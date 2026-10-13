<?php
/**
 * Resource limits for one export run.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Export\Domain;

/**
 * Published content decides how many pages and assets an export touches, so every run
 * is bounded. Exceeding a limit stops the export with a clear message; one oversized
 * asset is only skipped.
 */
final class ExportBudget {
	public const DEFAULTS = array(
		'max_pages'       => 20000,
		'max_files'       => 50000,
		'max_references'  => 200000,
		'max_file_bytes'  => 104857600,
		'max_total_bytes' => 2147483648,
		'max_seconds'     => 600,
	);

	private int $files      = 0;
	private int $pages      = 0;
	private int $references = 0;
	private int $bytes      = 0;
	private float $started;

	/** @var array<string,int> */
	private array $limits;

	/** @param array<string,int> $limits Overrides for DEFAULTS. */
	public function __construct( array $limits = array(), ?float $started = null ) {
		$this->limits = array();
		foreach ( self::DEFAULTS as $name => $default ) {
			$this->limits[ $name ] = max( 1, (int) ( $limits[ $name ] ?? $default ) );
		}
		$this->started = $started ?? microtime( true );
	}

	public function maxFileBytes(): int {
		return $this->limits['max_file_bytes'];
	}

	public function addPage(): void {
		if ( ++$this->pages > $this->limits['max_pages'] ) {
			throw new ExportLimitExceeded( sprintf( 'The export has more than %d pages.', $this->limits['max_pages'] ) );
		}
	}

	public function addReference(): void {
		if ( ++$this->references > $this->limits['max_references'] ) {
			throw new ExportLimitExceeded( sprintf( 'The pages reference more than %d assets.', $this->limits['max_references'] ) );
		}
	}

	public function addFile( int $bytes ): void {
		$this->checkTime();
		if ( ++$this->files > $this->limits['max_files'] ) {
			throw new ExportLimitExceeded( sprintf( 'The export has more than %d files.', $this->limits['max_files'] ) );
		}
		$this->bytes += max( 0, $bytes );
		if ( $this->bytes > $this->limits['max_total_bytes'] ) {
			throw new ExportLimitExceeded( sprintf( 'The export is larger than %d MB.', intdiv( $this->limits['max_total_bytes'], 1048576 ) ) );
		}
	}

	public function checkTime(): void {
		if ( microtime( true ) - $this->started > $this->limits['max_seconds'] ) {
			throw new ExportLimitExceeded( sprintf( 'The export took longer than %d seconds.', $this->limits['max_seconds'] ) );
		}
	}
}
