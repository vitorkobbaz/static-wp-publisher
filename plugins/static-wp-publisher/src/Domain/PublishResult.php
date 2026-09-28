<?php
/**
 * Immutable publication result.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class PublishResult {
	/**
	 * @param bool $retryable False when the refusal is deterministic (protected, personalized,
	 *                        non-HTML, redirecting...) and retrying would give the same answer.
	 */
	public function __construct(
		public bool $success,
		public string $message,
		public ?string $relativePath = null,
		public ?string $contentHash = null,
		public bool $retryable = true,
	) {}

	public static function refused( string $message ): self {
		return new self( false, $message, null, null, false );
	}
}
