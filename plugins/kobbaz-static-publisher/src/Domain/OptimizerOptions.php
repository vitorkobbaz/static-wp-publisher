<?php
/**
 * What the static-copy optimizer may change.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final readonly class OptimizerOptions {
	public const MAX_PRELOADS = 2;

	/**
	 * @param bool $optimize    Preload the LCP image, font-display: swap, image attributes.
	 * @param bool $combineCss  Combine consecutive local stylesheets (experimental).
	 * @param int  $eagerImages Leading images never lazy-loaded.
	 */
	public function __construct(
		public bool $optimize = true,
		public bool $combineCss = false,
		public int $eagerImages = 2,
	) {}
}
