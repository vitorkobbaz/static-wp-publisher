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
					<tr><th><?php esc_html_e( 'Queue', 'static-wp-publisher' ); ?></th><td><?php echo esc_html( sprintf( 'Pending: %d | Running: %d | Failed: %d | Completed: %d', $counts['pending'], $counts['running'], $counts['failed'], $counts['succeeded'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Next compatibility worker', 'static-wp-publisher' ); ?></th><td><?php echo $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : esc_html__( 'Not scheduled — configure WP-Cron or a real scheduler.', 'static-wp-publisher' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Published directory', 'static-wp-publisher' ); ?></th><td><code><?php echo esc_html( $this->storage->publishedRoot() ); ?></code></td></tr>
				</tbody>
			</table>

			<p>
				<?php $this->actionButton( 'swpp_full_build', __( 'Queue full preview build', 'static-wp-publisher' ), 'button button-primary' ); ?>
				<?php $this->actionButton( 'swpp_process_now', __( 'Process next batch', 'static-wp-publisher' ) ); ?>
				<?php $this->actionButton( 'swpp_toggle_serving', $enabled ? __( 'Return to dynamic WordPress', 'static-wp-publisher' ) : __( 'Publish generated HTML', 'static-wp-publisher' ), $enabled ? 'button' : 'button button-secondary' ); ?>
			</p>

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
		$this->redirect( sprintf( __( '%d URL(s) added to the publication queue.', 'static-wp-publisher' ), $count ) );
	}

	public function processNow(): void {
		$this->authorize( 'swpp_process_now' );
		$job = $this->queue->claim();
		if ( null === $job ) {
			$this->redirect( __( 'The queue is empty.', 'static-wp-publisher' ) );
		}
		$result = $this->publisher->publish( $job->url );
		if ( $result->success ) {
			$this->queue->complete( $job->id );
		} else {
			$this->queue->fail( $job->id, $result->message );
		}
		$this->redirect( $result->message );
	}

	public function toggleServing(): void {
		$this->authorize( 'swpp_toggle_serving' );
		$settings            = get_option( 'swpp_settings', array() );
		$settings['enabled'] = empty( $settings['enabled'] );
		update_option( 'swpp_settings', $settings, false );
		$this->redirect( $settings['enabled'] ? __( 'Static serving published.', 'static-wp-publisher' ) : __( 'Dynamic WordPress restored.', 'static-wp-publisher' ) );
	}

	private function actionButton( string $action, string $label, string $class = 'button' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
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
