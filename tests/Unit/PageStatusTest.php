<?php
/**
 * Page status resolution tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\PageStatus;

final class PageStatusTest extends TestCase {
	private const ARTIFACT = array( 'published_at' => '2026-09-28 12:00:00' );

	/** @return array{status:string,attempts:int,last_error:string,updated_at:string} */
	private static function job( string $status, int $attempts = 0, string $error = '', string $updated = '2026-09-28 11:00:00' ): array {
		return array(
			'status'     => $status,
			'attempts'   => $attempts,
			'last_error' => $error,
			'updated_at' => $updated,
		);
	}

	public function test_published_copy_is_static(): void {
		$status = PageStatus::resolve( self::job( 'succeeded' ), self::ARTIFACT, false );

		self::assertSame( PageStatus::STATIC_COPY, $status->key );
		self::assertTrue( $status->isServedStatically() );
		self::assertSame( PageStatus::STATIC_COPY, PageStatus::resolve( null, self::ARTIFACT, false )->key );
	}

	public function test_active_job_over_existing_copy_is_updating(): void {
		self::assertSame( PageStatus::UPDATING, PageStatus::resolve( self::job( 'pending' ), self::ARTIFACT, false )->key );
		self::assertSame( PageStatus::UPDATING, PageStatus::resolve( self::job( 'running' ), self::ARTIFACT, false )->key );
	}

	public function test_failure_newer_than_copy_marks_it_stale(): void {
		$stale = PageStatus::resolve( self::job( 'failed', 3, 'Timeout', '2026-09-28 13:00:00' ), self::ARTIFACT, false );
		$old   = PageStatus::resolve( self::job( 'failed', 3, 'Timeout', '2026-09-28 10:00:00' ), self::ARTIFACT, false );

		self::assertSame( PageStatus::STALE, $stale->key );
		self::assertSame( 'Timeout', $stale->detail );
		self::assertSame( PageStatus::STATIC_COPY, $old->key );
	}

	public function test_protected_page_with_copy_is_flagged_as_exposed(): void {
		$status = PageStatus::resolve( self::job( 'succeeded' ), self::ARTIFACT, true );

		self::assertSame( PageStatus::EXPOSED, $status->key );
		self::assertTrue( $status->isServedStatically() );
	}

	public function test_states_without_a_copy(): void {
		self::assertSame( PageStatus::QUEUED, PageStatus::resolve( self::job( 'pending' ), null, false )->key );
		self::assertSame( PageStatus::GENERATING, PageStatus::resolve( self::job( 'running' ), null, false )->key );
		self::assertSame( PageStatus::RETRYING, PageStatus::resolve( self::job( 'pending', 1, 'HTTP status 503 is not publishable.' ), null, false )->key );
		self::assertSame( PageStatus::ERROR, PageStatus::resolve( self::job( 'failed', 3, 'Timeout' ), null, false )->key );
		self::assertSame( PageStatus::MISSING, PageStatus::resolve( null, null, false )->key );
		self::assertFalse( PageStatus::resolve( null, null, false )->isServedStatically() );
	}

	public function test_unpublishable_pages_are_dynamic_with_reason(): void {
		$skipped   = PageStatus::resolve( self::job( 'skipped', 0, 'Page is password protected.' ), null, true );
		$protected = PageStatus::resolve( null, null, true );

		self::assertSame( PageStatus::DYNAMIC, $skipped->key );
		self::assertSame( 'Page is password protected.', $skipped->detail );
		self::assertSame( PageStatus::DYNAMIC, $protected->key );
		self::assertFalse( $protected->isServedStatically() );
	}
}
