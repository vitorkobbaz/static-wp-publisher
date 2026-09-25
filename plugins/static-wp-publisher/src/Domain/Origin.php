<?php
/**
 * Strict same-origin URL comparison.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final class Origin {
	public static function matches( string $candidate, string $home ): bool {
		$target = parse_url( $candidate );
		$origin = parse_url( $home );
		if ( false === $target || false === $origin || isset( $target['user'] ) || isset( $target['pass'] ) ) {
			return false;
		}

		$target_scheme = strtolower( (string) ( $target['scheme'] ?? '' ) );
		$origin_scheme = strtolower( (string) ( $origin['scheme'] ?? '' ) );
		$target_host   = strtolower( (string) ( $target['host'] ?? '' ) );
		$origin_host   = strtolower( (string) ( $origin['host'] ?? '' ) );

		return '' !== $target_host
			&& $target_scheme === $origin_scheme
			&& $target_host === $origin_host
			&& self::port( $target, $target_scheme ) === self::port( $origin, $origin_scheme );
	}

	/** @param array<string,int|string> $parts */
	private static function port( array $parts, string $scheme ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}
		return 'https' === $scheme ? 443 : 80;
	}
}
