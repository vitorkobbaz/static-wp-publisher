<?php
/**
 * Safe static artifact storage.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

use RuntimeException;
use SWPP\Core\Domain\UrlPath;

final class Storage {
	private const KEY_OPTION      = 'swpp_storage_key';
	private const MAX_VERSIONS    = 20;
	private const MAX_ASSET_FILES = 500;
	private const MAX_ASSET_BYTES = 104857600;

	public function root(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new RuntimeException( (string) $uploads['error'] );
		}
		return trailingslashit( (string) $uploads['basedir'] ) . 'static-wp-publisher/site-' . get_current_blog_id();
	}

	public function publishedRoot(): string {
		return $this->root() . '/published';
	}

	/**
	 * Working files and page history live in a directory named after a random per-site
	 * secret, so they cannot be requested by address on any web server. The generated
	 * deny rules (Apache and IIS) and empty index files are defence in depth only.
	 */
	public function privateRoot(): string {
		$key = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $key ) || 1 !== preg_match( '~^[a-f0-9]{32}$~', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			if ( ! add_option( self::KEY_OPTION, $key, '', false ) ) {
				$key = (string) get_option( self::KEY_OPTION, '' );
				if ( 1 !== preg_match( '~^[a-f0-9]{32}$~', $key ) ) {
					throw new RuntimeException( 'Unable to establish private Static WP Publisher storage.' );
				}
			}
		}
		return $this->root() . '/private-' . $key;
	}

	public function ensureStructure(): void {
		$private = $this->privateRoot();
		foreach ( array( $this->publishedRoot(), $private . '/versions', $private . '/tmp' ) as $directory ) {
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				throw new RuntimeException( 'Unable to create Static WP Publisher storage.' );
			}
		}
		$this->protect( $private );
		$this->retireLegacyDirectories( $private );
	}

	public function pathForUrl( string $url ): string {
		return $this->publishedRoot() . '/' . UrlPath::relative( $url, home_url( '/' ) );
	}

	public function delete( string $url ): void {
		$target = $this->pathForUrl( $url );
		if ( is_file( $target ) && ! unlink( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			throw new RuntimeException( 'Unable to remove stale static artifact.' );
		}
		// Withdrawn content must not survive as history either.
		$this->removeTree( $this->versionDirectory( UrlPath::relative( $url, home_url( '/' ) ) ) );

		$root      = rtrim( wp_normalize_path( $this->publishedRoot() ), '/' );
		$directory = dirname( $target );
		while ( is_dir( $directory ) && wp_normalize_path( $directory ) !== $root ) {
			$entries = scandir( $directory );
			if ( false === $entries || array_diff( $entries, array( '.', '..' ) ) ) {
				break;
			}
			if ( ! rmdir( $directory ) ) {
				break;
			}
			$directory = dirname( $directory );
		}
	}

	/**
	 * @return array{path:string,relative:string,hash:string,bytes:int}
	 */
	public function write( string $url, string $contents ): array {
		$this->ensureStructure();
		$relative = UrlPath::relative( $url, home_url( '/' ) );
		$target   = $this->publishedRoot() . '/' . $relative;
		$dir      = dirname( $target );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Unable to create artifact directory.' );
		}

		$hash = hash( 'sha256', $contents );
		if ( is_file( $target ) && hash_equals( (string) hash_file( 'sha256', $target ), $hash ) ) {
			// Unchanged: no rewrite and no new history entry.
			return array(
				'path'     => $target,
				'relative' => $relative,
				'hash'     => $hash,
				'bytes'    => (int) filesize( $target ),
			);
		}
		if ( is_file( $target ) ) {
			$this->snapshot( $target, $relative );
		}

		$temp = $this->privateRoot() . '/tmp/' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $contents, LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to write temporary artifact.' );
		}
		if ( ! rename( $temp, $target ) ) {
			@unlink( $temp );
			throw new RuntimeException( 'Unable to atomically publish artifact.' );
		}

		$hash = hash_file( 'sha256', $target );
		if ( false === $hash ) {
			throw new RuntimeException( 'Unable to hash published artifact.' );
		}

		return array(
			'path'     => $target,
			'relative' => $relative,
			'hash'     => $hash,
			'bytes'    => (int) filesize( $target ),
		);
	}

	/**
	 * Stores a generated asset (a combined stylesheet) under assets/<kind>/ and returns
	 * its public URL. Names are content hashes, so an existing file is already correct.
	 */
	public function writeAsset( string $kind, string $name, string $contents ): string {
		if ( 1 !== preg_match( '~^[a-z]{2,10}$~', $kind ) || 1 !== preg_match( '~^[a-f0-9]{16}\.(css)$~', $name ) ) {
			throw new RuntimeException( 'Invalid static asset name.' );
		}
		$this->ensureStructure();
		$directory = $this->root() . '/assets/' . $kind;
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'Unable to create the static asset directory.' );
		}
		$target = $directory . '/' . $name;
		if ( ! is_file( $target ) ) {
			$this->assertAssetQuota( $directory, strlen( $contents ) );
			$temp = $this->privateRoot() . '/tmp/' . wp_generate_uuid4() . '.tmp';
			if ( false === file_put_contents( $temp, $contents, LOCK_EX ) ) {
				throw new RuntimeException( 'Unable to write temporary asset.' );
			}
			if ( ! rename( $temp, $target ) ) {
				@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new RuntimeException( 'Unable to publish asset.' );
			}
		}

		$uploads = wp_upload_dir();
		$base    = set_url_scheme( trailingslashit( (string) $uploads['baseurl'] ), (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		return $base . 'static-wp-publisher/site-' . get_current_blog_id() . '/assets/' . $kind . '/' . $name;
	}

	/** Number of previous copies kept per page, from the retain_versions setting. */
	public static function retainedVersions(): int {
		$settings = get_option( 'swpp_settings', array() );
		$retain   = is_array( $settings ) && isset( $settings['retain_versions'] ) ? (int) $settings['retain_versions'] : 3;
		return max( 0, min( self::MAX_VERSIONS, $retain ) );
	}

	private function snapshot( string $target, string $relative ): void {
		$retain = self::retainedVersions();
		$dir    = $this->versionDirectory( $relative );
		if ( $retain > 0 ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw new RuntimeException( 'Unable to create the page history directory.' );
			}
			copy( $target, $dir . '/' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ) . '.html' );
		}
		$this->pruneVersions( $dir, $retain );
	}

	/** Keeps the newest $retain copies of one page and removes the rest. */
	private function pruneVersions( string $dir, int $retain ): void {
		$files = is_dir( $dir ) ? glob( $dir . '/*.html' ) : array();
		if ( ! is_array( $files ) || count( $files ) <= $retain ) {
			return;
		}
		rsort( $files, SORT_STRING );
		foreach ( array_slice( $files, $retain ) as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( 0 === $retain ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	private function versionDirectory( string $relative ): string {
		return $this->privateRoot() . '/versions/' . substr( hash( 'sha256', $relative ), 0, 32 );
	}

	/** Generated bundles are content-addressed and shared; their number and size are capped. */
	private function assertAssetQuota( string $directory, int $incoming ): void {
		$files = glob( $directory . '/*' );
		$files = is_array( $files ) ? $files : array();
		$bytes = $incoming;
		foreach ( $files as $file ) {
			$bytes += (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$max_files = (int) apply_filters( 'swpp_max_generated_assets', self::MAX_ASSET_FILES );
		$max_bytes = (int) apply_filters( 'swpp_max_generated_asset_bytes', self::MAX_ASSET_BYTES );
		if ( count( $files ) >= $max_files || $bytes > $max_bytes ) {
			throw new RuntimeException( 'Generated asset storage quota reached.' );
		}
	}

	/**
	 * Earlier versions kept history and working files in predictable directories that
	 * only Apache was told to protect. History moves into the private directory and the
	 * old working directory is removed.
	 */
	private function retireLegacyDirectories( string $private_root ): void {
		$legacy_versions = $this->root() . '/versions';
		if ( is_dir( $legacy_versions ) && ! is_link( $legacy_versions ) && ! is_dir( $private_root . '/versions-legacy' ) ) {
			if ( ! @rename( $legacy_versions, $private_root . '/versions-legacy' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->removeTree( $legacy_versions );
			}
		}
		$this->removeTree( $this->root() . '/tmp' );
	}

	private function removeTree( string $directory ): void {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}
		$entries = scandir( $directory );
		foreach ( false === $entries ? array() : array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->removeTree( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	private function protect( string $directory ): void {
		$files = array(
			'.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <requestFiltering>\n        <fileExtensions allowUnlisted=\"false\" />\n      </requestFiltering>\n    </security>\n    <directoryBrowse enabled=\"false\" />\n  </system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $files as $name => $contents ) {
			if ( ! is_file( $directory . '/' . $name ) ) {
				file_put_contents( $directory . '/' . $name, $contents, LOCK_EX );
			}
		}
	}
}
