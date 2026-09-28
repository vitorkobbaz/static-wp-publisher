<?php
/**
 * Content scope tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\ContentScope;

final class ContentScopeTest extends TestCase {
	public function test_builder_templates_are_not_pages(): void {
		$types = ContentScope::pageTypes( array( 'post', 'page', 'attachment', 'elementor_library', 'e-floating-buttons', 'e-landing-page', 'product' ) );

		self::assertSame( array( 'post', 'page', 'e-landing-page', 'product' ), $types );
	}

	public function test_exclusions_are_configurable_and_case_insensitive(): void {
		self::assertSame( array( 'page' ), ContentScope::pageTypes( array( 'page', 'Portfolio' ), array( 'portfolio' ) ) );
	}

	public function test_only_query_less_addresses_can_be_static(): void {
		self::assertTrue( ContentScope::isStaticAddress( 'https://example.com/about/' ) );
		self::assertFalse( ContentScope::isStaticAddress( 'https://example.com/?elementor_library=header' ) );
		self::assertFalse( ContentScope::isStaticAddress( 'https://example.com/page/#top' ) );
	}
}
