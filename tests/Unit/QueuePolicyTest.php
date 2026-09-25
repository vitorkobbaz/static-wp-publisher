<?php
/**
 * Queue policy tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\QueuePolicy;

final class QueuePolicyTest extends TestCase {
	public function test_retries_become_terminal_at_configured_limit(): void {
		$policy = new QueuePolicy( 3, 900 );

		self::assertFalse( $policy->isTerminalAttempt( 2 ) );
		self::assertTrue( $policy->isTerminalAttempt( 3 ) );
	}

	public function test_exponential_backoff_is_capped_at_one_hour(): void {
		$policy = new QueuePolicy();

		self::assertSame( 120, $policy->retryDelaySeconds( 1 ) );
		self::assertSame( 240, $policy->retryDelaySeconds( 2 ) );
		self::assertSame( 3600, $policy->retryDelaySeconds( 20 ) );
	}

	public function test_rejects_unsafe_policy_values(): void {
		$this->expectException( InvalidArgumentException::class );
		new QueuePolicy( 0, 59 );
	}
}
