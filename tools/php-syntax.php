<?php
/**
 * Cross-platform PHP syntax check used by Composer and CI.
 */

declare(strict_types=1);

$roots  = array( dirname( __DIR__ ) . '/plugins', dirname( __DIR__ ) . '/tests' );
$failed = false;

foreach ( $roots as $root ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() );
		passthru( $command, $exitCode );
		$failed = $failed || 0 !== $exitCode;
	}
}

exit( $failed ? 1 : 0 );
