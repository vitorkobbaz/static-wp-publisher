<?php
/**
 * Speed comparison tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\SpeedComparison;

final class SpeedComparisonTest extends TestCase {
	public function test_reports_speed_up_factor(): void {
		$comparison = SpeedComparison::of( 40, 520 );

		self::assertSame( SpeedComparison::FASTER, $comparison->verdict );
		self::assertSame( 13.0, $comparison->factor );
		self::assertSame( 2.5, SpeedComparison::of( 200, 500 )->factor );
	}

	public function test_does_not_claim_small_differences(): void {
		self::assertSame( SpeedComparison::SIMILAR, SpeedComparison::of( 430, 520 )->verdict );
		self::assertNull( SpeedComparison::of( 430, 520 )->factor );
	}

	public function test_missing_measurements_are_unknown(): void {
		self::assertSame( SpeedComparison::UNKNOWN, SpeedComparison::of( null, 520 )->verdict );
		self::assertSame( SpeedComparison::UNKNOWN, SpeedComparison::of( 40, null )->verdict );
	}

	public function test_zero_millisecond_static_response_does_not_divide_by_zero(): void {
		self::assertSame( 520.0, SpeedComparison::of( 0, 520 )->factor );
	}
}
