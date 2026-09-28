<?php
/**
 * Worker budget tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\WorkerBudget;

final class WorkerBudgetTest extends TestCase {
	public function test_request_budget_uses_requested_values_without_php_limit(): void {
		$budget = WorkerBudget::forRequest( 50, 20, 0 );

		self::assertSame( 50, $budget->limit );
		self::assertSame( 20, $budget->seconds );
	}

	public function test_request_budget_leaves_half_of_max_execution_time(): void {
		self::assertSame( 15, WorkerBudget::forRequest( 50, 20, 30 )->seconds );
		self::assertSame( 20, WorkerBudget::forRequest( 50, 20, 300 )->seconds );
		self::assertSame( 1, WorkerBudget::forRequest( 50, 20, 1 )->seconds );
	}

	public function test_request_budget_is_clamped(): void {
		$low  = WorkerBudget::forRequest( 0, 0, 0 );
		$high = WorkerBudget::forRequest( 100000, 100000, 0 );

		self::assertSame( 1, $low->limit );
		self::assertSame( 1, $low->seconds );
		self::assertSame( WorkerBudget::MAX_LIMIT, $high->limit );
		self::assertSame( WorkerBudget::MAX_SECONDS, $high->seconds );
	}

	public function test_cli_budget_has_no_time_limit(): void {
		$budget = WorkerBudget::forCli( 100 );

		self::assertSame( 100, $budget->limit );
		self::assertSame( 0, $budget->seconds );
		self::assertSame( 1, WorkerBudget::forCli( -5 )->limit );
	}
}
