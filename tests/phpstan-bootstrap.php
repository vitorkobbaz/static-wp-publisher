<?php
/**
 * Symbols supplied by WordPress and plugin entry points at runtime.
 */

declare(strict_types=1);

define( 'SWPP_FILE', __FILE__ );
define( 'SWPP_VERSION', '0.1.0' );
define( 'SWPP_EXPORT_VERSION', '0.1.0' );

if ( ! class_exists( 'WP_CLI' ) ) {
	final class WP_CLI {
		public static function add_command( string $name, object $command ): void {}
		public static function line( string $message ): void {}
		public static function success( string $message ): void {}
		public static function error( string $message ): void {}
	}
}
