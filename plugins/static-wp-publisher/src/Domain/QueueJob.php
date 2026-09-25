<?php
/**
 * Queue job value object.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class QueueJob {
	public function __construct(
		public int $id,
		public string $url,
		public string $reason,
		public int $attempts,
		public string $lockToken,
	) {}
}
