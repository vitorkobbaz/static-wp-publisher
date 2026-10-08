<?php
/**
 * Applies the speed options to a static copy before it is stored.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\HtmlOptimizer;
use SWPP\Core\Domain\OptimizerAssets;
use SWPP\Core\Domain\OptimizerOptions;
use Throwable;

final class PageOptimizer {
	public function __construct( private readonly OptimizerAssets $assets ) {}

	/**
	 * Current speed options. Image, font and preload optimization is on unless turned
	 * off; CSS combining is experimental and off unless turned on.
	 *
	 * @return array{optimize:bool,combine_css:bool}
	 */
	public static function settings(): array {
		$settings = get_option( 'swpp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		return array(
			'optimize'    => ! array_key_exists( 'optimize', $settings ) || ! empty( $settings['optimize'] ),
			'combine_css' => ! empty( $settings['combine_css'] ),
		);
	}

	/** Never breaks publication: any failure stores the page exactly as rendered. */
	public function optimize( string $html ): string {
		$settings = self::settings();
		try {
			return ( new HtmlOptimizer() )->optimize(
				$html,
				new OptimizerOptions(
					$settings['optimize'],
					$settings['combine_css'],
					max( 0, (int) apply_filters( 'swpp_eager_images', 2 ) )
				),
				$this->assets
			);
		} catch ( Throwable ) {
			return $html;
		}
	}
}
