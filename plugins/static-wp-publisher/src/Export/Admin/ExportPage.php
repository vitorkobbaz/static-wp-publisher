<?php
/**
 * Export administration screen.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Export\Admin;

use InvalidArgumentException;
use SWPP\Core\Export\Application\Exporter;
use SWPP\Core\Export\Domain\ExportRequest;
use SWPP\Core\Export\Domain\ExportResult;
use SWPP\Core\Export\ExportModule;

final class ExportPage {
	private const PRO_PANEL_META = 'swpp_pro_panel_dismissed';
	private const PRO_URL        = 'https://static-wp-publisher.vitorkobbaz.com/pro/';

	public function __construct(
		private readonly Exporter $exporter,
		private readonly ExportDownload $download,
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_swpp_create_export', array( $this, 'create' ) );
		add_action( 'admin_post_swpp_dismiss_pro_panel', array( $this, 'dismissProPanel' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'static-wp-publisher',
			__( 'Portable Export', 'static-wp-publisher' ),
			__( 'Portable Export', 'static-wp-publisher' ),
			'manage_options',
			'static-wp-publisher-export',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'static-wp-publisher' ) );
		}
		$result = get_transient( 'swpp_export_result_' . get_current_user_id() );
		delete_transient( 'swpp_export_result_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Portable Export', 'static-wp-publisher' ); ?></h1>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice <?php echo esc_attr( ! empty( $result['success'] ) ? 'notice-success' : 'notice-error' ); ?>">
					<p><?php echo esc_html( (string) $result['message'] ); ?></p>
				</div>
				<?php if ( ! empty( $result['directory'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Directory:', 'static-wp-publisher' ); ?></strong> <code><?php echo esc_html( (string) $result['directory'] ); ?></code></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['download_token'] ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( $this->download->url( (string) $result['download_token'] ) ); ?>"><?php esc_html_e( 'Download ZIP', 'static-wp-publisher' ); ?></a></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['deployments'] ) ) : ?>
					<ul>
						<?php foreach ( $result['deployments'] as $deployment ) : ?>
							<li><?php echo esc_html( (string) $deployment ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $result['warnings'] ) ) : ?>
					<details>
						<summary><?php esc_html_e( 'Dynamic limitations detected', 'static-wp-publisher' ); ?></summary>
						<ul>
							<?php foreach ( $result['warnings'] as $warning ) : ?>
								<li><?php echo esc_html( (string) $warning ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="swpp_create_export">
				<?php wp_nonce_field( 'swpp_create_export' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Mode', 'static-wp-publisher' ); ?></th><td><label><input type="radio" name="mode" value="relocatable" checked> <?php esc_html_e( 'Relocatable package', 'static-wp-publisher' ); ?></label><br><label><input type="radio" name="mode" value="publishable"> <?php esc_html_e( 'Publishable target', 'static-wp-publisher' ); ?></label></td></tr>
					<tr><th scope="row"><label for="swpp-target-base"><?php esc_html_e( 'Target base URL', 'static-wp-publisher' ); ?></label></th><td><input class="regular-text code" id="swpp-target-base" name="target_base" type="url" placeholder="https://example.com/subdirectory/"><p class="description"><?php esc_html_e( 'Required for a publishable package. Leaving this blank creates a relocatable package and triggers portability warnings.', 'static-wp-publisher' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Relocatable safeguard', 'static-wp-publisher' ); ?></th><td><label><input type="checkbox" name="noindex" value="1" checked> <?php esc_html_e( 'Add noindex to relocatable HTML (recommended)', 'static-wp-publisher' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Package', 'static-wp-publisher' ); ?></th><td><label><input type="checkbox" name="create_zip" value="1" checked> <?php esc_html_e( 'Create ZIP in addition to the export directory', 'static-wp-publisher' ); ?></label></td></tr>
					<?php $targets = ExportModule::deployTargets(); ?>
					<?php if ( array() !== $targets ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Deploy to', 'static-wp-publisher' ); ?></th><td>
							<?php foreach ( $targets as $target ) : ?>
								<label><input type="checkbox" name="deploy_targets[]" value="<?php echo esc_attr( $target->id() ); ?>"> <?php echo esc_html( $target->label() ); ?></label><br>
							<?php endforeach; ?>
						</td></tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Create portable export', 'static-wp-publisher' ) ); ?>
			</form>
			<?php $this->renderProPanel( array() !== $targets ); ?>
		</div>
		<?php
	}

	public function create(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'static-wp-publisher' ) );
		}
		check_admin_referer( 'swpp_create_export' );
		$target = esc_url_raw( wp_unslash( (string) ( $_POST['target_base'] ?? '' ) ) );
		$mode   = sanitize_key( wp_unslash( (string) ( $_POST['mode'] ?? '' ) ) );
		if ( '' === $target ) {
			$mode = 'relocatable';
		}
		try {
			$request = new ExportRequest( $mode, $target, ! empty( $_POST['noindex'] ), ! empty( $_POST['create_zip'] ) );
			$result  = $this->exporter->export( $request );
		} catch ( InvalidArgumentException $error ) {
			set_transient(
				'swpp_export_result_' . get_current_user_id(),
				array(
					'success' => false,
					'message' => $error->getMessage(),
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=static-wp-publisher-export' ) );
			exit;
		}
		$download_token = null;
		$message        = $result->message;
		$success        = $result->success;
		if ( $result->success && null !== $result->zip ) {
			$download_token = $this->download->authorize( $result->zip, get_current_user_id() );
			if ( null === $download_token ) {
				$success = false;
				$message = __( 'The export was created, but a secure download could not be prepared.', 'static-wp-publisher' );
			}
		}
		set_transient(
			'swpp_export_result_' . get_current_user_id(),
			array(
				'success'        => $success,
				'message'        => $message,
				'directory'      => $result->directory,
				'download_token' => $download_token,
				'deployments'    => $result->success ? $this->deploy( $result ) : array(),
				'warnings'       => $result->warnings,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=static-wp-publisher-export' ) );
		exit;
	}

	/**
	 * Runs the deployment targets the administrator selected for this export.
	 *
	 * @return list<string> One line per target.
	 */
	private function deploy( ExportResult $result ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in create().
		$selected = isset( $_POST['deploy_targets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['deploy_targets'] ) ) : array();
		$lines    = array();
		foreach ( ExportModule::deployTargets() as $id => $target ) {
			if ( ! in_array( $id, $selected, true ) ) {
				continue;
			}
			try {
				$outcome = $target->deploy( $result );
				$lines[] = $target->label() . ': ' . ( $outcome['success'] ? '' : __( 'failed', 'static-wp-publisher' ) . ' — ' ) . $outcome['message'];
			} catch ( \Throwable $error ) {
				$lines[] = $target->label() . ': ' . __( 'failed', 'static-wp-publisher' ) . ' — ' . $error->getMessage();
			}
		}
		return $lines;
	}

	/**
	 * The only place the free plugin mentions Pro: a small panel on this screen that each
	 * administrator can dismiss for good. Hidden once a deployment add-on is active.
	 */
	private function renderProPanel( bool $hasTargets ): void {
		if ( $hasTargets || get_user_meta( get_current_user_id(), self::PRO_PANEL_META, true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=swpp_dismiss_pro_panel' ), 'swpp_dismiss_pro_panel' );
		?>
		<div class="card" style="max-width:640px">
			<h2><?php esc_html_e( 'Publish automatically', 'static-wp-publisher' ); ?></h2>
			<p><?php esc_html_e( 'Static WP Publisher Pro deploys every change to SFTP, S3-compatible storage or Netlify, sending only the pages that changed.', 'static-wp-publisher' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( self::PRO_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn about Pro', 'static-wp-publisher' ); ?></a>
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'static-wp-publisher' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function dismissProPanel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'static-wp-publisher' ) );
		}
		check_admin_referer( 'swpp_dismiss_pro_panel' );
		update_user_meta( get_current_user_id(), self::PRO_PANEL_META, 1 );
		wp_safe_redirect( admin_url( 'admin.php?page=static-wp-publisher-export' ) );
		exit;
	}
}
