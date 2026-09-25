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
	 * @param array<string,string|string[]> $headers Response headers.
	 */
	public static function check( int $status, array $headers, string $body ): PublishResult {
		if ( in_array( $status, array( 301, 308 ), true ) ) {
			return new PublishResult( true, 'Redirect is eligible for the redirect manifest.' );
		}
		if ( 200 !== $status ) {
			return new PublishResult( false, sprintf( 'HTTP status %d is not publishable.', $status ) );
		}

		$content_type_header = $headers['content-type'] ?? '';
		$content_type        = strtolower( is_array( $content_type_header ) ? implode( ', ', $content_type_header ) : $content_type_header );
		if ( ! str_contains( $content_type, 'text/html' ) ) {
			return new PublishResult( false, 'Response is not HTML.' );
		}
		if ( isset( $headers['set-cookie'] ) ) {
			return new PublishResult( false, 'Response attempted to set a cookie.' );
		}

		$unsafe_markers = array(
			'id="wpadminbar"',
			'name="post_password"',
			'wp-login.php?action=logout',
		);
		foreach ( $unsafe_markers as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				return new PublishResult( false, 'Response appears personalized or protected.' );
			}
		}

		if ( '' === trim( $body ) ) {
			return new PublishResult( false, 'Response body is empty.' );
		}

		return new PublishResult( true, 'Response is eligible.' );
	}
}
