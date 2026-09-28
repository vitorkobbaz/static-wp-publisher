<?php
/**
 * Resolves same-origin asset URLs to files inside wp-content or wp-includes.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

use SWPP\Core\Domain\AssetUrl;
use SWPP\Core\Domain\OptimizerAssets;
use Throwable;

/**
 * Only URLs under content_url() or includes_url() are considered, their path must stay
 * inside that directory after resolving symlinks, and only known file types are read.
 * Nothing here takes a path from a request.
 */
final class LocalAssetResolver implements OptimizerAssets {
	private const IMAGE_TYPES = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg' );
	private const MAX_CSS     = 2097152;

	public function __construct( private readonly Storage $storage ) {}

	public function stylesheet( string $url ): ?string {
		$path = $this->path( $url, array( 'css' ) );
		if ( null === $path || (int) filesize( $path ) > self::MAX_CSS ) {
			return null;
		}
		$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- validated local file.
		return false === $css ? null : $css;
	}

	public function imageSize( string $url ): ?array {
		$path = $this->path( $url, self::IMAGE_TYPES );
		if ( null === $path ) {
			return null;
		}
		if ( 'svg' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return $this->svgSize( $path );
		}
		$size = wp_getimagesize( $path );
		if ( ! is_array( $size ) || (int) $size[0] < 1 || (int) $size[1] < 1 ) {
			return null;
		}
		return array( (int) $size[0], (int) $size[1] );
	}

	public function isLocal( string $url ): bool {
		return null !== $this->path( $url, array_merge( self::IMAGE_TYPES, array( 'css' ) ) );
	}

	public function storeStylesheet( string $css ): ?string {
		try {
			return $this->storage->writeAsset( 'css', substr( hash( 'sha256', $css ), 0, 16 ) . '.css', $css );
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @param list<string> $types Allowed lowercase extensions. */
	private function path( string $url, array $types ): ?string {
		$roots = array(
			array( content_url( '/' ), WP_CONTENT_DIR ),
			array( includes_url( '/' ), ABSPATH . WPINC ),
		);
		foreach ( $roots as list( $prefix, $directory ) ) {
			$relative = AssetUrl::relative( $url, $prefix );
			if ( null === $relative ) {
				continue;
			}
			if ( ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), $types, true ) ) {
				return null;
			}
			$root = realpath( $directory );
			$file = realpath( $directory . '/' . $relative );
			if ( false === $root || false === $file || ! is_file( $file ) ) {
				return null;
			}
			$root = trailingslashit( wp_normalize_path( $root ) );
			return str_starts_with( wp_normalize_path( $file ), $root ) ? $file : null;
		}
		return null;
	}

	/** @return array{0:int,1:int}|null */
	private function svgSize( string $path ): ?array {
		$head = file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- validated local file.
		if ( false === $head || 1 !== preg_match( '~<svg\b[^>]*>~i', $head, $svg ) ) {
			return null;
		}
		$width  = preg_match( '~\bwidth\s*=\s*["\']?(\d+(?:\.\d+)?)(?:px)?["\'\s>]~i', $svg[0], $w ) ? (int) round( (float) $w[1] ) : 0;
		$height = preg_match( '~\bheight\s*=\s*["\']?(\d+(?:\.\d+)?)(?:px)?["\'\s>]~i', $svg[0], $h ) ? (int) round( (float) $h[1] ) : 0;
		if ( ( $width < 1 || $height < 1 ) && 1 === preg_match( '~viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)~i', $svg[0], $box ) ) {
			$width  = (int) round( (float) $box[1] );
			$height = (int) round( (float) $box[2] );
		}
		return $width > 0 && $height > 0 ? array( $width, $height ) : null;
	}
}
