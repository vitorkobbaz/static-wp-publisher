<?php
/**
 * Resolves downloadable export archives within the managed exports directory.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Infrastructure;

final class ExportPathGuard {
	public function resolve( string $exportsRoot, string $candidate ): ?string {
		if ( '' === $exportsRoot || '' === $candidate || str_contains( $candidate, "\0" ) || is_link( $candidate ) ) {
			return null;
		}

		$root = realpath( $exportsRoot );
		$file = realpath( $candidate );
		if ( false === $root || false === $file || ! is_dir( $root ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$root = rtrim( $this->normalize( $root ), '/' );
		$file = $this->normalize( $file );
		if (
			! hash_equals( $root, dirname( $file ) ) ||
			'zip' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) )
		) {
			return null;
		}

		return $file;
	}

	private function normalize( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}
