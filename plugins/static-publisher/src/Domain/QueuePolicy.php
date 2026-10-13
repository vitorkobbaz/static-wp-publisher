<?php
/**
 * Retry and lease policy for publication jobs.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class QueuePolicy {
	public function __construct(
		public int $maxAttempts = 3,
		public int $leaseSeconds = 900,
	) {
		if ( $this->maxAttempts < 1 || $this->leaseSeconds < 60 ) {
			throw new \InvalidArgumentException( 'Invalid queue policy.' );
		}
	}

	public function isTerminalAttempt( int $attempts ): bool {
		return $attempts >= $this->maxAttempts;
	}

	public function retryDelaySeconds( int $attempts ): int {
		return min( 3600, ( 2 ** max( 1, $attempts ) ) * 60 );
	}
}
