<?php
/**
 * Single-page administrator actions shared by admin-post handlers and REST.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Admin;

use SWPP\Core\Application\StatusReport;
use SWPP\Core\Application\Worker;
use SWPP\Core\Domain\SpeedComparison;
use SWPP\Core\Infrastructure\SpeedCheck;
use SWPP\Core\Infrastructure\Verifier;

final class PageActions {
	public const REGENERATE = 'regenerate';
	public const VERIFY     = 'verify';

	public function __construct(
		private readonly Worker $worker,
		private readonly StatusReport $report,
		private readonly Verifier $verifier,
	) {}

	/**
	 * @return array{ok:bool,level:string,message:string,static:?bool}|null Null when the id is not a public page.
	 */
	public function run( string $action, int $post_id ): ?array {
		$row = $this->report->rowFor( $post_id );
		if ( null === $row ) {
			return null;
		}
		return self::VERIFY === $action ? $this->verify( $row['url'], $row['title'], $post_id ) : $this->regenerate( $row['url'] );
	}

	/** @return array{ok:bool,level:string,message:string,static:?bool} */
	private function regenerate( string $url ): array {
		$result = $this->worker->runOne( $url );
		if ( null === $result ) {
			return $this->outcome( false, 'warning', __( 'This page is already being updated. Try again in a moment.', 'static-wp-publisher' ) );
		}
		if ( $result->success ) {
			return $this->outcome( true, 'success', __( 'Static copy updated.', 'static-wp-publisher' ) );
		}
		if ( ! $result->retryable ) {
			/* translators: %s: reason the page cannot be static. */
			return $this->outcome( true, 'info', sprintf( __( 'This page always uses live WordPress: %s', 'static-wp-publisher' ), $result->message ) );
		}
		/* translators: %s: error message. */
		return $this->outcome( false, 'error', sprintf( __( 'The static copy could not be updated: %s It will be retried automatically.', 'static-wp-publisher' ), $result->message ) );
	}

	/**
	 * Loads the page the way a visitor gets it and the way WordPress builds it, and says
	 * which one visitors receive and how fast each answers.
	 *
	 * @return array{ok:bool,level:string,message:string,static:?bool}
	 */
	private function verify( string $url, string $title, int $post_id ): array {
		$result = ( new SpeedCheck( $this->verifier ) )->measure( $url, $title, $post_id );
		if ( '' !== $result['error'] ) {
			/* translators: %s: error message. */
			return $this->outcome( false, 'error', sprintf( __( 'Your server could not load its own page: %s', 'static-wp-publisher' ), $result['error'] ) );
		}
		$live = StatusPresenter::seconds( (int) $result['dynamic_ms'] );
		if ( ! $result['served_static'] || null === $result['static_ms'] ) {
			/* translators: %s: response time, e.g. "0.58 s". */
			return $this->outcome( true, 'warning', sprintf( __( 'Visitors get live WordPress for this page (%s). It has no static copy being delivered.', 'static-wp-publisher' ), $live ), false );
		}

		$comparison = SpeedComparison::of( $result['static_ms'], $result['dynamic_ms'] );
		$message    = sprintf(
			/* translators: 1: static response time, 2: WordPress response time. */
			__( 'Visitors get the static copy: %1$s instead of %2$s through WordPress.', 'static-wp-publisher' ),
			StatusPresenter::seconds( $result['static_ms'] ),
			$live
		);
		if ( SpeedComparison::FASTER === $comparison->verdict ) {
			$message .= ' ' . StatusPresenter::factorLabel( (float) $comparison->factor );
		}
		return $this->outcome( true, 'success', $message, true );
	}

	/** @return array{ok:bool,level:string,message:string,static:?bool} */
	private function outcome( bool $ok, string $level, string $message, ?bool $is_static = null ): array {
		return array(
			'ok'      => $ok,
			'level'   => $level,
			'message' => $message,
			'static'  => $is_static,
		);
	}
}
