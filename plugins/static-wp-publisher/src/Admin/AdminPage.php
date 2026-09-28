<?php
/**
 * WordPress administration screen.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Admin;

use SWPP\Core\Application\Inventory;
use SWPP\Core\Application\Publisher;
use SWPP\Core\Application\Queue;
use SWPP\Core\Application\Worker;
use SWPP\Core\Infrastructure\Storage;

final class AdminPage {
	public function __construct(
		private readonly Queue $queue,
		private readonly Publisher $publisher,
		private readonly Inventory $inventory,
		private readonly Storage $storage,
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_swpp_full_build', array( $this, 'fullBuild' ) );
		add_action( 'admin_post_swpp_process_now', array( $this, 'processNow' ) );
		add_action( 'admin_post_swpp_toggle_serving', array( $this, 'toggleServing' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Static WP Publisher', 'static-wp-publisher' ),
			__( 'Static Publisher', 'static-wp-publisher' ),
			'manage_options',
			'static-wp-publisher',
			array( $this, 'render' ),
			'dashicons-performance',
			58
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage static publication.', 'static-wp-publisher' ) );
		}

		$counts   = $this->queue->counts();
		$settings = get_option( 'swpp_settings', array() );
		$enabled  = ! empty( $settings['enabled'] );
		$next     = wp_next_scheduled( 'swpp_process_queue' );
		$next_at  = false !== $next ? wp_date( 'Y-m-d H:i:s', $next ) : false;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Static WP Publisher', 'static-wp-publisher' ); ?></h1>
			<?php if ( isset( $_GET['swpp_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['swpp_notice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Overview', 'static-wp-publisher' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th><?php esc_html_e( 'Local static serving', 'static-wp-publisher' ); ?></th><td><?php echo $enabled ? esc_html__( 'Published', 'static-wp-publisher' ) : esc_html__( 'Preview only', 'static-wp-publisher' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Queue', 'static-wp-publisher' ); ?></th><td><?php echo esc_html( sprintf( 'Pending: %d | Running: %d | Failed: %d | Skipped: %d | Completed: %d', $counts['pending'], $counts['running'], $counts['failed'], $counts['skipped'], $counts['succeeded'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Next compatibility worker', 'static-wp-publisher' ); ?></th><td><?php echo is_string( $next_at ) ? esc_html( $next_at ) : esc_html__( 'Not scheduled — configure WP-Cron or a real scheduler.', 'static-wp-publisher' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Published directory', 'static-wp-publisher' ); ?></th><td><code><?php echo esc_html( $this->storage->publishedRoot() ); ?></code></td></tr>
				</tbody>
			</table>

			<p>
				<?php $this->actionButton( 'swpp_full_build', __( 'Queue full preview build', 'static-wp-publisher' ), 'button button-primary' ); ?>
				<?php $this->actionButton( 'swpp_process_now', __( 'Process next batch', 'static-wp-publisher' ) ); ?>
				<?php $this->actionButton( 'swpp_toggle_serving', $enabled ? __( 'Return to dynamic WordPress', 'static-wp-publisher' ) : __( 'Publish generated HTML', 'static-wp-publisher' ), $enabled ? 'button' : 'button button-secondary' ); ?>
			</p>

			<?php $this->renderProblems(); ?>

			<h2><?php esc_html_e( 'Important limitations', 'static-wp-publisher' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'PHP fallback serving still boots WordPress. Review server-specific direct-file rules before maximum-performance production use.', 'static-wp-publisher' ); ?></li>
				<li><?php esc_html_e( 'Search, forms, comments, login, carts, checkout, accounts, and personalized pages require a dynamic backend.', 'static-wp-publisher' ); ?></li>
				<li><?php esc_html_e( 'WP-Cron can be late on low-traffic origins. Configure a real scheduler for precise publication times.', 'static-wp-publisher' ); ?></li>
			</ul>

			<?php do_action( 'swpp_admin_after_overview', $this ); ?>
		</div>
		<?php
	}

	public function fullBuild(): void {
		$this->authorize( 'swpp_full_build' );
		$count = $this->inventory->enqueueAll();
		/* translators: %d: number of URLs added to the publication queue. */
		$this->redirect( sprintf( __( '%d URL(s) added to the publication queue.', 'static-wp-publisher' ), $count ) );
	}

	public function processNow(): void {
		$this->authorize( 'swpp_process_now' );
		$report = ( new Worker( $this->queue, $this->publisher ) )->run( Worker::requestBudget() );
		if ( 0 === $report->processed ) {
			$retry_at = $this->queue->nextRetryAt();
			if ( null !== $retry_at ) {
				$this->redirect(
					sprintf(
						/* translators: 1: number of jobs waiting, 2: local date and time of the next retry. */
						__( 'No job is due now. %1$d job(s) are waiting to retry after an error; next attempt at %2$s. See "Needs attention" below.', 'static-wp-publisher' ),
						$this->queue->counts()['pending'],
						$this->localTime( $retry_at )
					)
				);
			}
			$this->redirect( __( 'The queue is empty.', 'static-wp-publisher' ) );
		}

		$pending = $this->queue->counts()['pending'];
		$notice  = sprintf(
			/* translators: 1: processed URLs, 2: published URLs, 3: failed URLs, 4: skipped URLs, 5: URLs still pending. */
			__( '%1$d URL(s) processed: %2$d published, %3$d failed, %4$d skipped (not publishable). %5$d still pending.', 'static-wp-publisher' ),
			$report->processed,
			$report->succeeded,
			$report->failed,
			$report->skipped,
			$pending
		);
		if ( null !== $report->lastError ) {
			/* translators: %s: URL and error message of the last failed job. */
			$notice .= ' ' . sprintf( __( 'Last error: %s', 'static-wp-publisher' ), $report->lastError );
		}
		$this->redirect( $notice );
	}

	public function toggleServing(): void {
		$this->authorize( 'swpp_toggle_serving' );
		$settings            = get_option( 'swpp_settings', array() );
		$settings['enabled'] = empty( $settings['enabled'] );
		update_option( 'swpp_settings', $settings, false );
		$this->redirect( $settings['enabled'] ? __( 'Static serving published.', 'static-wp-publisher' ) : __( 'Dynamic WordPress restored.', 'static-wp-publisher' ) );
	}

	private function renderProblems(): void {
		$problems = $this->queue->problems();
		if ( array() === $problems ) {
			return;
		}
		$labels = array(
			'pending' => __( 'Retrying', 'static-wp-publisher' ),
			'failed'  => __( 'Failed', 'static-wp-publisher' ),
			'skipped' => __( 'Not publishable (served dynamically)', 'static-wp-publisher' ),
		);
		?>
		<h2><?php esc_html_e( 'Needs attention', 'static-wp-publisher' ); ?></h2>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'static-wp-publisher' ); ?></th>
					<th><?php esc_html_e( 'Status', 'static-wp-publisher' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'static-wp-publisher' ); ?></th>
					<th><?php esc_html_e( 'Next attempt', 'static-wp-publisher' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'static-wp-publisher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $problems as $problem ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $problem['url'] ); ?>"><?php echo esc_html( $problem['url'] ); ?></a></td>
						<td><?php echo esc_html( $labels[ $problem['status'] ] ?? $problem['status'] ); ?></td>
						<td><?php echo esc_html( (string) $problem['attempts'] ); ?></td>
						<td><?php echo 'pending' === $problem['status'] ? esc_html( $this->localTime( $problem['available_at'] ) ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( $problem['last_error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function localTime( string $utc_mysql ): string {
		$timestamp = strtotime( $utc_mysql . ' UTC' );
		return false === $timestamp ? $utc_mysql : (string) wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	private function actionButton( string $action, string $label, string $css_class = 'button' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'static-wp-publisher' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( 'swpp_notice', rawurlencode( $notice ), admin_url( 'admin.php?page=static-wp-publisher' ) ) );
		exit;
	}
}
