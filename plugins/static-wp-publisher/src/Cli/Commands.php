<?php
/**
 * WP-CLI command registration.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Cli;

use SWPP\Core\Application\Inventory;
use SWPP\Core\Application\Publisher;
use SWPP\Core\Application\Queue;
use SWPP\Core\Infrastructure\Storage;

final class Commands {
	public function __construct(
		private readonly Queue $queue,
		private readonly Publisher $publisher,
		private readonly Inventory $inventory,
		private readonly Storage $storage,
	) {}

	public static function register( Queue $queue, Publisher $publisher, Inventory $inventory, Storage $storage ): void {
		\WP_CLI::add_command( 'swpp', new self( $queue, $publisher, $inventory, $storage ) );
	}

	/**
	 * Shows queue and publication status.
	 *
	 * @param list<string> $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$data = array(
			'queue'          => $this->queue->counts(),
			'published_root' => $this->storage->publishedRoot(),
		);
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}
		\WP_CLI::line( 'Published root: ' . $data['published_root'] );
		foreach ( $data['queue'] as $status => $count ) {
			\WP_CLI::line( sprintf( '%s: %d', $status, $count ) );
		}
	}

	/** Queues the complete public inventory. */
	public function build(): void {
		\WP_CLI::success( sprintf( '%d URL(s) queued.', $this->inventory->enqueueAll() ) );
	}

	/**
	 * Publishes one URL immediately.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Public URL on this WordPress origin.
	 */
	/** @param list<string> $args Positional arguments. */
	public function publish( array $args ): void {
		$result = $this->publisher->publish( (string) ( $args[0] ?? '' ) );
		if ( $result->success ) {
			\WP_CLI::success( $result->message );
		} else {
			\WP_CLI::error( $result->message );
		}
	}

	/**
	 * Processes due queue items.
	 *
	 * @param list<string> $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Named arguments.
	 */
	public function process( array $args, array $assoc_args ): void {
		unset( $args );
		$limit = max( 1, min( 1000, (int) ( $assoc_args['limit'] ?? 10 ) ) );
		$done  = 0;
		while ( $done < $limit ) {
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$result = $this->publisher->publish( $job->url );
			if ( $result->success ) {
				$this->queue->complete( $job );
			} else {
				$this->queue->fail( $job, $result->message );
			}
			++$done;
		}
		\WP_CLI::success( sprintf( '%d job(s) processed.', $done ) );
	}
}
