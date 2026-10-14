<?php
/**
 * Portable export composition root.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Export;

use SWPP\Core\Export\Admin\ExportDownload;
use SWPP\Core\Export\Admin\ExportPage;
use SWPP\Core\Export\Application\Exporter;
use SWPP\Core\Export\Infrastructure\AssetCollector;
use SWPP\Core\Export\Infrastructure\ExportPathGuard;
use SWPP\Core\Export\Infrastructure\ExportStorage;
use SWPP\Core\Export\Infrastructure\ZipPackager;

/**
 * Manual directory and ZIP exports are part of the free plugin, without limits.
 * Add-ons extend them with deployment targets through the swpp_deploy_targets filter.
 */
final class ExportModule {
	public function register(): void {
		$storage = new ExportStorage();
		add_action( ExportStorage::CLEANUP_HOOK, array( $storage, 'purgeExpired' ) );
		$exporter = new Exporter( new UrlRewriter(), new ZipPackager(), new AssetCollector(), $storage );
		$download = new ExportDownload( new ExportPathGuard(), $storage );
		$download->register();
		( new ExportPage( $exporter, $download ) )->register();
		do_action( 'swpp_export_ready', $this );
	}

	/**
	 * Deployment targets registered by add-ons, keyed by their ID.
	 *
	 * @return array<string,DeployTarget>
	 */
	public static function deployTargets(): array {
		$targets = array();
		foreach ( (array) apply_filters( 'swpp_deploy_targets', array() ) as $target ) {
			if ( $target instanceof DeployTarget && 1 === preg_match( '~^[a-z0-9_-]{1,40}$~', $target->id() ) ) {
				$targets[ $target->id() ] = $target;
			}
		}
		return $targets;
	}
}
