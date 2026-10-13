<?php
/**
 * Builds the WordPress.org package of the free plugin: dist/static-wp-publisher-<version>.zip
 * with a single static-wp-publisher/ folder. Fails when versions disagree, so a release
 * never ships a readme, header and constant that point at different versions.
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$plugin = $root . '/plugins/static-wp-publisher';
$main   = (string) file_get_contents( $plugin . '/static-wp-publisher.php' );
$readme = (string) file_get_contents( $plugin . '/readme.txt' );

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/define\(\s*'SWPP_VERSION',\s*'([^']+)'/", $main, $constant );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
$versions = array( $header[1] ?? '', $constant[1] ?? '', $stable[1] ?? '' );
if ( '' === $versions[0] || 1 !== count( array_unique( $versions ) ) ) {
	fwrite( STDERR, 'Version mismatch (header, SWPP_VERSION, Stable tag): ' . implode( ', ', $versions ) . PHP_EOL );
	exit( 1 );
}
$version = $versions[0];

// Only runtime files are shipped.
$include = array( 'static-wp-publisher.php', 'uninstall.php', 'readme.txt', 'src', 'assets', 'languages' );

$dist = $root . '/dist';
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0755, true ) ) {
	fwrite( STDERR, 'Unable to create dist/.' . PHP_EOL );
	exit( 1 );
}
$target = $dist . '/static-wp-publisher-' . $version . '.zip';
@unlink( $target );

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, 'Unable to create ' . $target . PHP_EOL );
	exit( 1 );
}
$count = 0;
foreach ( $include as $entry ) {
	$path = $plugin . '/' . $entry;
	if ( is_file( $path ) ) {
		$zip->addFile( $path, 'static-wp-publisher/' . $entry );
		++$count;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( ! $file->isFile() || $file->isLink() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $plugin ) + 1 ) );
		$zip->addFile( $file->getPathname(), 'static-wp-publisher/' . $relative );
		++$count;
	}
}
$zip->close();

echo 'Built ' . $target . ' (' . $count . ' files, version ' . $version . ')' . PHP_EOL;
