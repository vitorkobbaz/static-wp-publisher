<?php
/**
 * Checks what an anonymous visitor receives for one URL.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

use SWPP\Core\Domain\Origin;

final class Verifier {
	/**
	 * Requests the URL without cookies or the render signature, exactly like a new visitor.
	 *
	 * @return array{ok:bool,status:int,static:bool,milliseconds:int,error:string}
	 */
	public function check( string $url ): array {
		if ( ! Origin::matches( $url, home_url( '/' ) ) ) {
			return array(
				'ok'           => false,
				'status'       => 0,
				'static'       => false,
				'milliseconds' => 0,
				'error'        => 'Only URLs on this site can be verified.',
			);
		}

		$started  = microtime( true );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => 'Static-WP-Publisher-Verifier/' . SWPP_VERSION,
			)
		);
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'           => false,
				'status'       => 0,
				'static'       => false,
				'milliseconds' => $elapsed,
				'error'        => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$marker = wp_remote_retrieve_header( $response, 'x-static-wp-publisher' );
		$marker = is_array( $marker ) ? (string) reset( $marker ) : $marker;
		return array(
			'ok'           => true,
			'status'       => is_numeric( $status ) ? (int) $status : 0,
			'static'       => 'HIT' === strtoupper( trim( $marker ) ),
			'milliseconds' => $elapsed,
			'error'        => '',
		);
	}
}
