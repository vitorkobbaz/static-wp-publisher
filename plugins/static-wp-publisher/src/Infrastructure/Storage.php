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

	public function ensureStructure(): void {
		foreach ( array( $this->publishedRoot(), $this->root() . '/versions', $this->root() . '/tmp', $this->root() . '/manifests' ) as $directory ) {
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				throw new RuntimeException( 'Unable to create Static WP Publisher storage.' );
			}
		}
		$this->protect( $this->root() . '/tmp' );
		$this->protect( $this->root() . '/versions' );
	}

	public function pathForUrl( string $url ): string {
		return $this->publishedRoot() . '/' . UrlPath::relative( $url, home_url( '/' ) );
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

		if ( is_file( $target ) ) {
			$version_dir = $this->root() . '/versions/' . gmdate( 'Ymd-His' ) . '/' . dirname( $relative );
			wp_mkdir_p( $version_dir );
			copy( $target, trailingslashit( $version_dir ) . basename( $target ) );
		}

		$temp = $this->root() . '/tmp/' . wp_generate_uuid4() . '.tmp';
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

	private function protect( string $directory ): void {
		$rules = "Options -Indexes\n<FilesMatch \".*\">\nRequire all denied\n</FilesMatch>\n";
		if ( ! is_file( $directory . '/.htaccess' ) ) {
			file_put_contents( $directory . '/.htaccess', $rules, LOCK_EX );
		}
		if ( ! is_file( $directory . '/index.php' ) ) {
			file_put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX );
		}
	}
}
