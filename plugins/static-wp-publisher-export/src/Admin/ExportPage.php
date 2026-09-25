<?php
/**
 * Export administration screen.
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

namespace SWPP\Export\Admin;

use InvalidArgumentException;
use SWPP\Export\Application\Exporter;
use SWPP\Export\Domain\ExportRequest;
use SWPP\Export\Infrastructure\Entitlement;

final class ExportPage {
	public function __construct(
		private readonly Exporter $exporter,
		private readonly Entitlement $entitlement,
		private readonly ExportDownload $download,
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_swpp_create_export', array( $this, 'create' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'static-wp-publisher',
			__( 'Portable Export', 'static-wp-publisher-export' ),
			__( 'Portable Export', 'static-wp-publisher-export' ),
			'manage_options',
			'static-wp-publisher-export',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'static-wp-publisher-export' ) );
		}
		$result = get_transient( 'swpp_export_result_' . get_current_user_id() );
		delete_transient( 'swpp_export_result_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Portable Export', 'static-wp-publisher-export' ); ?></h1>
			<?php if ( ! $this->entitlement->isAllowed() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'A valid Individual Export entitlement is required. Development environments may set SWPP_EXPORT_DEV_MODE.', 'static-wp-publisher-export' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice <?php echo esc_attr( ! empty( $result['success'] ) ? 'notice-success' : 'notice-error' ); ?>">
					<p><?php echo esc_html( (string) $result['message'] ); ?></p>
				</div>
				<?php if ( ! empty( $result['directory'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Directory:', 'static-wp-publisher-export' ); ?></strong> <code><?php echo esc_html( (string) $result['directory'] ); ?></code></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['download_token'] ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( $this->download->url( (string) $result['download_token'] ) ); ?>"><?php esc_html_e( 'Download ZIP', 'static-wp-publisher-export' ); ?></a></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['warnings'] ) ) : ?>
					<details>
						<summary><?php esc_html_e( 'Dynamic limitations detected', 'static-wp-publisher-export' ); ?></summary>
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
					<tr><th scope="row"><?php esc_html_e( 'Mode', 'static-wp-publisher-export' ); ?></th><td><label><input type="radio" name="mode" value="relocatable" checked> <?php esc_html_e( 'Relocatable package', 'static-wp-publisher-export' ); ?></label><br><label><input type="radio" name="mode" value="publishable"> <?php esc_html_e( 'Publishable target', 'static-wp-publisher-export' ); ?></label></td></tr>
					<tr><th scope="row"><label for="swpp-target-base"><?php esc_html_e( 'Target base URL', 'static-wp-publisher-export' ); ?></label></th><td><input class="regular-text code" id="swpp-target-base" name="target_base" type="url" placeholder="https://example.com/subdirectory/"><p class="description"><?php esc_html_e( 'Required for a publishable package. Leaving this blank creates a relocatable package and triggers portability warnings.', 'static-wp-publisher-export' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Relocatable safeguard', 'static-wp-publisher-export' ); ?></th><td><label><input type="checkbox" name="noindex" value="1" checked> <?php esc_html_e( 'Add noindex to relocatable HTML (recommended)', 'static-wp-publisher-export' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Package', 'static-wp-publisher-export' ); ?></th><td><label><input type="checkbox" name="create_zip" value="1" checked> <?php esc_html_e( 'Create ZIP in addition to the export directory', 'static-wp-publisher-export' ); ?></label></td></tr>
				</table>
				<?php
				submit_button(
					__( 'Create portable export', 'static-wp-publisher-export' ),
					'primary',
					'submit',
					true,
					$this->entitlement->isAllowed() ? array() : array( 'disabled' => 'disabled' )
				);
				?>
			</form>
		</div>
		<?php
	}

	public function create(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this site.', 'static-wp-publisher-export' ) );
		}
		check_admin_referer( 'swpp_create_export' );
		if ( ! $this->entitlement->isAllowed() ) {
			wp_die( esc_html__( 'A valid export entitlement is required.', 'static-wp-publisher-export' ) );
		}
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
				$message = __( 'The export was created, but a secure download could not be prepared.', 'static-wp-publisher-export' );
			}
		}
		set_transient(
			'swpp_export_result_' . get_current_user_id(),
			array(
				'success'        => $success,
				'message'        => $message,
				'directory'      => $result->directory,
				'download_token' => $download_token,
				'warnings'       => $result->warnings,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=static-wp-publisher-export' ) );
		exit;
	}
}
