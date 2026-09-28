<?php
/**
 * Static-serving query policy tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\QueryPolicy;

final class QueryPolicyTest extends TestCase {
	public function test_query_less_requests_are_cacheable(): void {
		self::assertTrue( QueryPolicy::isCacheable( '' ) );
		self::assertTrue( QueryPolicy::isCacheable( '&' ) );
	}

	public function test_tracking_parameters_are_ignored(): void {
		self::assertTrue( QueryPolicy::isCacheable( 'utm_source=newsletter&utm_campaign=launch' ) );
		self::assertTrue( QueryPolicy::isCacheable( 'fbclid=abc&GCLID=def' ) );
		self::assertTrue( QueryPolicy::isCacheable( 'utm_medium' ) );
	}

	public function test_content_changing_parameters_bypass_static_serving(): void {
		self::assertFalse( QueryPolicy::isCacheable( 's=teste' ) );
		self::assertFalse( QueryPolicy::isCacheable( 'p=123' ) );
		self::assertFalse( QueryPolicy::isCacheable( 'page_id=2&preview=true' ) );
		self::assertFalse( QueryPolicy::isCacheable( 'paged=2' ) );
	}

	public function test_any_unknown_parameter_bypasses_even_with_tracking(): void {
		self::assertFalse( QueryPolicy::isCacheable( 'utm_source=x&s=teste' ) );
		self::assertFalse( QueryPolicy::isCacheable( '=value' ) );
		self::assertFalse( QueryPolicy::isCacheable( '%73=teste' ) );
	}

	public function test_prefix_rules_do_not_match_unrelated_names(): void {
		self::assertFalse( QueryPolicy::isCacheable( 'utm=1' ) );
		self::assertFalse( QueryPolicy::isCacheable( 'gclid_extra=1' ) );
	}

	public function test_ignored_list_is_configurable(): void {
		self::assertTrue( QueryPolicy::isCacheable( 'ref=partner', array( 'ref' ) ) );
		self::assertFalse( QueryPolicy::isCacheable( 'utm_source=x', array( 'ref' ) ) );
	}
}
