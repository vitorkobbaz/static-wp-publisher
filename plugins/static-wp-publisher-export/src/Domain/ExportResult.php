<?php
/**
 * Export result value object.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Domain;

final readonly class ExportResult {
	/** @param list<string> $warnings */
	public function __construct(
		public bool $success,
		public string $message,
		public ?string $directory = null,
		public ?string $zip = null,
		public array $warnings = array(),
	) {}
}
