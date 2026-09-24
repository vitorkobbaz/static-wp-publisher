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
use SWPP\Core\Infrastructure\Renderer;
use SWPP\Core\Infrastructure\Storage;
use SWPP\Core\Serving\LocalServer;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$database  = new Database();
		$storage   = new Storage();
		$queue     = new Queue( $database );
		$publisher = new Publisher( new Renderer(), $storage, $database );
		$inventory = new Inventory( $queue );

		add_filter( 'cron_schedules', array( $this, 'cronSchedules' ) );
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

	/**
	 * Adds the one-minute compatibility worker interval.
	 *
	 * @param array<string,array<string,int|string>> $schedules Existing schedules.
	 * @return array<string,array<string,int|string>>
	 */
	public function cronSchedules( array $schedules ): array {
		$schedules['swpp_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Static WP Publisher)', 'static-wp-publisher' ),
		);
		return $schedules;
	}

	public function processQueue(): void {
		$database  = new Database();
		$queue     = new Queue( $database );
		$publisher = new Publisher( new Renderer(), new Storage(), $database );
		$limit     = (int) apply_filters( 'swpp_worker_batch_size', 3 );

		for ( $i = 0; $i < max( 1, min( 50, $limit ) ); ++$i ) {
			$job = $queue->claim();
			if ( null === $job ) {
				break;
			}

			$result = $publisher->publish( $job->url );
			if ( $result->success ) {
				$queue->complete( $job->id );
			} else {
				$queue->fail( $job->id, $result->message );
			}
		}
 	}
}
