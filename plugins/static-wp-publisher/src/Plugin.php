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
use SWPP\Core\Application\Publisher;
use SWPP\Core\Application\Queue;
use SWPP\Core\Cli\Commands;
use SWPP\Core\Http\RestController;
use SWPP\Core\Infrastructure\Database;
use SWPP\Core\Infrastructure\CronSchedule;
use SWPP\Core\Infrastructure\Renderer;
use SWPP\Core\Infrastructure\Storage;
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
		$publisher = new Publisher( new Renderer(), $storage, $database );
		$inventory = new Inventory( $queue );

		add_filter( 'cron_schedules', array( CronSchedule::class, 'add' ) );
		add_action( 'swpp_process_queue', array( $this, 'processQueue' ) );

		( new LocalServer( $storage ) )->register();
		( new Invalidator( $queue ) )->register();
		( new AdminPage( $queue, $publisher, $inventory, $storage ) )->register();
		( new RestController( $queue, $publisher, $inventory, $storage ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register( $queue, $publisher, $inventory, $storage );
		}

		do_action( 'swpp_core_ready', $this );
	}

	public function processQueue(): void {
		$database  = new Database();
		$queue     = new Queue( $database );
		$publisher = new Publisher( new Renderer(), new Storage(), $database );
		$limit     = (int) apply_filters( 'swpp_worker_batch_size', 3 );

		if ( false !== get_option( 'swpp_full_rebuild_recommended', false ) ) {
			// Delete first so a concurrent content change can safely request another sweep.
			delete_option( 'swpp_full_rebuild_recommended' );
			( new Inventory( $queue ) )->enqueueAll();
		} else {
			( new Inventory( $queue ) )->enqueueBatch();
		}

		for ( $i = 0; $i < max( 1, min( 50, $limit ) ); ++$i ) {
			$job = $queue->claim();
			if ( null === $job ) {
				break;
			}

			$result = $publisher->publish( $job->url );
			if ( $result->success ) {
				$queue->complete( $job );
			} else {
				$queue->fail( $job, $result->message );
			}
		}
	}
}
