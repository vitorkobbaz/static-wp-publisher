<?php
/**
 * Decides whether a request query string may receive a static artifact.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final class QueryPolicy {
	/**
	 * Marketing/analytics parameters that never change the rendered page. Entries ending
	 * in "*" match by prefix.
	 */
	public const TRACKING_PARAMETERS = array(
		'utm_*',
		'gclid',
		'gbraid',
		'wbraid',
		'dclid',
		'fbclid',
		'msclkid',
		'twclid',
		'ttclid',
		'li_fat_id',
		'igshid',
		'mc_cid',
		'mc_eid',
		'_gl',
	);

	/**
	 * Static artifacts are generated for query-less URLs only, so any parameter that
	 * could change the response (search, pagination, previews, ?p=ID) must reach WordPress.
	 *
	 * @param list<string> $ignored Parameters that may be ignored; "*" suffix = prefix match.
	 */
	public static function isCacheable( string $query, array $ignored = self::TRACKING_PARAMETERS ): bool {
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) );
			if ( '' === $name || ! self::isIgnored( $name, $ignored ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param list<string> $ignored */
	private static function isIgnored( string $name, array $ignored ): bool {
		foreach ( $ignored as $candidate ) {
			$candidate = strtolower( $candidate );
			if ( str_ends_with( $candidate, '*' ) ) {
				if ( str_starts_with( $name, substr( $candidate, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $name === $candidate ) {
				return true;
			}
		}
		return false;
	}
}
