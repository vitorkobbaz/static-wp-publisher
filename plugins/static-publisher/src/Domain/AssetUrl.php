<?php
/**
 * Pure URL helpers for local assets referenced by static copies.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final class AssetUrl {
	/**
	 * Path of $url below $prefix (both absolute URLs on the same host, any scheme), with
	 * query and fragment removed. Null when outside the prefix or when the path could
	 * escape it (traversal, backslashes, NUL bytes, encoded separators).
	 */
	public static function relative( string $url, string $prefix ): ?string {
		$target = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure domain code, no WordPress available.
		$base   = parse_url( $prefix ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $target ) || ! is_array( $base ) || empty( $target['host'] ) || empty( $base['host'] ) ) {
			return null;
		}
		if ( strtolower( $target['host'] ) !== strtolower( $base['host'] ) || ( $target['port'] ?? null ) !== ( $base['port'] ?? null ) ) {
			return null;
		}
		$base_path = rtrim( $base['path'] ?? '', '/' ) . '/';
		$path      = $target['path'] ?? '';
		if ( ! str_starts_with( $path, $base_path ) ) {
			return null;
		}
		$relative = rawurldecode( substr( $path, strlen( $base_path ) ) );
		if ( '' === $relative || str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) ) {
			return null;
		}
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment || '.' === $segment || '' === $segment ) {
				return null;
			}
		}
		return $relative;
	}

	/** Resolves a CSS url() reference against the stylesheet's absolute URL. */
	public static function resolve( string $base, string $reference ): string {
		$reference = trim( $reference );
		if ( '' === $reference || str_starts_with( $reference, '#' ) || str_starts_with( $reference, '//' ) || 1 === preg_match( '~^[a-z][a-z0-9+.-]*:~i', $reference ) ) {
			return $reference;
		}
		$parts  = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $reference, '/' ) ) {
			return $origin . $reference;
		}

		$directory = explode( '/', (string) preg_replace( '~/[^/]*$~', '', $parts['path'] ?? '' ) );
		$suffix    = '';
		if ( 1 === preg_match( '~^([^?#]*)([?#].*)$~', $reference, $split ) ) {
			$reference = $split[1];
			$suffix    = $split[2];
		}
		foreach ( explode( '/', $reference ) as $segment ) {
			if ( '..' === $segment ) {
				if ( count( $directory ) > 1 ) {
					array_pop( $directory );
				}
			} elseif ( '.' !== $segment && '' !== $segment ) {
				$directory[] = $segment;
			}
		}
		return $origin . implode( '/', $directory ) . $suffix;
	}
}
