<?php
/**
 * ZIP package creation.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Infrastructure;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ZipPackager {
	public function create( string $source, string $destination ): string {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'The PHP ZIP extension is required to create archives.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $destination, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create ZIP archive.' );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) {
				$zip->close();
				throw new RuntimeException( 'Symbolic links are not allowed in exports.' );
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$absolute = $file->getPathname();
			$relative = str_replace( '\\', '/', substr( $absolute, strlen( rtrim( $source, '/\\' ) ) + 1 ) );
			if ( ! $zip->addFile( $absolute, $relative ) ) {
				$zip->close();
				throw new RuntimeException( 'Unable to add a file to the ZIP archive.' );
			}
		}
		if ( ! $zip->close() || ! is_file( $destination ) ) {
			throw new RuntimeException( 'ZIP archive validation failed.' );
		}

		$validation = new ZipArchive();
		if ( true !== $validation->open( $destination, ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'The generated ZIP archive cannot be reopened.' );
		}
		for ( $index = 0; $index < $validation->numFiles; $index++ ) {
			$name = $validation->getNameIndex( $index );
			if ( false === $name || str_starts_with( $name, '/' ) || str_contains( $name, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', str_replace( '\\', '/', $name ) ) ) {
				$validation->close();
				throw new RuntimeException( 'The generated ZIP contains an unsafe path.' );
			}
		}
		$validation->close();
		return $destination;
	}
}
