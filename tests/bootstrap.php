<?php
declare(strict_types=1);

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name ) ?? $name;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
