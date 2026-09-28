<?php
/**
 * Measures how fast one page answers as static HTML versus WordPress.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

final class SpeedCheck {
	public const OPTION   = 'swpp_speed_check';
	private const SAMPLES = 3;

	public function __construct( private readonly Verifier $verifier ) {}

	/**
	 * Takes three anonymous samples of each path from this server and keeps the medians.
	 * The WordPress path adds an unknown query parameter, which the static server never
	 * answers, so the same page is rendered dynamically.
	 *
	 * @return array{url:string,static_ms:?int,dynamic_ms:?int,served_static:bool,measured_at:int,error:string}
	 */
	public function measure( string $url ): array {
		$static  = array();
		$dynamic = array();
		$served  = true;
		$error   = '';
		for ( $i = 0; $i < self::SAMPLES; ++$i ) {
			$hit = $this->verifier->check( $url );
			$raw = $this->verifier->check( add_query_arg( 'swpp_speed', wp_generate_password( 8, false ), $url ) );
			if ( ! $hit['ok'] || ! $raw['ok'] ) {
				$error = '' !== $hit['error'] ? $hit['error'] : $raw['error'];
				break;
			}
			$served    = $served && $hit['static'];
			$static[]  = $hit['milliseconds'];
			$dynamic[] = $raw['milliseconds'];
		}

		$result = array(
			'url'           => $url,
			'static_ms'     => '' === $error && $served ? self::median( $static ) : null,
			'dynamic_ms'    => '' === $error ? self::median( $dynamic ) : null,
			'served_static' => '' === $error && $served,
			'measured_at'   => time(),
			'error'         => $error,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/** @return array{url:string,static_ms:?int,dynamic_ms:?int,served_static:bool,measured_at:int,error:string}|null */
	public static function last(): ?array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['url'], $stored['measured_at'] ) ) {
			return null;
		}
		return array(
			'url'           => (string) $stored['url'],
			'static_ms'     => isset( $stored['static_ms'] ) ? (int) $stored['static_ms'] : null,
			'dynamic_ms'    => isset( $stored['dynamic_ms'] ) ? (int) $stored['dynamic_ms'] : null,
			'served_static' => ! empty( $stored['served_static'] ),
			'measured_at'   => (int) $stored['measured_at'],
			'error'         => isset( $stored['error'] ) ? (string) $stored['error'] : '',
		);
	}

	/** @param list<int> $values */
	private static function median( array $values ): ?int {
		if ( array() === $values ) {
			return null;
		}
		sort( $values );
		return $values[ intdiv( count( $values ), 2 ) ];
	}
}
