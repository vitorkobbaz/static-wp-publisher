<?php
/**
 * Local-file access the static-copy optimizer needs.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

interface OptimizerAssets {
	/** Contents of a same-origin stylesheet stored on this server, or null. */
	public function stylesheet( string $url ): ?string;

	/**
	 * Intrinsic size of a same-origin image stored on this server, or null.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public function imageSize( string $url ): ?array;

	/** True when the URL points to a file stored on this server. */
	public function isLocal( string $url ): bool;

	/** Stores a combined stylesheet and returns its public URL, or null on failure. */
	public function storeStylesheet( string $css ): ?string;
}
