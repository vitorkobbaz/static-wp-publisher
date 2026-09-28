<?php
/**
 * Core plugin composition root.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core;

use SWPP\Core\Admin\AdminPage;
use SWPP\Core\Application\Invalidator;
use SWPP\Core\Application\Inventory;
use SWPP\Core\Application\PageOptimizer;
use SWPP\Core\Application\Publisher;
use SWPP\Core\Application\Queue;
use SWPP\Core\Application\StatusReport;
use SWPP\Core\Application\Worker;
use SWPP\Core\Cli\Commands;
use SWPP\Core\Http\RestController;
use SWPP\Core\Infrastructure\Database;
use SWPP\Core\Infrastructure\CronSchedule;
use SWPP\Core\Infrastructure\LocalAssetResolver;
use SWPP\Core\Infrastructure\Renderer;
use SWPP\Core\Infrastructure\Storage;
use SWPP\Core\Infrastructure\Verifier;
use SWPP\Core\Serving\LocalServer;

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

		$database = new Database();
		$database->maybeUpgrade();
		$storage   = new Storage();
		$queue     = new Queue( $database );
		$publisher = new Publisher( new Renderer(), $storage, $database, new PageOptimizer( new LocalAssetResolver( $storage ) ) );
		$inventory = new Inventory( $queue );

		add_filter( 'cron_schedules', array( CronSchedule::class, 'add' ) );
		add_action( 'swpp_process_queue', array( $this, 'processQueue' ) );

		( new LocalServer( $storage ) )->register();
		( new Invalidator( $queue ) )->register();
		$report   = new StatusReport( $database, $queue );
		$verifier = new Verifier();
		( new AdminPage( $queue, $publisher, $inventory, $storage, $report, $verifier ) )->register();
		( new RestController( $queue, $publisher, $inventory, $storage, $report, $verifier ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register( $queue, $publisher, $inventory, $storage );
		}

		do_action( 'swpp_core_ready', $this );
	}

	public function processQueue(): void {
		$database  = new Database();
		$queue     = new Queue( $database );
		$storage   = new Storage();
		$publisher = new Publisher( new Renderer(), $storage, $database, new PageOptimizer( new LocalAssetResolver( $storage ) ) );

		( new Worker( $queue, $publisher ) )->runPass( new Inventory( $queue ), Worker::requestBudget() );
	}
}
