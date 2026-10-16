<?php
/**
 * Anonymous public response renderer.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

use SWPP\Core\Domain\Origin;
use WP_Error;

final class Renderer {
	private const MAX_REDIRECTS = 3;

	/**
	 * Redirects are followed one hop at a time and only within the WordPress origin, so
	 * the stored body always comes from this site. A redirect that leaves the origin is
	 * returned as is: 301/308 are recorded as redirects, anything else is refused.
	 *
	 * @return array{status:int,headers:array<string,string|string[]>,body:string}|WP_Error
	 */
	public function render( string $url ): array|WP_Error {
		$home = home_url( '/' );
		if ( ! Origin::matches( $url, $home ) ) {
			return new WP_Error( 'swpp_external_origin', 'Renderer only accepts the WordPress origin.' );
		}

		$hops = 0;
		while ( true ) {
			$response = $this->fetch( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$status   = $response['status'];
			$location = $response['headers']['location'] ?? '';
			$location = is_array( $location ) ? (string) reset( $location ) : $location;
			if ( $status < 300 || $status > 399 || '' === $location || $hops >= self::MAX_REDIRECTS ) {
				return $response;
			}
			$next = \WP_Http::make_absolute_url( $location, $url );
			if ( ! Origin::matches( $next, $home ) ) {
				return $response;
			}
			$url = $next;
			++$hops;
		}
	}

	/**
	 * @return array{status:int,headers:array<string,string|string[]>,body:string}|WP_Error
	 */
	private function fetch( string $url ): array|WP_Error {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'X-SWPP-Render' => hash_hmac( 'sha256', $url, wp_salt( 'auth' ) ),
					'Cache-Control' => 'no-cache',
				),
				'user-agent'  => 'Static-Publisher/' . SWPP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			if ( is_array( $value ) ) {
				$headers[ strtolower( (string) $name ) ] = array_map( 'strval', $value );
			} elseif ( is_scalar( $value ) ) {
				$headers[ strtolower( (string) $name ) ] = (string) $value;
			}
		}
		$status = wp_remote_retrieve_response_code( $response );

		return array(
			'status'  => is_numeric( $status ) ? (int) $status : 0,
			'headers' => $headers,
			'body'    => wp_remote_retrieve_body( $response ),
		);
	}
}
