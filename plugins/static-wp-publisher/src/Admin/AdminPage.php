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
use SWPP\Core\Application\StatusReport;
use SWPP\Core\Application\Worker;
use SWPP\Core\Domain\PageStatus;
use SWPP\Core\Infrastructure\Storage;
use SWPP\Core\Infrastructure\Verifier;

final class AdminPage {
	private const SLUG = 'static-wp-publisher';

	private string $hook = '';

	public function __construct(
		private readonly Queue $queue,
		private readonly Publisher $publisher,
		private readonly Inventory $inventory,
		private readonly Storage $storage,
		private readonly StatusReport $report,
		private readonly Verifier $verifier,
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_swpp_generate_all', array( $this, 'generateAll' ) );
		add_action( 'admin_post_swpp_regenerate', array( $this, 'regenerate' ) );
		add_action( 'admin_post_swpp_verify', array( $this, 'verify' ) );
		add_action( 'admin_post_swpp_toggle_serving', array( $this, 'toggleServing' ) );
	}

	public function menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Static WP Publisher', 'static-wp-publisher' ),
			__( 'Static Publisher', 'static-wp-publisher' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-performance',
			58
		);
	}

	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$base = plugins_url( 'assets/', SWPP_FILE );
		wp_enqueue_style( 'swpp-admin', $base . 'admin.css', array(), SWPP_VERSION );
		wp_enqueue_script( 'swpp-admin', $base . 'admin.js', array(), SWPP_VERSION, true );
		wp_localize_script(
			'swpp-admin',
			'swppAdmin',
			array(
				'root'    => esc_url_raw( rest_url( 'swpp/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'pageUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
				'i18n'    => array(
					/* translators: 1: pages published, 2: pages not publishable, 3: errors, 4: pages still waiting. */
					'progress' => __( 'Working… %1$d published, %2$d not publishable, %3$d errors, %4$d waiting.', 'static-wp-publisher' ),
					/* translators: 1: pages published, 2: pages not publishable, 3: errors. */
					'done'     => __( 'Generation finished: %1$d published, %2$d not publishable, %3$d errors.', 'static-wp-publisher' ),
					/* translators: 1: pages published, 2: pages waiting to retry. */
					'retrying' => __( 'Generation paused: %1$d published; %2$d page(s) will retry automatically after an error.', 'static-wp-publisher' ),
					'failed'   => __( 'Generation stopped because the server returned an error. Reload the page and try again.', 'static-wp-publisher' ),
					'starting' => __( 'Listing the pages of your site…', 'static-wp-publisher' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage static publication.', 'static-wp-publisher' ) );
		}

		$settings = get_option( 'swpp_settings', array() );
		$enabled  = is_array( $settings ) && ! empty( $settings['enabled'] );
		$summary  = $this->report->summary();
		$paged    = isset( $_GET['swpp_page'] ) ? max( 1, absint( $_GET['swpp_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$listing  = $this->report->rows( $paged );
		?>
		<div class="wrap swpp-admin">
			<h1><?php esc_html_e( 'Static WP Publisher', 'static-wp-publisher' ); ?></h1>
			<?php $this->renderNotice(); ?>

			<div class="swpp-banner <?php echo $enabled ? 'swpp-banner--on' : 'swpp-banner--off'; ?>">
				<div>
					<p class="swpp-banner__title">
						<?php echo $enabled ? esc_html__( 'Static serving is ON', 'static-wp-publisher' ) : esc_html__( 'Static serving is OFF', 'static-wp-publisher' ); ?>
					</p>
					<p>
						<?php
						echo $enabled
							? esc_html__( 'Visitors who are not logged in receive the generated HTML. Logged-in users, search, forms, and pages without a static copy keep using WordPress.', 'static-wp-publisher' )
							: esc_html__( 'Everyone receives normal WordPress pages. Generate the static copies, then turn static serving on.', 'static-wp-publisher' );
						?>
					</p>
				</div>
				<?php $this->actionButton( 'swpp_toggle_serving', $enabled ? __( 'Turn static serving off', 'static-wp-publisher' ) : __( 'Turn static serving on', 'static-wp-publisher' ), $enabled ? 'button' : 'button button-primary' ); ?>
			</div>

			<div class="swpp-cards">
				<?php
				$this->card(
					__( 'Static copies', 'static-wp-publisher' ),
					$summary['static'],
					/* translators: %d: number of public pages and posts. */
					sprintf( _n( '%d public page or post on this site.', '%d public pages and posts on this site.', $summary['content'], 'static-wp-publisher' ), $summary['content'] ),
					'good'
				);
				$this->card(
					__( 'Waiting', 'static-wp-publisher' ),
					$summary['queued'] + $summary['running'] + $summary['retrying'],
					/* translators: %d: number of pages waiting to retry. */
					sprintf( __( '%d retrying after an error.', 'static-wp-publisher' ), $summary['retrying'] ),
					$summary['retrying'] > 0 ? 'warn' : 'neutral'
				);
				$this->card(
					__( 'Served by WordPress', 'static-wp-publisher' ),
					$summary['skipped'],
					/* translators: %d: number of password-protected pages. */
					sprintf( __( 'Not publishable as static HTML (%d password protected). They keep working normally.', 'static-wp-publisher' ), $summary['protected'] ),
					'neutral'
				);
				$this->card(
					__( 'Errors', 'static-wp-publisher' ),
					$summary['failed'],
					__( 'Pages that could not be generated after several attempts.', 'static-wp-publisher' ),
					$summary['failed'] > 0 ? 'bad' : 'neutral'
				);
				?>
			</div>

			<div class="swpp-generate">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-generate>
					<input type="hidden" name="action" value="swpp_generate_all">
					<?php wp_nonce_field( 'swpp_generate_all' ); ?>
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Generate all pages now', 'static-wp-publisher' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'Creates or refreshes the static copy of every public page. Pages also update automatically in the background when you edit content.', 'static-wp-publisher' ); ?></p>
				<div class="swpp-progress" data-swpp-progress hidden>
					<progress data-swpp-progress-bar></progress>
					<p data-swpp-progress-text role="status" aria-live="polite"></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Pages and posts', 'static-wp-publisher' ); ?></h2>
			<p class="description"><?php esc_html_e( '"Check" loads the page as an anonymous visitor and tells you whether it was delivered from the static copy.', 'static-wp-publisher' ); ?></p>
			<?php $this->renderRows( $listing['rows'], $enabled ); ?>
			<?php $this->renderPagination( $paged, $listing['pages'] ); ?>

			<?php $this->renderProblems(); ?>

			<details class="swpp-details">
				<summary><?php esc_html_e( 'Technical details', 'static-wp-publisher' ); ?></summary>
				<?php $this->renderTechnical(); ?>
			</details>

			<?php do_action( 'swpp_admin_after_overview', $this ); ?>
		</div>
		<?php
	}

	/** No-JavaScript fallback: queue everything and process one budgeted pass. */
	public function generateAll(): void {
		$this->authorize( 'swpp_generate_all' );
		$this->inventory->enqueueAll();
		$report  = ( new Worker( $this->queue, $this->publisher ) )->runPass( $this->inventory, Worker::requestBudget() );
		$pending = $this->queue->counts()['pending'];
		$notice  = sprintf(
			/* translators: 1: published, 2: not publishable, 3: errors, 4: still waiting. */
			__( '%1$d published, %2$d not publishable, %3$d errors. %4$d page(s) still waiting; click again to continue or let the background worker finish.', 'static-wp-publisher' ),
			$report->succeeded,
			$report->skipped,
			$report->failed,
			$pending
		);
		$this->redirect( $notice, $report->failed > 0 ? 'warning' : 'success' );
	}

	public function regenerate(): void {
		$this->authorize( 'swpp_regenerate' );
		$url    = $this->requestedUrl();
		$result = ( new Worker( $this->queue, $this->publisher ) )->runOne( $url );
		if ( null === $result ) {
			$this->redirect( __( 'This page is already being generated. Try again in a moment.', 'static-wp-publisher' ), 'warning' );
		}
		if ( $result->success ) {
			/* translators: %s: page URL. */
			$this->redirect( sprintf( __( 'Static copy of %s updated.', 'static-wp-publisher' ), $url ) );
		}
		/* translators: 1: page URL, 2: reason. */
		$message = sprintf( __( '%1$s was not published: %2$s', 'static-wp-publisher' ), $url, $result->message );
		if ( ! $result->retryable ) {
			$message .= ' ' . __( 'It will keep being served by WordPress.', 'static-wp-publisher' );
		}
		$this->redirect( $message, $result->retryable ? 'error' : 'warning' );
	}

	public function verify(): void {
		$this->authorize( 'swpp_verify' );
		$url   = $this->requestedUrl();
		$check = $this->verifier->check( $url );
		if ( ! $check['ok'] ) {
			/* translators: 1: page URL, 2: error message. */
			$this->redirect( sprintf( __( 'Could not load %1$s: %2$s', 'static-wp-publisher' ), $url, $check['error'] ), 'error' );
		}
		if ( $check['static'] ) {
			/* translators: 1: page URL, 2: HTTP status code, 3: response time in milliseconds. */
			$this->redirect( sprintf( __( 'Confirmed: %1$s is delivered from the static copy (HTTP %2$d, %3$d ms).', 'static-wp-publisher' ), $url, $check['status'], $check['milliseconds'] ) );
		}
		/* translators: 1: page URL, 2: HTTP status code, 3: response time in milliseconds. */
		$this->redirect( sprintf( __( '%1$s is delivered by WordPress, not from a static copy (HTTP %2$d, %3$d ms). Check that static serving is on and that the page has a static copy.', 'static-wp-publisher' ), $url, $check['status'], $check['milliseconds'] ), 'warning' );
	}

	public function toggleServing(): void {
		$this->authorize( 'swpp_toggle_serving' );
		$settings            = get_option( 'swpp_settings', array() );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = empty( $settings['enabled'] );
		update_option( 'swpp_settings', $settings, false );
		$this->redirect( $settings['enabled'] ? __( 'Static serving is on.', 'static-wp-publisher' ) : __( 'Static serving is off. Everyone receives normal WordPress pages.', 'static-wp-publisher' ) );
	}

	private function card( string $label, int $value, string $hint, string $tone ): void {
		?>
		<div class="swpp-card swpp-card--<?php echo esc_attr( $tone ); ?>">
			<p class="swpp-card__label"><?php echo esc_html( $label ); ?></p>
			<p class="swpp-card__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></p>
			<p class="swpp-card__hint"><?php echo esc_html( $hint ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param list<array{post_id:int,title:string,type:string,url:string,status:PageStatus,published_at:string,bytes:int}> $rows
	 */
	private function renderRows( array $rows, bool $enabled ): void {
		?>
		<table class="widefat striped swpp-pages">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Static copy updated', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'static-wp-publisher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No public pages found.', 'static-wp-publisher' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php list( $label, $tone ) = $this->statusLabel( $row['status'], $enabled ); ?>
					<tr data-swpp-url="<?php echo esc_attr( $row['url'] ); ?>">
						<td>
							<strong><?php echo esc_html( $row['title'] ); ?></strong>
							<span class="swpp-muted"><?php echo esc_html( $row['type'] ); ?></span><br>
							<code class="swpp-url"><?php echo esc_html( $row['url'] ); ?></code>
						</td>
						<td>
							<span class="swpp-badge swpp-badge--<?php echo esc_attr( $tone ); ?>" data-swpp-status="<?php echo esc_attr( $row['status']->key ); ?>"><?php echo esc_html( $label ); ?></span>
							<?php if ( '' !== $row['status']->detail ) : ?>
								<br><span class="swpp-muted"><?php echo esc_html( $row['status']->detail ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo '' !== $row['published_at'] ? esc_html( $this->localTime( $row['published_at'] ) ) : '&mdash;'; ?></td>
						<td><?php echo $row['bytes'] > 0 ? esc_html( (string) size_format( $row['bytes'] ) ) : '&mdash;'; ?></td>
						<td class="swpp-actions">
							<a class="button button-small" href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'static-wp-publisher' ); ?></a>
							<?php $this->rowButton( 'swpp_regenerate', $row['post_id'], __( 'Regenerate', 'static-wp-publisher' ) ); ?>
							<?php $this->rowButton( 'swpp_verify', $row['post_id'], __( 'Check', 'static-wp-publisher' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @return array{0:string,1:string} Label and tone. */
	private function statusLabel( PageStatus $status, bool $enabled ): array {
		return match ( $status->key ) {
			PageStatus::STATIC_COPY => array( $enabled ? __( 'Static', 'static-wp-publisher' ) : __( 'Static copy ready', 'static-wp-publisher' ), 'good' ),
			PageStatus::UPDATING    => array( __( 'Static · update queued', 'static-wp-publisher' ), 'good' ),
			PageStatus::STALE       => array( __( 'Static · last update failed', 'static-wp-publisher' ), 'warn' ),
			PageStatus::EXPOSED     => array( __( 'Protected page still public — regenerate', 'static-wp-publisher' ), 'bad' ),
			PageStatus::QUEUED      => array( __( 'Queued', 'static-wp-publisher' ), 'neutral' ),
			PageStatus::GENERATING  => array( __( 'Generating', 'static-wp-publisher' ), 'info' ),
			PageStatus::RETRYING    => array( __( 'Retrying after an error', 'static-wp-publisher' ), 'warn' ),
			PageStatus::DYNAMIC     => array( __( 'Served by WordPress', 'static-wp-publisher' ), 'neutral' ),
			PageStatus::ERROR       => array( __( 'Error', 'static-wp-publisher' ), 'bad' ),
			default                 => array( __( 'Not generated yet', 'static-wp-publisher' ), 'neutral' ),
		};
	}

	private function renderPagination( int $paged, int $pages ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'swpp_page', '%#%', admin_url( 'admin.php?page=' . self::SLUG ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		if ( '' !== $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	private function renderProblems(): void {
		$problems = $this->queue->problems();
		if ( array() === $problems ) {
			return;
		}
		$labels = array(
			'pending' => __( 'Retrying', 'static-wp-publisher' ),
			'failed'  => __( 'Error', 'static-wp-publisher' ),
			'skipped' => __( 'Served by WordPress', 'static-wp-publisher' ),
		);
		?>
		<h2><?php esc_html_e( 'Needs attention', 'static-wp-publisher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Latest result for every address that could not be published, including archive pages.', 'static-wp-publisher' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Address', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next attempt', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'static-wp-publisher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $problems as $problem ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $problem['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $problem['url'] ); ?></a></td>
						<td><?php echo esc_html( $labels[ $problem['status'] ] ?? $problem['status'] ); ?></td>
						<td><?php echo 'pending' === $problem['status'] ? esc_html( $this->localTime( $problem['available_at'] ) ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( $problem['last_error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function renderTechnical(): void {
		$counts = $this->queue->counts();
		$next   = wp_next_scheduled( 'swpp_process_queue' );
		?>
		<table class="widefat striped swpp-technical">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Published directory', 'static-wp-publisher' ); ?></th><td><code><?php echo esc_html( $this->storage->publishedRoot() ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Next background run', 'static-wp-publisher' ); ?></th><td><?php echo false !== $next ? esc_html( (string) wp_date( 'Y-m-d H:i:s', $next ) ) : esc_html__( 'Not scheduled — configure WP-Cron or a real scheduler.', 'static-wp-publisher' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Job history', 'static-wp-publisher' ); ?></th><td><?php echo esc_html( sprintf( 'pending %d · running %d · failed %d · skipped %d · succeeded %d', $counts['pending'], $counts['running'], $counts['failed'], $counts['skipped'], $counts['succeeded'] ) ); ?></td></tr>
			</tbody>
		</table>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'The static copy is currently delivered by WordPress itself (PHP fallback). Web-server rules for maximum speed are planned.', 'static-wp-publisher' ); ?></li>
			<li><?php esc_html_e( 'Search, forms, comments, login, carts, checkout, accounts, and personalized pages always use WordPress.', 'static-wp-publisher' ); ?></li>
			<li><?php esc_html_e( 'WP-Cron can be late on low-traffic sites. Configure a real scheduler for precise background updates.', 'static-wp-publisher' ); ?></li>
		</ul>
		<?php
	}

	private function renderNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only redirect parameters.
		if ( ! isset( $_GET['swpp_notice'] ) ) {
			return;
		}
		$notice = sanitize_text_field( wp_unslash( $_GET['swpp_notice'] ) );
		$level  = isset( $_GET['swpp_level'] ) ? sanitize_key( wp_unslash( $_GET['swpp_level'] ) ) : 'success';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$level = in_array( $level, array( 'success', 'warning', 'error', 'info' ), true ) ? $level : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $level ); ?> is-dismissible" data-swpp-notice><p><?php echo esc_html( $notice ); ?></p></div>
		<?php
	}

	private function localTime( string $utc_mysql ): string {
		$timestamp = strtotime( $utc_mysql . ' UTC' );
		return false === $timestamp ? $utc_mysql : (string) wp_date( 'Y-m-d H:i', $timestamp );
	}

	private function requestedUrl(): string {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$url     = $post_id >= 0 ? $this->report->urlForContent( $post_id ) : null;
		if ( null === $url ) {
			$this->redirect( __( 'That page is not public content of this site.', 'static-wp-publisher' ), 'error' );
		}
		return $url;
	}

	private function rowButton( string $action, int $post_id, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<?php wp_nonce_field( $action, '_wpnonce', false ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function actionButton( string $action, string $label, string $css_class = 'button' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swpp-inline-form">
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

	private function redirect( string $notice, string $level = 'success' ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'swpp_notice' => rawurlencode( $notice ),
					'swpp_level'  => $level,
				),
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
	}
}
