<?php
/**
 * Maps public URLs to safe static paths.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

use InvalidArgumentException;

final class UrlPath {
	public static function relative( string $url, string $home ): string {
		$url_parts  = parse_url( $url );
		$home_parts = parse_url( $home );
		if ( false === $url_parts || false === $home_parts ) {
			throw new InvalidArgumentException( 'Invalid URL.' );
		}

		$host      = strtolower( (string) ( $url_parts['host'] ?? '' ) );
		$home_host = strtolower( (string) ( $home_parts['host'] ?? '' ) );
		if ( '' === $host || $host !== $home_host ) {
			throw new InvalidArgumentException( 'URL is outside the WordPress origin.' );
		}

		$path      = rawurldecode( (string) ( $url_parts['path'] ?? '/' ) );
		$home_path = rtrim( (string) ( $home_parts['path'] ?? '' ), '/' );
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$segments = array();
		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment || str_contains( $segment, "\0" ) ) {
				throw new InvalidArgumentException( 'Unsafe URL path.' );
			}
			$segments[] = sanitize_file_name( $segment );
		}

		if ( array() === $segments ) {
			return 'index.html';
		}

		return implode( '/', $segments ) . '/index.html';
	}
}
