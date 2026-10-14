<?php
/**
 * Batch size and wall-clock budget for one worker pass.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class WorkerBudget {
	public const DEFAULT_LIMIT   = 50;
	public const MAX_LIMIT       = 500;
	public const DEFAULT_SECONDS = 20;
	public const MAX_SECONDS     = 300;

	/**
	 * @param int $limit   Maximum jobs in this pass.
	 * @param int $seconds Stop claiming new jobs after this many seconds; 0 = no time limit.
	 */
	public function __construct(
		public int $limit,
		public int $seconds,
	) {}

	/**
	 * Builds a budget for a web or cron request. The time budget never exceeds half of
	 * PHP's max_execution_time, leaving room for the render already in flight.
	 */
	public static function forRequest( int $limit, int $seconds, int $max_execution_time ): self {
		$limit   = max( 1, min( self::MAX_LIMIT, $limit ) );
		$seconds = max( 1, min( self::MAX_SECONDS, $seconds ) );
		if ( $max_execution_time > 0 ) {
			$seconds = max( 1, min( $seconds, intdiv( $max_execution_time, 2 ) ) );
		}
		return new self( $limit, $seconds );
	}

	/** Builds an unbounded-time budget for WP-CLI. */
	public static function forCli( int $limit ): self {
		return new self( max( 1, min( self::MAX_LIMIT * 10, $limit ) ), 0 );
	}
}
