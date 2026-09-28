<?php
/**
 * Processes queued publication jobs within a batch and time budget.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\WorkerBudget;
use SWPP\Core\Domain\WorkerReport;

final class Worker {
	public function __construct(
		private readonly Queue $queue,
		private readonly Publisher $publisher,
	) {}

	/** Budget for cron, admin and REST passes, adjustable through filters. */
	public static function requestBudget(): WorkerBudget {
		return WorkerBudget::forRequest(
			(int) apply_filters( 'swpp_worker_batch_size', WorkerBudget::DEFAULT_LIMIT ),
			(int) apply_filters( 'swpp_worker_time_budget', WorkerBudget::DEFAULT_SECONDS ),
			(int) ini_get( 'max_execution_time' )
		);
	}

	public function run( WorkerBudget $budget ): WorkerReport {
		$started   = microtime( true );
		$succeeded = 0;
		$failed    = 0;
		$error     = null;

		while ( $succeeded + $failed < $budget->limit ) {
			// Check before claiming so no job is leased and then abandoned.
			if ( $budget->seconds > 0 && $succeeded + $failed > 0 && microtime( true ) - $started >= $budget->seconds ) {
				break;
			}
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}

			$result = $this->publisher->publish( $job->url );
			if ( $result->success ) {
				$this->queue->complete( $job );
				++$succeeded;
			} else {
				$this->queue->fail( $job, $result->message );
				++$failed;
				$error = $job->url . ': ' . $result->message;
			}
		}

		return new WorkerReport( $succeeded + $failed, $succeeded, $failed, $error );
	}
}
