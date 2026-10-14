<?php
/**
 * Compares static and WordPress response times honestly.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class SpeedComparison {
	public const FASTER  = 'faster';
	public const SIMILAR = 'similar';
	public const UNKNOWN = 'unknown';

	private function __construct(
		public string $verdict,
		public ?float $factor,
	) {}

	/**
	 * A speed-up is only claimed when WordPress takes at least 1.3x as long; the factor is
	 * rounded to one decimal below 10x and to a whole number above.
	 */
	public static function of( ?int $static_ms, ?int $dynamic_ms ): self {
		if ( null === $static_ms || null === $dynamic_ms || $dynamic_ms <= 0 ) {
			return new self( self::UNKNOWN, null );
		}
		$factor = $dynamic_ms / max( 1, $static_ms );
		if ( $factor < 1.3 ) {
			return new self( self::SIMILAR, null );
		}
		return new self( self::FASTER, $factor >= 10 ? round( $factor ) : round( $factor, 1 ) );
	}
}
