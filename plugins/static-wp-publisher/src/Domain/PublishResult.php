<?php
/**
 * Immutable publication result.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class PublishResult {
	public function __construct(
		public bool $success,
		public string $message,
		public ?string $relativePath = null,
		public ?string $contentHash = null,
	) {}
}
