<?php
/**
 * Export add-on composition root.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export;

use SWPP\Export\Admin\ExportDownload;
use SWPP\Export\Admin\ExportPage;
use SWPP\Export\Application\Exporter;
use SWPP\Export\Infrastructure\AssetCollector;
use SWPP\Export\Infrastructure\Entitlement;
use SWPP\Export\Infrastructure\ExportPathGuard;
use SWPP\Export\Infrastructure\ExportStorage;
use SWPP\Export\Infrastructure\ZipPackager;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted           = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$storage      = new ExportStorage();
		add_action( ExportStorage::CLEANUP_HOOK, array( $storage, 'purgeExpired' ) );
		$exporter     = new Exporter( new UrlRewriter(), new ZipPackager(), new AssetCollector(), $storage );
		$download     = new ExportDownload( new ExportPathGuard(), $storage );
		$download->register();
		( new ExportPage( $exporter, new Entitlement(), $download ) )->register();
		do_action( 'swpp_export_ready', $this );
	}
}
