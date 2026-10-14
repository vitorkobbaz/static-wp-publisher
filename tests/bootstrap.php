<?php
declare(strict_types=1);

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name ) ?? $name;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
