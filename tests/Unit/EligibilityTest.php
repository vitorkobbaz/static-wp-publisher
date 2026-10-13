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

	public function test_private_or_visitor_specific_responses_are_refused_without_retry(): void {
		$cases = array(
			array( 'cache-control' => 'private, max-age=0' ),
			array( 'cache-control' => 'no-cache, must-revalidate, max-age=0, no-store, private' ),
			array( 'cache-control' => array( 'max-age=60', 'no-store' ) ),
			array( 'pragma' => 'no-cache' ),
			array( 'vary' => 'Accept-Encoding, Cookie' ),
			array( 'vary' => 'authorization' ),
			array( 'vary' => '*' ),
			array( 'www-authenticate' => 'Basic realm="x"' ),
		);
		foreach ( $cases as $headers ) {
			$result = Eligibility::check( 200, self::HTML + $headers, '<p>x</p>' );
			self::assertFalse( $result->success, (string) json_encode( $headers ) );
			self::assertFalse( $result->retryable );
		}
	}

	public function test_password_form_reason_wins_over_private_cache_headers(): void {
		$result = Eligibility::check( 200, self::HTML + array( 'cache-control' => 'private' ), '<input name="post_password">' );

		self::assertSame( 'Page is password protected.', $result->message );
	}

	public function test_shareable_cache_headers_stay_eligible(): void {
		$headers = self::HTML + array(
			'cache-control' => 'public, max-age=600',
			'vary'          => 'Accept-Encoding',
		);
		self::assertTrue( Eligibility::check( 200, $headers, '<p>x</p>' )->success );
	}
}
