<?php
/**
 * Publishes one URL.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use Throwable;
use SWPP\Core\Domain\Eligibility;
use SWPP\Core\Domain\PublishResult;
use SWPP\Core\Infrastructure\Database;
use SWPP\Core\Infrastructure\Renderer;
use SWPP\Core\Infrastructure\Storage;

final class Publisher {
	public function __construct(
		private readonly Renderer $renderer,
		private readonly Storage $storage,
		private readonly Database $database,
	) {}

	public function publish( string $url ): PublishResult {
		$response = $this->renderer->render( $url );
		if ( is_wp_error( $response ) ) {
			return new PublishResult( false, $response->get_error_message() );
		}
		if ( in_array( $response['status'], array( 404, 410 ), true ) ) {
			try {
				$this->storage->delete( $url );
				$this->removeArtifact( $url );
				return new PublishResult( true, 'Removed stale artifact for a missing URL.' );
			} catch ( Throwable $error ) {
				return new PublishResult( false, $error->getMessage() );
			}
		}

		$eligible = Eligibility::check( $response['status'], $response['headers'], $response['body'] );
		if ( ! $eligible->success ) {
			return $eligible;
		}

		if ( in_array( $response['status'], array( 301, 308 ), true ) ) {
			$redirects         = get_option( 'swpp_redirects', array() );
			$location          = $response['headers']['location'] ?? '';
			$redirects[ $url ] = is_array( $location ) ? (string) reset( $location ) : $location;
			update_option( 'swpp_redirects', $redirects, false );
			return new PublishResult( true, 'Redirect recorded.' );
		}

		try {
			$file = $this->storage->write( $url, $response['body'] );
			$this->recordArtifact( $url, $file['relative'], $file['hash'], $file['bytes'], $response['status'] );
			return new PublishResult( true, 'Published.', $file['relative'], $file['hash'] );
		} catch ( Throwable $error ) {
			return new PublishResult( false, $error->getMessage() );
		}
	}

	private function recordArtifact( string $url, string $path, string $hash, int $bytes, int $status ): void {
		global $wpdb;
		$table = $this->database->table( 'artifacts' );
		$wpdb->replace(
			$table,
			array(
				'url_hash'      => hash( 'sha256', $url ),
				'url'           => $url,
				'relative_path' => $path,
				'content_hash'  => $hash,
				'bytes'         => $bytes,
				'status_code'   => $status,
				'published_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	private function removeArtifact( string $url ): void {
		global $wpdb;
		$wpdb->delete(
			$this->database->table( 'artifacts' ),
			array( 'url_hash' => hash( 'sha256', $url ) ),
			array( '%s' )
		);
	}
}
