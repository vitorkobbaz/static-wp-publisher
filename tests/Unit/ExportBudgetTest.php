<?php
declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Export\Domain\ExportBudget;
use SWPP\Core\Export\Domain\ExportLimitExceeded;

final class ExportBudgetTest extends TestCase {
	public function testStaysQuietWithinLimits(): void {
		$budget = new ExportBudget(
			array(
				'max_pages'       => 2,
				'max_files'       => 2,
				'max_total_bytes' => 10,
			)
		);
		$budget->addPage();
		$budget->addPage();
		$budget->addFile( 5 );
		$budget->addFile( 5 );
		$this->addToAssertionCount( 1 );
	}

	public function testStopsAfterTooManyPages(): void {
		$budget = new ExportBudget( array( 'max_pages' => 1 ) );
		$budget->addPage();
		$this->expectException( ExportLimitExceeded::class );
		$budget->addPage();
	}

	public function testStopsAfterTooManyFiles(): void {
		$budget = new ExportBudget( array( 'max_files' => 1 ) );
		$budget->addFile( 1 );
		$this->expectException( ExportLimitExceeded::class );
		$budget->addFile( 1 );
	}

	public function testStopsWhenTotalBytesAreExceeded(): void {
		$budget = new ExportBudget( array( 'max_total_bytes' => 10 ) );
		$budget->addFile( 6 );
		$this->expectException( ExportLimitExceeded::class );
		$budget->addFile( 6 );
	}

	public function testStopsAfterTooManyReferences(): void {
		$budget = new ExportBudget( array( 'max_references' => 1 ) );
		$budget->addReference();
		$this->expectException( ExportLimitExceeded::class );
		$budget->addReference();
	}

	public function testStopsWhenTimeRunsOut(): void {
		$budget = new ExportBudget( array( 'max_seconds' => 1 ), microtime( true ) - 5 );
		$this->expectException( ExportLimitExceeded::class );
		$budget->checkTime();
	}

	public function testInvalidLimitsFallBackToAtLeastOne(): void {
		$budget = new ExportBudget( array( 'max_file_bytes' => 0 ) );
		self::assertSame( 1, $budget->maxFileBytes() );
		self::assertSame( ExportBudget::DEFAULTS['max_file_bytes'], ( new ExportBudget() )->maxFileBytes() );
	}
}
