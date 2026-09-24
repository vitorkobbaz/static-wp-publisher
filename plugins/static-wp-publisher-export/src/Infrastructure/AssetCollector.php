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
	 * @param list<string> $pages Original generated HTML documents.
	 * @return list<string>
	 */
	public function collect( array $pages, string $destination ): array {
		$queue    = new SplQueue();
		$warnings = array();
		foreach ( $pages as $html ) {
			foreach ( $this->htmlUrls( $html ) as $url ) {
				$queue->enqueue( $url );
			}
		}

		while ( ! $queue->isEmpty() ) {
			$url = (string) $queue->dequeue();
			try {
				$asset = $this->localAsset( $url );
				if ( null === $asset || isset( $this->seen[ $asset['relative'] ] ) ) {
					continue;
				}
				$this->seen[ $asset['relative'] ] = true;
				$target                           = trailingslashit( $destination ) . $asset['relative'];
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
	private function htmlUrls( string $html ): array {
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
			if ( ! $element instanceof DOMElement ) {
				continue;
			}
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
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/** @return list<string> */
	private function cssUrls( string $css, string $stylesheetUrl ): array {
		preg_match_all( '/url\(\s*[\'\"]?([^\)\'\"]+)[\'\"]?\s*\)/i', $css, $matches );
		$urls = array();
		foreach ( $matches[1] ?? array() as $url ) {
			$url = trim( (string) $url );
			if ( preg_match( '#^(data:|https?://|/)#i', $url ) ) {
				$urls[] = $url;
				continue;
			}
			$base   = rtrim( (string) preg_replace( '#/[^/]*$#', '/', $stylesheetUrl ), '/' );
			$urls[] = $base . '/' . $url;
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
		if ( isset( $parsed['host'] ) && strtolower( (string) $parsed['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return null;
		}
		$path      = rawurldecode( (string) ( $parsed['path'] ?? '' ) );
		$home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		if ( '' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path     = $this->normalizePath( $path );
		$relative = ltrim( wp_normalize_path( $path ), '/' );
		if ( '' === $relative || preg_match( '#(^|/)\.\.(/|$)#', $relative ) || preg_match( '/\.(php\d*|phtml|phar)$/i', $relative ) ) {
			return null;
		}

		$root   = rtrim( wp_normalize_path( ABSPATH ), '/' );
		$source = realpath( ABSPATH . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
		if ( false === $source || ! is_file( $source ) || ! str_starts_with( wp_normalize_path( $source ), $root . '/' ) ) {
			return null;
		}

		return array( 'source' => $source, 'relative' => $relative );
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
}
