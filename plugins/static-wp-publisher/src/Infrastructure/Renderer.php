<?php
/**
 * Anonymous public response renderer.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

use WP_Error;

final class Renderer {
	/**
	 * @return array{status:int,headers:array<string,string|string[]>,body:string}|WP_Error
	 */
	public function render( string $url ): array|WP_Error {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( empty( $target['host'] ) || empty( $home['host'] ) || strtolower( (string) $target['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return new WP_Error( 'swpp_external_origin', 'Renderer only accepts the WordPress origin.' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => array(
					'X-SWPP-Render' => hash_hmac( 'sha256', $url, wp_salt( 'auth' ) ),
					'Cache-Control' => 'no-cache',
				),
				'user-agent'  => 'Static-WP-Publisher/' . SWPP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = $value;
		}

		return array(
			'status'  => wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => wp_remote_retrieve_body( $response ),
		);
	}
}
