<?php
/**
 * Labels, colours and icons for page states, shared by the list table and REST.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Admin;

use SWPP\Core\Domain\PageStatus;

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
	 * @return array{label:string,tone:string,icon:string}
	 */
	public function describe( PageStatus $status ): array {
		return match ( $status->key ) {
			PageStatus::STATIC_COPY => array(
				'label' => $this->servingEnabled ? __( 'Static', 'static-wp-publisher' ) : __( 'Static copy ready', 'static-wp-publisher' ),
				'tone'  => 'good',
				'icon'  => 'yes-alt',
			),
			PageStatus::UPDATING    => array(
				'label' => __( 'Update pending', 'static-wp-publisher' ),
				'tone'  => 'info',
				'icon'  => 'update',
			),
			PageStatus::QUEUED      => array(
				'label' => __( 'Queued', 'static-wp-publisher' ),
				'tone'  => 'info',
				'icon'  => 'clock',
			),
			PageStatus::GENERATING  => array(
				'label' => __( 'Generating', 'static-wp-publisher' ),
				'tone'  => 'info',
				'icon'  => 'update',
			),
			PageStatus::RETRYING    => array(
				'label' => __( 'Retrying after an error', 'static-wp-publisher' ),
				'tone'  => 'warn',
				'icon'  => 'backup',
			),
			PageStatus::STALE       => array(
				'label' => __( 'Outdated static copy', 'static-wp-publisher' ),
				'tone'  => 'warn',
				'icon'  => 'warning',
			),
			PageStatus::EXPOSED     => array(
				'label' => __( 'Protected page still public', 'static-wp-publisher' ),
				'tone'  => 'bad',
				'icon'  => 'shield',
			),
			PageStatus::ERROR       => array(
				'label' => __( 'Error', 'static-wp-publisher' ),
				'tone'  => 'bad',
				'icon'  => 'dismiss',
			),
			PageStatus::DYNAMIC     => array(
				'label' => __( 'Served by WordPress', 'static-wp-publisher' ),
				'tone'  => 'neutral',
				'icon'  => 'wordpress',
			),
			default                 => array(
				'label' => __( 'Not generated yet', 'static-wp-publisher' ),
				'tone'  => 'neutral',
				'icon'  => 'minus',
			),
		};
	}

	/** Badge plus reason, as escaped HTML. */
	public function statusHtml( PageStatus $status ): string {
		$state = $this->describe( $status );
		$html  = sprintf(
			'<span class="swpp-badge swpp-badge--%1$s" data-swpp-status="%2$s" data-swpp-group="%3$s"><span class="dashicons dashicons-%4$s" aria-hidden="true"></span>%5$s</span>',
			esc_attr( $state['tone'] ),
			esc_attr( $status->key ),
			esc_attr( $status->group() ),
			esc_attr( $state['icon'] ),
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
