<?php
/**
 * Determines whether a rendered response is safe to publish.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final class Eligibility {
	/**
	 * Transient failures stay retryable; deterministic refusals are returned through
	 * PublishResult::refused() so the queue stops retrying and stale copies are removed.
	 *
	 * @param array<string,string|string[]> $headers Response headers.
	 */
	public static function check( int $status, array $headers, string $body ): PublishResult {
		if ( in_array( $status, array( 301, 308 ), true ) ) {
			return new PublishResult( true, 'Redirect is eligible for the redirect manifest.' );
		}
		if ( 200 !== $status ) {
			$message = sprintf( 'HTTP status %d is not publishable.', $status );
			return self::isTransientStatus( $status ) ? new PublishResult( false, $message ) : PublishResult::refused( $message );
		}

		$content_type_header = $headers['content-type'] ?? '';
		$content_type        = strtolower( is_array( $content_type_header ) ? implode( ', ', $content_type_header ) : $content_type_header );
		if ( ! str_contains( $content_type, 'text/html' ) ) {
			return PublishResult::refused( 'Response is not HTML.' );
		}
		if ( isset( $headers['set-cookie'] ) ) {
			return PublishResult::refused( 'Response attempted to set a cookie.' );
		}

		if ( false !== stripos( $body, 'name="post_password"' ) ) {
			return PublishResult::refused( 'Page is password protected.' );
		}
		$unsafe_markers = array(
			'id="wpadminbar"',
			'wp-login.php?action=logout',
		);
		foreach ( $unsafe_markers as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				return PublishResult::refused( 'Response appears personalized or protected.' );
			}
		}

		if ( '' === trim( $body ) ) {
			return new PublishResult( false, 'Response body is empty.' );
		}

		return new PublishResult( true, 'Response is eligible.' );
	}

	private static function isTransientStatus( int $status ): bool {
		return 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500;
	}
}
