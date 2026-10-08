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
use SWPP\Core\Infrastructure\SpeedCheck;

/**
 * @phpstan-import-type SpeedResult from SpeedCheck
 */
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
				'label' => __( 'Updating', 'static-wp-publisher' ),
				'tone'  => 'info',
			),
			PageStatus::QUEUED      => array(
				'label' => __( 'Waiting to be created', 'static-wp-publisher' ),
				'tone'  => 'info',
			),
			PageStatus::GENERATING  => array(
				'label' => __( 'Being created', 'static-wp-publisher' ),
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
				'label' => __( 'Could not be created', 'static-wp-publisher' ),
				'tone'  => 'bad',
			),
			PageStatus::DYNAMIC     => array(
				'label' => __( 'WordPress only', 'static-wp-publisher' ),
				'tone'  => 'neutral',
			),
			default                 => array(
				'label' => __( 'Not created yet', 'static-wp-publisher' ),
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

	/** Relative time ("5 mins ago") with the exact local time on hover. */
	public function timeHtml( string $utc_mysql ): string {
		$timestamp = '' === $utc_mysql ? false : strtotime( $utc_mysql . ' UTC' );
		if ( false === $timestamp ) {
			return '&mdash;';
		}
		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( (string) wp_date( 'Y-m-d H:i', $timestamp ) ),
			esc_html( self::ago( $timestamp ) )
		);
	}

	/** "just now" under a minute, otherwise "5 mins ago". */
	public static function ago( int $timestamp ): string {
		if ( time() - $timestamp < MINUTE_IN_SECONDS ) {
			return __( 'just now', 'static-wp-publisher' );
		}
		/* translators: %s: human-readable time difference, e.g. "5 mins". */
		return sprintf( __( '%s ago', 'static-wp-publisher' ), human_time_diff( $timestamp, time() ) );
	}

	/** Milliseconds as seconds with two decimals ("0.30 s"). */
	public static function seconds( int $milliseconds ): string {
		/* translators: %s: duration in seconds, e.g. "0.30". */
		return sprintf( __( '%s s', 'static-wp-publisher' ), number_format_i18n( $milliseconds / 1000, 2 ) );
	}

	/** "2× faster." / "2.5× faster." */
	public static function factorLabel( float $factor ): string {
		/* translators: %s: speed-up factor, e.g. "12" or "2.5". */
		return sprintf( __( '%s× faster.', 'static-wp-publisher' ), number_format_i18n( $factor, fmod( $factor, 1.0 ) > 0 ? 1 : 0 ) );
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
	 * Result of the last visitor test, as escaped HTML: which page, what visitors got,
	 * and both response times.
	 *
	 * @param SpeedResult|null $last
	 */
	public function speedHtml( ?array $last ): string {
		if ( null === $last ) {
			return '<p class="swpp-speed__verdict">' . esc_html__( 'Not tested yet', 'static-wp-publisher' ) . '</p>'
				. '<p class="swpp-speed__lead">' . esc_html__( 'Pick a page and compare how fast visitors get it as a static copy and through WordPress.', 'static-wp-publisher' ) . '</p>';
		}

		$page = sprintf(
			'<p class="swpp-speed__page">%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a> · %4$s</p>',
			esc_html__( 'Page tested:', 'static-wp-publisher' ),
			esc_url( $last['url'] ),
			esc_html( $last['label'] ),
			/* translators: %s: relative time, e.g. "just now" or "5 mins ago". */
			esc_html( sprintf( __( 'tested %s', 'static-wp-publisher' ), self::ago( $last['measured_at'] ) ) )
		);

		if ( '' !== $last['error'] ) {
			return '<p class="swpp-speed__verdict">' . esc_html__( 'The test could not run', 'static-wp-publisher' ) . '</p>' . $page
				. '<p class="swpp-speed__lead">' . esc_html__( 'Your server could not reach its own address. Some hosts block this; try again later or ask your host.', 'static-wp-publisher' ) . '</p>'
				. '<details class="swpp-speed__error"><summary>' . esc_html__( 'Error details', 'static-wp-publisher' ) . '</summary><p>' . esc_html( $last['error'] ) . '</p></details>';
		}

		if ( ! $last['served_static'] || null === $last['static_ms'] ) {
			return '<p class="swpp-speed__verdict">' . esc_html__( 'No static copy delivered', 'static-wp-publisher' ) . '</p>' . $page
				. $this->barsHtml( null, (int) $last['dynamic_ms'] )
				. '<p class="swpp-speed__lead">' . esc_html__( 'Visitors got live WordPress. Create the static copy of this page, turn static delivery on, and test again to compare.', 'static-wp-publisher' ) . '</p>';
		}

		$comparison = SpeedComparison::of( $last['static_ms'], $last['dynamic_ms'] );
		$faster     = SpeedComparison::FASTER === $comparison->verdict;
		$verdict    = $faster
			/* translators: %s: speed-up sentence, e.g. "2× faster.". */
			? sprintf( __( 'Visitors get it %s', 'static-wp-publisher' ), self::factorLabel( (float) $comparison->factor ) )
			: __( 'About as fast as WordPress', 'static-wp-publisher' );
		$note = $faster ? '' : '<p class="swpp-speed__lead">' . esc_html__( 'This page is already quick to build, so the gain is small.', 'static-wp-publisher' ) . '</p>';

		return '<p class="swpp-speed__verdict">' . esc_html( $verdict ) . '</p>' . $page
			. $this->barsHtml( $last['static_ms'], (int) $last['dynamic_ms'] ) . $note;
	}

	/** How the test works and what it is not. */
	public static function methodHtml( string $url ): string {
		return '<details class="swpp-method"><summary>' . esc_html__( 'How is this measured?', 'static-wp-publisher' ) . '</summary>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of requests of each kind. */
					__( 'Static Publisher asks your own server for the page %d times the way a visitor gets it, and the same number of times forcing WordPress to build it. It shows the typical (median) time of each.', 'static-wp-publisher' ),
					SpeedCheck::SAMPLES
				)
			) . '</p>'
			. '<p>' . esc_html__( 'This is how quickly your server starts answering. It is not a Google PageSpeed score and does not include images or scripts loading in the visitor\'s browser.', 'static-wp-publisher' ) . '</p>'
			. '<p><a href="' . esc_url( 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $url ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Test the full page load with PageSpeed Insights', 'static-wp-publisher' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'static-wp-publisher' ) . '</span><span aria-hidden="true"> ↗</span></a></p>'
			. '</details>';
	}

	/** Two proportional bars: static copy against WordPress. */
	private function barsHtml( ?int $static_ms, int $dynamic_ms ): string {
		$scale = max( 1, $dynamic_ms, (int) $static_ms );
		$rows  = array();
		if ( null !== $static_ms ) {
			$rows[] = array( __( 'Static copy', 'static-wp-publisher' ), $static_ms, 'good' );
		}
		$rows[] = array( __( 'WordPress', 'static-wp-publisher' ), $dynamic_ms, 'neutral' );

		$html = '<dl class="swpp-bars">';
		foreach ( $rows as list( $label, $ms, $tone ) ) {
			$html .= sprintf(
				'<div class="swpp-bar"><dt>%1$s</dt><dd><span class="swpp-bar__track"><span class="swpp-bar__fill swpp-tone--%2$s" style="width: %3$s%%"></span></span><span class="swpp-bar__value swpp-num">%4$s</span></dd></div>',
				esc_html( $label ),
				esc_attr( $tone ),
				esc_attr( (string) max( 2, round( 100 * $ms / $scale, 1 ) ) ),
				esc_html( self::seconds( $ms ) )
			);
		}
		return $html . '</dl>';
	}

	/** @return array<string,string> Tab labels by view key. */
	public static function viewLabels(): array {
		return array(
			'all'                       => __( 'All', 'static-wp-publisher' ),
			PageStatus::GROUP_STATIC    => __( 'Static', 'static-wp-publisher' ),
			PageStatus::GROUP_PENDING   => __( 'Updating', 'static-wp-publisher' ),
			PageStatus::GROUP_ATTENTION => __( 'Needs attention', 'static-wp-publisher' ),
			PageStatus::GROUP_DYNAMIC   => __( 'WordPress only', 'static-wp-publisher' ),
			PageStatus::GROUP_MISSING   => __( 'Not created yet', 'static-wp-publisher' ),
		);
	}
}
