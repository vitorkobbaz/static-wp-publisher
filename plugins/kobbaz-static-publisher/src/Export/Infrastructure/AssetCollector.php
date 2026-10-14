<?php
/**
 * Copies same-origin static assets referenced by generated pages.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Export\Infrastructure;

use DOMDocument;
use DOMElement;
use RuntimeException;
use SplQueue;
use SWPP\Core\Infrastructure\Storage;
use SWPP\Core\Export\Domain\ExportBudget;
use SWPP\Core\Export\Domain\ExportLimitExceeded;
use Throwable;

final class AssetCollector {
	/** @var array<string,true> */
	private array $seen = array();

	/**
	 * @param list<array{path:string,relative:string}> $pages Original generated HTML files and exported paths.
	 * @return list<string>
	 */
	public function collect( array $pages, string $destination, ?ExportBudget $budget = null ): array {
		$budget     = $budget ?? new ExportBudget();
		$this->seen = array();
		$queue      = new SplQueue();
		$warnings   = array();
		foreach ( $pages as $page ) {
			$html = file_get_contents( $page['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- generated page.
			if ( false === $html ) {
				$warnings[] = 'Page ' . $page['relative'] . ': unable to read it for asset discovery.';
				continue;
			}
			$page_url = $this->pageUrl( $page['relative'] );
			foreach ( $this->htmlUrls( $html, $page_url ) as list( $url, $link_only ) ) {
				$budget->addReference();
				$queue->enqueue( array( $this->resolveUrl( $url, $page_url ), $link_only ) );
			}
		}

		while ( ! $queue->isEmpty() ) {
			list( $url, $link_only ) = $queue->dequeue();
			$url                     = (string) $url;
			try {
				$asset = $this->localAsset( $url, (bool) $link_only );
				if ( null === $asset ) {
					continue;
				}
				$key = strtolower( $asset['relative'] );
				if ( isset( $this->seen[ $key ] ) ) {
					continue;
				}
				$this->seen[ $key ] = true;
				$bytes              = (int) filesize( $asset['source'] );
				if ( $bytes > $budget->maxFileBytes() ) {
					throw new RuntimeException( 'Asset is larger than the per-file export limit.' );
				}
				$budget->addFile( $bytes );
				$target = trailingslashit( $destination ) . $asset['relative'];
				if ( ! wp_mkdir_p( dirname( $target ) ) || ! copy( $asset['source'], $target ) ) {
					throw new RuntimeException( 'Unable to copy asset.' );
				}

				if ( 'css' === strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ) ) {
					$css = (string) file_get_contents( $target );
					foreach ( $this->cssUrls( $css, $url ) as $dependency ) {
						$budget->addReference();
						$queue->enqueue( array( $dependency, false ) );
					}
				}
			} catch ( ExportLimitExceeded $error ) {
				throw $error;
			} catch ( Throwable $error ) {
				// One bad reference only loses that asset, never the whole export.
				$warnings[] = 'Asset ' . $this->printable( $url ) . ': ' . ( $error instanceof RuntimeException ? $error->getMessage() : 'unreadable reference.' );
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * URLs found in a document, each flagged true when it is only a link to a document
	 * (a or area href) rather than a resource the page loads.
	 *
	 * @return list<array{0:string,1:bool}>
	 */
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
			$is_link = in_array( strtolower( $element->tagName ), array( 'a', 'area' ), true );
			foreach ( array( 'src', 'href', 'poster', 'data' ) as $attribute ) {
				if ( $element->hasAttribute( $attribute ) ) {
					$urls[] = array( $element->getAttribute( $attribute ), $is_link && 'href' === $attribute );
				}
			}
			foreach ( array( 'srcset', 'imagesrcset', 'data-srcset' ) as $attribute ) {
				if ( ! $element->hasAttribute( $attribute ) ) {
					continue;
				}
				foreach ( explode( ',', $element->getAttribute( $attribute ) ) as $candidate ) {
					$parts  = preg_split( '/\s+/', trim( $candidate ), 2 );
					$urls[] = array( (string) ( $parts[0] ?? '' ), false );
				}
			}
			if ( $element->hasAttribute( 'style' ) ) {
				foreach ( $this->cssUrls( $element->getAttribute( 'style' ), $pageUrl ) as $url ) {
					$urls[] = array( $url, false );
				}
			}
			if ( 'style' === strtolower( $element->tagName ) ) {
				foreach ( $this->cssUrls( $element->textContent, $pageUrl ) as $url ) {
					$urls[] = array( $url, false );
				}
			}
		}

		$unique = array();
		foreach ( $urls as $entry ) {
			if ( '' !== $entry[0] ) {
				$unique[ $entry[0] . ( $entry[1] ? '|link' : '|asset' ) ] = $entry;
			}
		}
		return array_values( $unique );
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

	/**
	 * Maps a same-origin URL to a file that is public by design: theme, plugin and
	 * WordPress core assets, or media library uploads. Document links (a/area href) are
	 * only followed into the media library. Being inside the WordPress root is not enough.
	 *
	 * @return array{source:string,relative:string}|null
	 */
	private function localAsset( string $url, bool $linkOnly = false ): ?array {
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
		$path = rawurldecode( $encoded_path );
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			throw new RuntimeException( 'Control characters are not permitted in asset paths.' );
		}
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

		$source = realpath( ABSPATH . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
		if ( false === $source || ! is_file( $source ) ) {
			if ( $linkOnly ) {
				return null;
			}
			throw new RuntimeException( 'Referenced same-origin asset was not found.' );
		}
		if ( ! $this->isPublicSource( $source, $linkOnly ) ) {
			if ( $linkOnly ) {
				return null;
			}
			throw new RuntimeException( 'Referenced file is not in a public asset directory.' );
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
		// Data formats (json, xml, txt, map) are left out: they are rarely page assets and
		// often hold configuration, logs or exports that were never meant to be published.
		$allowed = array( 'avif', 'bmp', 'css', 'eot', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'm4a', 'mp3', 'mp4', 'ogg', 'otf', 'pdf', 'png', 'svg', 'ttf', 'wav', 'webm', 'webp', 'woff', 'woff2' );
		$allowed = array_map( 'strval', (array) apply_filters( 'swpp_export_asset_extensions', $allowed ) );
		return in_array( $extension, $allowed, true );
	}

	/**
	 * The resolved file (after symlinks) must sit inside an approved public directory, and
	 * never inside Static Publisher's own storage other than its generated assets.
	 */
	private function isPublicSource( string $source, bool $linkOnly ): bool {
		$uploads = wp_upload_dir();
		$media   = empty( $uploads['error'] ) ? (string) $uploads['basedir'] : '';
		$roots   = $linkOnly ? array( $media ) : array( $media, WP_CONTENT_DIR, ABSPATH . WPINC );
		$source  = $this->comparable( $source );
		if ( ! $this->isInside( $source, $roots ) ) {
			return false;
		}
		$storage = ( new Storage() )->root();
		if ( $this->isInside( $source, array( dirname( $storage ) ) ) ) {
			return ! $linkOnly && $this->isInside( $source, array( $storage . '/assets' ) );
		}
		return true;
	}

	/** @param list<string> $roots Directories; missing ones are ignored. */
	private function isInside( string $comparable, array $roots ): bool {
		foreach ( $roots as $root ) {
			$real = '' === $root ? false : realpath( $root );
			if ( false !== $real && str_starts_with( $comparable, $this->comparable( $real ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private function comparable( string $path ): string {
		$path = rtrim( wp_normalize_path( $path ), '/' );
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}

	private function printable( string $url ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '?', $url );
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
