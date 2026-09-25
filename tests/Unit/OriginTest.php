<?php
/**
 * Origin tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\Origin;

final class OriginTest extends TestCase {
	public function test_accepts_equivalent_default_ports(): void {
		self::assertTrue( Origin::matches( 'https://example.com/page/', 'https://example.com:443/' ) );
	}

	public function test_rejects_scheme_port_and_credentials_changes(): void {
		self::assertFalse( Origin::matches( 'http://example.com/page/', 'https://example.com/' ) );
		self::assertFalse( Origin::matches( 'https://example.com:8443/page/', 'https://example.com/' ) );
		self::assertFalse( Origin::matches( 'https://user@example.com/page/', 'https://example.com/' ) );
	}
}
