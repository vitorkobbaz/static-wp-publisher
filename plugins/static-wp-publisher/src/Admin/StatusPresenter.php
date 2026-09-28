<?php
/**
 * Labels and colours for page states, shared by the list table and REST.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Admin;

use SWPP\Core\Domain\PageStatus;
use SWPP\Core\Domain\SpeedComparison;

final class StatusPresenter {
	public function __construct( private readonly bool $servingEnabled ) {}

	public static function forCurrentSettings(): self {
		$settings = get_option( 'swpp_settings', array() );
		return new self( is_array( $settings ) && ! empty( $settings['enabled'] ) );
	}

	public function servingEnabled(): bool {
		return $this->servingEnabled;
	}

	/**
	 * Green is reserved for an up-to-date static copy; anything still in motion is blue,
	 * degraded states amber, failures red, and WordPress-only pages grey.
	 *
	 * @return array{label:string,tone:string}
	 */
	public function describe( PageStatus $status ): array {
		return match ( $status->key ) {
			PageStatus::STATIC_COPY => array(
				'label' => $this->servingEnabled ? __( 'Static', 'static-wp-publisher' ) : __( 'Static copy ready', 'static-wp-publisher' ),
				'tone'  => 'good',
			),
			PageStatus::UPDATING    => array(
				'label' => __( 'Update pending', 'static-wp-publisher' ),
				'tone'  => 'info',
			),
			PageStatus::QUEUED      => array(
				'label' => __( 'Queued', 'static-wp-publisher' ),
				'tone'  => 'info',
			),
			PageStatus::GENERATING  => array(
				'label' => __( 'Generating', 'static-wp-publisher' ),
				'tone'  => 'info',
			),
			PageStatus::RETRYING    => array(
				'label' => __( 'Retrying after an error', 'static-wp-publisher' ),
				'tone'  => 'warn',
			),
			PageStatus::STALE       => array(
				'label' => __( 'Outdated static copy', 'static-wp-publisher' ),
				'tone'  => 'warn',
			),
			PageStatus::EXPOSED     => array(
				'label' => __( 'Protected page still public', 'static-wp-publisher' ),
				'tone'  => 'bad',
			),
			PageStatus::ERROR       => array(
				'label' => __( 'Error', 'static-wp-publisher' ),
				'tone'  => 'bad',
			),
			PageStatus::DYNAMIC     => array(
				'label' => __( 'Served by WordPress', 'static-wp-publisher' ),
				'tone'  => 'neutral',
			),
			default                 => array(
				'label' => __( 'Not generated yet', 'static-wp-publisher' ),
				'tone'  => 'neutral',
			),
		};
	}

	/** Status dot, label and reason, as escaped HTML. The label always carries the meaning. */
	public function statusHtml( PageStatus $status ): string {
		$state = $this->describe( $status );
		$html  = sprintf(
			'<span class="swpp-state swpp-state--%1$s" data-swpp-status="%2$s" data-swpp-group="%3$s"><span class="swpp-dot" aria-hidden="true"></span>%4$s</span>',
			esc_attr( $state['tone'] ),
			esc_attr( $status->key ),
			esc_attr( $status->group() ),
			esc_html( $state['label'] )
		);
		if ( '' !== $status->detail ) {
			$html .= '<span class="swpp-reason">' . esc_html( $status->detail ) . '</span>';
		}
		return $html;
	}

	/** Relative time ("5 minutes ago") with the exact local time on hover. */
	public function timeHtml( string $utc_mysql ): string {
		$timestamp = '' === $utc_mysql ? false : strtotime( $utc_mysql . ' UTC' );
		if ( false === $timestamp ) {
			return '&mdash;';
		}
		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( (string) wp_date( 'Y-m-d H:i', $timestamp ) ),
			/* translators: %s: human-readable time difference, e.g. "5 minutes". */
			esc_html( sprintf( __( '%s ago', 'static-wp-publisher' ), human_time_diff( $timestamp, time() ) ) )
		);
	}

	public function sizeHtml( int $bytes ): string {
		return $bytes > 0 ? esc_html( (string) size_format( $bytes ) ) : '&mdash;';
	}

	/**
	 * Tone of each dashboard group, in the order the composition bar shows them.
	 *
	 * @return array<string,string>
	 */
	public static function groupTones(): array {
		return array(
			PageStatus::GROUP_STATIC    => 'good',
			PageStatus::GROUP_PENDING   => 'info',
			PageStatus::GROUP_ATTENTION => 'bad',
			PageStatus::GROUP_DYNAMIC   => 'neutral',
			PageStatus::GROUP_MISSING   => 'empty',
		);
	}

	/**
	 * Last speed measurement as escaped HTML.
	 *
	 * @param array{url:string,static_ms:?int,dynamic_ms:?int,served_static:bool,measured_at:int,error:string}|null $last
	 */
	public function speedHtml( ?array $last ): string {
		if ( null === $last ) {
			return '<p class="swpp-speed__verdict">' . esc_html__( 'Not measured yet', 'static-wp-publisher' ) . '</p><p class="swpp-muted">' . esc_html__( 'Compare how fast your home page answers as static HTML and through WordPress. Takes a few seconds.', 'static-wp-publisher' ) . '</p>';
		}
		/* translators: %s: human-readable time difference, e.g. "5 minutes". */
		$when = '<p class="swpp-muted">' . esc_html( sprintf( __( 'Measured from your server %s ago, median of 3 requests each.', 'static-wp-publisher' ), human_time_diff( $last['measured_at'], time() ) ) ) . '</p>';
		if ( '' !== $last['error'] ) {
			return '<p class="swpp-speed__verdict">' . esc_html__( 'The measurement failed', 'static-wp-publisher' ) . '</p><p class="swpp-muted">' . esc_html( $last['error'] ) . '</p>';
		}
		if ( ! $last['served_static'] || null === $last['static_ms'] ) {
			return '<p class="swpp-speed__verdict">' . esc_html__( 'Served by WordPress', 'static-wp-publisher' ) . '</p>'
				. $this->barsHtml( null, (int) $last['dynamic_ms'] )
				. '<p class="swpp-muted">' . esc_html__( 'Turn static serving on and generate the home page to compare.', 'static-wp-publisher' ) . '</p>';
		}

		$comparison = SpeedComparison::of( $last['static_ms'], $last['dynamic_ms'] );
		if ( SpeedComparison::FASTER === $comparison->verdict ) {
			$factor = (float) $comparison->factor;
			/* translators: %s: speed-up factor, e.g. "12" or "2.5". */
			$verdict = sprintf( __( '%s× faster as static HTML', 'static-wp-publisher' ), number_format_i18n( $factor, fmod( $factor, 1.0 ) > 0 ? 1 : 0 ) );
			$note    = '';
		} else {
			$verdict = __( 'About as fast as WordPress', 'static-wp-publisher' );
			$note    = '<p class="swpp-muted">' . esc_html__( 'Static pages are still delivered through PHP here, so the gain depends on how heavy each page is to build. Direct web-server delivery (planned) removes that step.', 'static-wp-publisher' ) . '</p>';
		}
		return '<p class="swpp-speed__verdict">' . esc_html( $verdict ) . '</p>' . $this->barsHtml( $last['static_ms'], (int) $last['dynamic_ms'] ) . $note . $when;
	}

	/** Two proportional bars: static HTML against WordPress. */
	private function barsHtml( ?int $static_ms, int $dynamic_ms ): string {
		$scale = max( 1, $dynamic_ms, (int) $static_ms );
		$rows  = array();
		if ( null !== $static_ms ) {
			$rows[] = array( __( 'Static HTML', 'static-wp-publisher' ), $static_ms, 'good' );
		}
		$rows[] = array( __( 'WordPress', 'static-wp-publisher' ), $dynamic_ms, 'neutral' );

		$html = '<dl class="swpp-bars">';
		foreach ( $rows as list( $label, $ms, $tone ) ) {
			$html .= sprintf(
				'<div class="swpp-bar"><dt>%1$s</dt><dd><span class="swpp-bar__track"><span class="swpp-bar__fill swpp-tone--%2$s" style="width: %3$s%%"></span></span><span class="swpp-bar__value swpp-num">%4$s ms</span></dd></div>',
				esc_html( $label ),
				esc_attr( $tone ),
				esc_attr( (string) max( 2, round( 100 * $ms / $scale, 1 ) ) ),
				esc_html( number_format_i18n( $ms ) )
			);
		}
		return $html . '</dl>';
	}

	/** @return array<string,string> Tab labels by view key. */
	public static function viewLabels(): array {
		return array(
			'all'                       => __( 'All', 'static-wp-publisher' ),
			PageStatus::GROUP_STATIC    => __( 'Static', 'static-wp-publisher' ),
			PageStatus::GROUP_PENDING   => __( 'Pending', 'static-wp-publisher' ),
			PageStatus::GROUP_ATTENTION => __( 'Needs attention', 'static-wp-publisher' ),
			PageStatus::GROUP_DYNAMIC   => __( 'Served by WordPress', 'static-wp-publisher' ),
			PageStatus::GROUP_MISSING   => __( 'Not generated', 'static-wp-publisher' ),
		);
	}
}
