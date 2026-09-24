<?php
/**
 * PHP fallback static server.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Serving;

use Throwable;
use SWPP\Core\Infrastructure\Storage;

final class LocalServer {
	public function __construct( private readonly Storage $storage ) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybeServe' ), -1000 );
	}

	public function maybeServe(): void {
		$settings = get_option( 'swpp_settings', array() );
		if ( empty( $settings['enabled'] ) || defined( 'SWPP_DISABLE_STATIC' ) && SWPP_DISABLE_STATIC ) {
			return;
		}
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) || is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( $this->isSignedRenderRequest() || $this->hasBypassCookie() ) {
			return;
		}

		$request_uri = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$url         = home_url( strtok( $request_uri, '?' ) ?: '/' );
		try {
			$file = $this->storage->pathForUrl( $url );
		} catch ( Throwable ) {
			return;
		}
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Static-WP-Publisher: HIT' );
		header( 'Cache-Control: public, max-age=60, must-revalidate' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	private function isSignedRenderRequest(): bool {
		$provided = (string) ( $_SERVER['HTTP_X_SWPP_RENDER'] ?? '' );
		if ( '' === $provided ) {
			return false;
		}

		$home        = wp_parse_url( home_url( '/' ) );
		$request_uri = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		if ( empty( $home['scheme'] ) || empty( $home['host'] ) ) {
			return false;
		}
		$port = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		$url  = $home['scheme'] . '://' . $home['host'] . $port . $request_uri;

		return hash_equals( hash_hmac( 'sha256', $url, wp_salt( 'auth' ) ), $provided );
	}

	private function hasBypassCookie(): bool {
		$prefixes = array( 'wordpress_logged_in_', 'woocommerce_items_in_cart', 'wp_woocommerce_session_', 'comment_author_' );
		foreach ( array_keys( $_COOKIE ) as $cookie ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( (string) $cookie, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
