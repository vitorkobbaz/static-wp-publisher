<?php
/**
 * Authenticated delivery of generated export archives.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Admin;

use SWPP\Export\Infrastructure\ExportPathGuard;

final class ExportDownload {
	private const ACTION = 'swpp_download_export';
	private const TOKEN_PATTERN = '/\A[a-f0-9]{32}\z/';

	public function __construct( private readonly ExportPathGuard $paths ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function authorize( string $archive, int $userId ): ?string {
		$archive = $this->paths->resolve( $this->exportsRoot(), $archive );
		if ( null === $archive || $userId < 1 ) {
			return null;
		}

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! set_transient( $this->transientKey( $token, $userId ), $archive, HOUR_IN_SECONDS ) ) {
			return null;
		}

		return $token;
	}

	public function url( string $token ): string {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, self::ACTION );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download exports.', 'static-wp-publisher-export' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$token = sanitize_key( wp_unslash( (string) ( $_GET['token'] ?? '' ) ) );
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			wp_die( esc_html__( 'Invalid export download token.', 'static-wp-publisher-export' ), '', array( 'response' => 400 ) );
		}
		$user_id = get_current_user_id();
		$key     = $this->transientKey( $token, $user_id );
		$stored  = get_transient( $key );
		$archive = is_string( $stored ) ? $this->paths->resolve( $this->exportsRoot(), $stored ) : null;
		if ( null === $archive ) {
			wp_die( esc_html__( 'This export download is unavailable or has expired.', 'static-wp-publisher-export' ), '', array( 'response' => 404 ) );
		}
		if ( headers_sent() ) {
			wp_die( esc_html__( 'The archive cannot be downloaded because output has already started.', 'static-wp-publisher-export' ), '', array( 'response' => 500 ) );
		}

		$size   = filesize( $archive );
		$stream = fopen( $archive, 'rb' );
		if ( false === $size || false === $stream ) {
			wp_die( esc_html__( 'The archive could not be opened for download.', 'static-wp-publisher-export' ), '', array( 'response' => 500 ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $archive ) ) . '"' );
		header( 'Content-Length: ' . (string) $size );
		header( 'X-Content-Type-Options: nosniff' );

		$complete = true;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 1024 * 1024 );
			if ( false === $chunk ) {
				$complete = false;
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary archive stream.
			flush();
			if ( connection_aborted() ) {
				$complete = false;
				break;
			}
		}
		fclose( $stream );

		if ( $complete ) {
			delete_transient( $key );
		} else {
			error_log( 'Static WP Publisher Export: archive streaming did not complete.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		exit;
	}

	private function exportsRoot(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'static-wp-publisher/exports';
	}

	private function transientKey( string $token, int $userId ): string {
		return 'swpp_export_download_' . $userId . '_' . $token;
	}
}
