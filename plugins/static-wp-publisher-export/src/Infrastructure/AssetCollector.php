<?php
/**
 * Copies same-origin static assets referenced by generated pages.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Infrastructure;

use DOMDocument;
use DOMElement;
use RuntimeException;
use SplQueue;

final class AssetCollector {
	/** @var array<string,true> */
	private array $seen = array();

	/**
	 * @param list<array{html:string,relative:string}> $pages Original generated HTML documents and exported paths.
	 * @return list<string>
	 */
	public function collect( array $pages, string $destination ): array {
		$this->seen = array();
		$queue      = new SplQueue();
		$warnings   = array();
		foreach ( $pages as $page ) {
			$page_url = $this->pageUrl( $page['relative'] );
			foreach ( $this->htmlUrls( $page['html'], $page_url ) as $url ) {
				$queue->enqueue( $this->resolveUrl( $url, $page_url ) );
			}
		}

		while ( ! $queue->isEmpty() ) {
			$url = (string) $queue->dequeue();
			try {
				$asset = $this->localAsset( $url );
				if ( null === $asset ) {
					continue;
				}
				$key = strtolower( $asset['relative'] );
				if ( isset( $this->seen[ $key ] ) ) {
					continue;
				}
				$this->seen[ $key ] = true;
				$target             = trailingslashit( $destination ) . $asset['relative'];
				if ( ! wp_mkdir_p( dirname( $target ) ) || ! copy( $asset['source'], $target ) ) {
					throw new RuntimeException( 'Unable to copy asset.' );
				}

				if ( 'css' === strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ) ) {
					$css = (string) file_get_contents( $target );
					foreach ( $this->cssUrls( $css, $url ) as $dependency ) {
						$queue->enqueue( $dependency );
					}
				}
			} catch ( RuntimeException $error ) {
				$warnings[] = 'Asset ' . $url . ': ' . $error->getMessage();
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/** @return list<string> */
	private function htmlUrls( string $html, string $pageUrl ): array {
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return array();
		}

		$urls = array();
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			foreach ( array( 'src', 'href', 'poster', 'data' ) as $attribute ) {
				if ( $element->hasAttribute( $attribute ) ) {
					$urls[] = $element->getAttribute( $attribute );
				}
			}
			foreach ( array( 'srcset', 'imagesrcset', 'data-srcset' ) as $attribute ) {
				if ( ! $element->hasAttribute( $attribute ) ) {
					continue;
				}
				foreach ( explode( ',', $element->getAttribute( $attribute ) ) as $candidate ) {
					$parts  = preg_split( '/\s+/', trim( $candidate ), 2 );
					$urls[] = (string) ( $parts[0] ?? '' );
				}
			}
			if ( $element->hasAttribute( 'style' ) ) {
				$urls = array_merge( $urls, $this->cssUrls( $element->getAttribute( 'style' ), $pageUrl ) );
			}
			if ( 'style' === strtolower( $element->tagName ) ) {
				$urls = array_merge( $urls, $this->cssUrls( $element->textContent, $pageUrl ) );
			}
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/** @return list<string> */
	private function cssUrls( string $css, string $stylesheetUrl ): array {
		preg_match_all( '/url\(\s*[\'\"]?([^\)\'\"]+)[\'\"]?\s*\)/i', $css, $matches );
		preg_match_all( '/@import\s+(?:url\(\s*)?[\'\"]([^\'\"]+)[\'\"]\s*\)?/i', $css, $imports );
		$urls = array();
		foreach ( array_merge( $matches[1], $imports[1] ) as $url ) {
			$url    = trim( (string) $url );
			$urls[] = $this->resolveUrl( $url, $stylesheetUrl );
		}
		return $urls;
	}

	/** @return array{source:string,relative:string}|null */
	private function localAsset( string $url ): ?array {
		$url = trim( html_entity_decode( $url ) );
		if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^(data|mailto|tel|javascript):#i', $url ) ) {
			return null;
		}

		$home   = wp_parse_url( home_url( '/' ) );
		$parsed = wp_parse_url( $url );
		if ( false === $parsed ) {
			return null;
		}
		if ( isset( $parsed['host'] ) && ! $this->sameOrigin( $parsed, is_array( $home ) ? $home : array() ) ) {
			return null;
		}
		$encoded_path = (string) ( $parsed['path'] ?? '' );
		if ( preg_match( '#(?:^|/)(?:%2e)(?:%2e)?(?:/|$)#i', $encoded_path ) ) {
			throw new RuntimeException( 'Encoded dot segments are not permitted.' );
		}
		$path      = rawurldecode( $encoded_path );
		$home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		if ( '' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', str_replace( '\\', '/', $path ) ) ) {
			throw new RuntimeException( 'Parent path segments are not permitted.' );
		}
		$path     = $this->normalizePath( $path );
		$relative = ltrim( wp_normalize_path( $path ), '/' );
		if ( '' === $relative || ! $this->isAllowedAssetPath( $relative ) ) {
			return null;
		}

		$root   = rtrim( wp_normalize_path( ABSPATH ), '/' );
		$source = realpath( ABSPATH . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
		if ( false === $source || ! is_file( $source ) ) {
			throw new RuntimeException( 'Referenced same-origin asset was not found.' );
		}
		if ( ! str_starts_with( wp_normalize_path( $source ), $root . '/' ) ) {
			throw new RuntimeException( 'Referenced asset resolves outside the WordPress root.' );
		}

		return array(
			'source'   => $source,
			'relative' => $relative,
		);
	}

	private function normalizePath( string $path ): string {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		return '/' . implode( '/', $segments );
	}

	/**
	 * @param array<string,mixed> $left Parsed candidate URL.
	 * @param array<string,mixed> $right Parsed home URL.
	 */
	private function sameOrigin( array $left, array $right ): bool {
		$left_scheme  = strtolower( (string) ( $left['scheme'] ?? $right['scheme'] ?? '' ) );
		$right_scheme = strtolower( (string) ( $right['scheme'] ?? '' ) );
		$left_port    = (int) ( $left['port'] ?? ( 'https' === $left_scheme ? 443 : 80 ) );
		$right_port   = (int) ( $right['port'] ?? ( 'https' === $right_scheme ? 443 : 80 ) );
		return strtolower( (string) ( $left['host'] ?? '' ) ) === strtolower( (string) ( $right['host'] ?? '' ) )
			&& $left_scheme === $right_scheme
			&& $left_port === $right_port
			&& ! isset( $left['user'] )
			&& ! isset( $left['pass'] );
	}

	private function isAllowedAssetPath( string $relative ): bool {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		$allowed   = array( 'avif', 'bmp', 'css', 'eot', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'json', 'm4a', 'map', 'mp3', 'mp4', 'ogg', 'otf', 'pdf', 'png', 'svg', 'ttf', 'txt', 'wav', 'webm', 'webp', 'woff', 'woff2', 'xml' );
		return in_array( $extension, $allowed, true );
	}

	private function pageUrl( string $relative ): string {
		$relative = str_replace( '\\', '/', $relative );
		$route    = preg_replace( '#(?:^|/)index\.html$#i', '/', $relative ) ?? $relative;
		return rtrim( home_url( '/' ), '/' ) . '/' . ltrim( $route, '/' );
	}

	private function resolveUrl( string $url, string $base ): string {
		$url = trim( html_entity_decode( $url ) );
		if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return $url;
		}
		if ( str_starts_with( $url, '//' ) ) {
			$parsed_scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			$scheme        = is_string( $parsed_scheme ) && '' !== $parsed_scheme ? $parsed_scheme : 'https';
			return $scheme . ':' . $url;
		}
		$origin = wp_parse_url( $base );
		if ( ! is_array( $origin ) || empty( $origin['host'] ) ) {
			return $url;
		}
		$authority = (string) ( $origin['scheme'] ?? 'https' ) . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' );
		if ( str_starts_with( $url, '/' ) ) {
			return $authority . $url;
		}
		$path      = (string) ( $origin['path'] ?? '/' );
		$base_path = str_ends_with( $path, '/' ) ? $path : (string) preg_replace( '#/[^/]*$#', '/', $path );
		return $authority . $this->normalizePath( $base_path . $url );
	}
}
