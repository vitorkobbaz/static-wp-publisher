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
		$url = $this->report->urlForContent( $post_id );
		if ( null === $url ) {
			return null;
		}
		return self::VERIFY === $action ? $this->verify( $url ) : $this->regenerate( $url );
	}

	/** @return array{ok:bool,level:string,message:string,static:?bool} */
	private function regenerate( string $url ): array {
		$result = $this->worker->runOne( $url );
		if ( null === $result ) {
			return $this->outcome( false, 'warning', __( 'Already being generated. Try again in a moment.', 'static-wp-publisher' ) );
		}
		if ( $result->success ) {
			return $this->outcome( true, 'success', __( 'Static copy updated.', 'static-wp-publisher' ) );
		}
		if ( ! $result->retryable ) {
			/* translators: %s: reason the page cannot be static. */
			return $this->outcome( true, 'info', sprintf( __( 'Not publishable: %s It keeps being served by WordPress.', 'static-wp-publisher' ), $result->message ) );
		}
		/* translators: %s: error message. */
		return $this->outcome( false, 'error', sprintf( __( 'Generation failed: %s It will be retried automatically.', 'static-wp-publisher' ), $result->message ) );
	}

	/** @return array{ok:bool,level:string,message:string,static:?bool} */
	private function verify( string $url ): array {
		$check = $this->verifier->check( $url );
		if ( ! $check['ok'] ) {
			/* translators: %s: error message. */
			return $this->outcome( false, 'error', sprintf( __( 'Could not load the page: %s', 'static-wp-publisher' ), $check['error'] ) );
		}
		if ( $check['static'] ) {
			/* translators: 1: HTTP status code, 2: response time in milliseconds. */
			return $this->outcome( true, 'success', sprintf( __( 'Confirmed: delivered from the static copy (HTTP %1$d, %2$d ms).', 'static-wp-publisher' ), $check['status'], $check['milliseconds'] ), true );
		}
		/* translators: 1: HTTP status code, 2: response time in milliseconds. */
		return $this->outcome( true, 'warning', sprintf( __( 'Delivered by WordPress, not from a static copy (HTTP %1$d, %2$d ms).', 'static-wp-publisher' ), $check['status'], $check['milliseconds'] ), false );
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
