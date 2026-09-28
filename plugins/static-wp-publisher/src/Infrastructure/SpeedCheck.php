<?php
/**
 * Measures how fast one page answers as static HTML versus WordPress.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

/**
 * @phpstan-type SpeedResult array{url:string,label:string,post_id:int,static_ms:?int,dynamic_ms:?int,served_static:bool,measured_at:int,error:string}
 */
final class SpeedCheck {
	public const OPTION  = 'swpp_speed_check';
	public const SAMPLES = 3;

	public function __construct( private readonly Verifier $verifier ) {}

	/**
	 * Requests the page SAMPLES times as an anonymous visitor (static copy when one is
	 * served) and SAMPLES times through WordPress, from this server, and keeps the
	 * medians. The WordPress path adds an unknown query parameter, which the static
	 * server never answers, so the same page is built live. The last result is stored.
	 *
	 * @return SpeedResult
	 */
	public function measure( string $url, string $label, int $post_id ): array {
		$static  = array();
		$dynamic = array();
		$served  = true;
		$error   = '';
		for ( $i = 0; $i < self::SAMPLES; ++$i ) {
			$visitor = $this->verifier->check( $url );
			$live    = $this->verifier->check( add_query_arg( 'swpp_speed', wp_generate_password( 8, false ), $url ) );
			if ( ! $visitor['ok'] || ! $live['ok'] ) {
				$error = '' !== $visitor['error'] ? $visitor['error'] : $live['error'];
				break;
			}
			$served    = $served && $visitor['static'];
			$static[]  = $visitor['milliseconds'];
			$dynamic[] = $live['milliseconds'];
		}

		$result = array(
			'url'           => $url,
			'label'         => $label,
			'post_id'       => $post_id,
			'static_ms'     => '' === $error && $served ? self::median( $static ) : null,
			'dynamic_ms'    => '' === $error ? self::median( $dynamic ) : null,
			'served_static' => '' === $error && $served,
			'measured_at'   => time(),
			'error'         => $error,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/** @return SpeedResult|null */
	public static function last(): ?array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['url'], $stored['measured_at'] ) ) {
			return null;
		}
		return array(
			'url'           => (string) $stored['url'],
			'label'         => isset( $stored['label'] ) ? (string) $stored['label'] : (string) $stored['url'],
			'post_id'       => isset( $stored['post_id'] ) ? (int) $stored['post_id'] : 0,
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
