<?php
/**
 * Publication eligibility tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\Eligibility;

final class EligibilityTest extends TestCase {
	private const HTML = array( 'content-type' => 'text/html; charset=UTF-8' );

	public function test_plain_public_html_is_eligible(): void {
		$result = Eligibility::check( 200, self::HTML, '<html><body>Hello</body></html>' );

		self::assertTrue( $result->success );
	}

	public function test_password_protected_pages_are_refused_without_retry(): void {
		$result = Eligibility::check( 200, self::HTML, '<form><input name="post_password" type="password"></form>' );

		self::assertFalse( $result->success );
		self::assertFalse( $result->retryable );
		self::assertSame( 'Page is password protected.', $result->message );
	}

	public function test_deterministic_refusals_are_not_retryable(): void {
		self::assertFalse( Eligibility::check( 200, array( 'content-type' => 'application/json' ), '{}' )->retryable );
		self::assertFalse( Eligibility::check( 200, self::HTML + array( 'set-cookie' => 'a=b' ), '<p>x</p>' )->retryable );
		self::assertFalse( Eligibility::check( 200, self::HTML, '<div id="wpadminbar"></div>' )->retryable );
		self::assertFalse( Eligibility::check( 302, self::HTML, '' )->retryable );
		self::assertFalse( Eligibility::check( 403, self::HTML, '' )->retryable );
	}

	public function test_transient_failures_stay_retryable(): void {
		foreach ( array( 0, 408, 429, 500, 502, 503 ) as $status ) {
			$result = Eligibility::check( $status, self::HTML, '' );
			self::assertFalse( $result->success, (string) $status );
			self::assertTrue( $result->retryable, (string) $status );
		}
		self::assertTrue( Eligibility::check( 200, self::HTML, '   ' )->retryable );
	}

	public function test_permanent_redirects_remain_eligible(): void {
		self::assertTrue( Eligibility::check( 301, self::HTML, '' )->success );
		self::assertTrue( Eligibility::check( 308, self::HTML, '' )->success );
	}
}
