<?php
/**
 * Private filesystem storage for portable exports.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Infrastructure;

use RuntimeException;

final class ExportStorage {
	public const CLEANUP_HOOK = 'swpp_export_cleanup';

	public function root(): string {
		$base = apply_filters( 'swpp_export_private_base_dir', get_temp_dir() );
		if ( ! is_string( $base ) || '' === trim( $base ) ) {
			throw new RuntimeException( 'A private export directory is not configured.' );
		}

		$base = realpath( $base );
		if ( false === $base || ! is_dir( $base ) || ! is_writable( $base ) ) {
			throw new RuntimeException( 'The private export base directory does not exist or is not writable.' );
		}

		$public_roots = $this->publicRoots();
		if ( ! $this->isPrivateLocation( $base, $public_roots ) ) {
			throw new RuntimeException( 'The configured export directory is publicly accessible. Configure swpp_export_private_base_dir outside the web document root.' );
		}

		$install_id = substr( hash( 'sha256', $this->normalize( ABSPATH ) ), 0, 16 );
		$root       = rtrim( $base, '/\\' ) . '/static-wp-publisher-exports/install-' . $install_id . '/site-' . get_current_blog_id();
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			throw new RuntimeException( 'Unable to create the private export directory.' );
		}

		$root = realpath( $root );
		if ( false === $root || ! is_dir( $root ) || ! is_writable( $root ) ) {
			throw new RuntimeException( 'The private export directory is not writable.' );
		}
		if ( ! $this->isPrivateLocation( $root, $public_roots ) ) {
			throw new RuntimeException( 'The configured export directory is publicly accessible. Configure swpp_export_private_base_dir outside the web document root.' );
		}

		if ( '\\' !== DIRECTORY_SEPARATOR && ! chmod( $root, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private application storage permissions.
			throw new RuntimeException( 'Unable to secure the private export directory permissions.' );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
		return $this->normalize( $root );
	}

	public function purgeExpired(): void {
		try {
			$this->purgeExpiredEntries( $this->root(), 2 * DAY_IN_SECONDS );
		} catch ( RuntimeException $error ) {
			error_log( 'Static WP Publisher Export: unable to purge expired exports: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public function deleteArtifacts( string $archive ): void {
		$root         = $this->root();
		$real_archive = realpath( $archive );
		if ( false === $real_archive || ! $this->isDirectChild( $root, $real_archive ) || 'zip' !== strtolower( pathinfo( $real_archive, PATHINFO_EXTENSION ) ) ) {
			return;
		}

		@unlink( $real_archive ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup must not corrupt an HTTP response.
		$directory = rtrim( $root, '/\\' ) . '/' . pathinfo( $real_archive, PATHINFO_FILENAME );
		if ( is_dir( $directory ) && ! is_link( $directory ) ) {
			$this->removeDirectory( $directory );
		}
	}

	public function discardDirectory( string $directory ): void {
		$root           = $this->root();
		$real_directory = realpath( $directory );
		if ( false === $real_directory || ! is_dir( $real_directory ) || is_link( $real_directory ) || ! $this->isDirectChild( $root, $real_directory ) ) {
			return;
		}
		$this->removeDirectory( $real_directory );
	}

	/**
	 * Determines whether a resolved directory is outside every public root.
	 *
	 * @param list<string> $publicRoots Existing document roots.
	 */
	public function isPrivateLocation( string $candidate, array $publicRoots ): bool {
		$candidate = realpath( $candidate );
		if ( false === $candidate || ! is_dir( $candidate ) ) {
			return false;
		}
		$candidate = $this->comparable( $candidate );

		foreach ( $publicRoots as $public_root ) {
			$public_root = realpath( $public_root );
			if ( false === $public_root ) {
				continue;
			}
			$public_root = $this->comparable( $public_root );
			if ( $candidate === $public_root || str_starts_with( $candidate, $public_root . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return list<string> */
	private function publicRoots(): array {
		$roots   = array( ABSPATH );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = (string) WP_CONTENT_DIR;
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) ) {
			$roots[] = (string) $uploads['basedir'];
		}
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) )
			: '';
		if ( '' !== $document_root ) {
			$roots[] = $document_root;
		}
		return array_values( array_unique( $roots ) );
	}

	private function comparable( string $path ): string {
		$path = rtrim( $this->normalize( $path ), '/' );
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}

	private function normalize( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	private function isDirectChild( string $root, string $candidate ): bool {
		return hash_equals( $this->comparable( $root ), $this->comparable( dirname( $candidate ) ) );
	}

	private function purgeExpiredEntries( string $root, int $maximumAge ): void {
		$entries = @scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
		if ( false === $entries ) {
			return;
		}
		$cutoff = time() - max( HOUR_IN_SECONDS, $maximumAge );
		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			$path = rtrim( $root, '/\\' ) . '/' . $entry;
			if ( ! $this->isDirectChild( $root, $path ) ) {
				continue;
			}
			$modified = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
			if ( false === $modified || $modified >= $cutoff ) {
				continue;
			}
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->removeDirectory( $path );
			} elseif ( is_file( $path ) || is_link( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup.
			}
		}
	}

	private function removeDirectory( string $directory ): void {
		$entries = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
		if ( false === $entries ) {
			return;
		}
		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->removeDirectory( $path );
			} elseif ( is_file( $path ) || is_link( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup.
			}
		}
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup.
	}
}
