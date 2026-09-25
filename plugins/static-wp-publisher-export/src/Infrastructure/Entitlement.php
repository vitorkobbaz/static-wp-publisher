<?php
/**
 * Commercial entitlement boundary.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Infrastructure;

final class Entitlement {
	public function isAllowed(): bool {
		if ( defined( 'SWPP_EXPORT_DEV_MODE' ) && SWPP_EXPORT_DEV_MODE ) {
			return true;
		}
		if ( function_exists( 'swppe_fs' ) ) {
			$client = swppe_fs();
			if ( is_object( $client ) && method_exists( $client, 'can_use_premium_code' ) ) {
				return (bool) $client->can_use_premium_code();
			}
		}
		return (bool) apply_filters( 'swpp_export_entitled', false );
	}
}
