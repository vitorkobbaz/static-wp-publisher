<?php
/**
 * Outcome of one worker pass.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class WorkerReport {
	public function __construct(
		public int $processed,
		public int $succeeded,
		public int $failed,
		public ?string $lastError = null,
		public int $skipped = 0,
	) {}
}
