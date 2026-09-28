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
	private const SLUG     = 'static-wp-publisher';
	private const BULK_MAX = 50;

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
		add_action( 'admin_post_swpp_process_pending', array( $this, 'processPending' ) );
		add_action( 'admin_post_swpp_' . PageActions::REGENERATE, array( $this, 'regenerate' ) );
		add_action( 'admin_post_swpp_' . PageActions::VERIFY, array( $this, 'verify' ) );
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
		add_action( 'load-' . $this->hook, array( $this, 'handleBulkAction' ) );
	}

	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$base   = plugins_url( 'assets/', SWPP_FILE );
		$counts = $this->queue->latestCounts();
		wp_enqueue_style( 'swpp-admin', $base . 'admin.css', array( 'dashicons' ), SWPP_VERSION );
		wp_enqueue_script( 'swpp-admin', $base . 'admin.js', array(), SWPP_VERSION, true );
		wp_localize_script(
			'swpp-admin',
			'swppAdmin',
			array(
				'root'       => esc_url_raw( rest_url( 'swpp/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'pageUrl'    => $this->pageUrl(),
				'inProgress' => $counts['queued'] + $counts['running'],
				'i18n'       => array(
					/* translators: 1: pages published, 2: pages not publishable, 3: errors, 4: pages still waiting. */
					'progress'     => __( 'Working… %1$d published, %2$d not publishable, %3$d errors, %4$d waiting.', 'static-wp-publisher' ),
					/* translators: 1: pages published, 2: pages not publishable, 3: errors. */
					'done'         => __( 'Finished: %1$d published, %2$d not publishable, %3$d errors.', 'static-wp-publisher' ),
					/* translators: 1: pages published, 2: pages waiting to retry. */
					'retrying'     => __( 'Paused: %1$d published; %2$d page(s) will retry automatically after an error.', 'static-wp-publisher' ),
					'failed'       => __( 'Stopped because the server returned an error. Reload the page and try again.', 'static-wp-publisher' ),
					'starting'     => __( 'Listing the pages of your site…', 'static-wp-publisher' ),
					/* translators: 1: items done, 2: items selected. */
					'bulkProgress' => __( 'Working on %1$d of %2$d selected pages…', 'static-wp-publisher' ),
					/* translators: 1: pages that succeeded, 2: pages with a warning, 3: pages that failed. */
					'bulkDone'     => __( 'Done: %1$d OK, %2$d with warnings, %3$d failed. Reload the page to refresh the counters.', 'static-wp-publisher' ),
					'noSelection'  => __( 'Select at least one page first.', 'static-wp-publisher' ),
					'working'      => __( 'Working…', 'static-wp-publisher' ),
					'requestError' => __( 'The request failed. Reload the page and try again.', 'static-wp-publisher' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage static publication.', 'static-wp-publisher' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$presenter = StatusPresenter::forCurrentSettings();
		$table     = new PagesTable( $this->report, $presenter, $this->pageUrl() );
		$table->prepare_items();
		$listing = $table->listing();
		$enabled = $presenter->servingEnabled();
		?>
		<div class="wrap swpp-admin">
			<h1><?php esc_html_e( 'Static WP Publisher', 'static-wp-publisher' ); ?></h1>
			<?php $this->renderNotice(); ?>

			<div class="swpp-banner <?php echo $enabled ? 'swpp-banner--on' : 'swpp-banner--off'; ?>">
				<div>
					<p class="swpp-banner__title">
						<span class="dashicons <?php echo $enabled ? 'dashicons-yes-alt' : 'dashicons-controls-pause'; ?>" aria-hidden="true"></span>
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

			<?php $this->renderCards( $listing ); ?>

			<div class="swpp-generate">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-generate="build" class="swpp-inline-form">
					<input type="hidden" name="action" value="swpp_generate_all">
					<?php wp_nonce_field( 'swpp_generate_all' ); ?>
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Generate all pages now', 'static-wp-publisher' ); ?></button>
				</form>
				<?php if ( $listing['counts'][ PageStatus::GROUP_PENDING ] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-generate="process" class="swpp-inline-form">
						<input type="hidden" name="action" value="swpp_process_pending">
						<?php wp_nonce_field( 'swpp_process_pending' ); ?>
						<button type="submit" class="button button-large"><?php esc_html_e( 'Process pending now', 'static-wp-publisher' ); ?></button>
					</form>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Pages update automatically in the background (about once a minute) whenever you edit content. Use these buttons to do it right away.', 'static-wp-publisher' ); ?></p>
				<div class="swpp-progress" data-swpp-progress hidden>
					<progress data-swpp-progress-bar></progress>
					<p data-swpp-progress-text role="status" aria-live="polite"></p>
				</div>
				<p class="swpp-live" data-swpp-live hidden><span class="spinner is-active" aria-hidden="true"></span><?php esc_html_e( 'Pages are being updated in the background. This list refreshes automatically when they finish.', 'static-wp-publisher' ); ?></p>
			</div>

			<form method="get" data-swpp-table>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="swpp_view" value="<?php echo esc_attr( PagesTable::currentView() ); ?>">
				<?php $table->views(); ?>
				<?php $table->search_box( __( 'Search pages', 'static-wp-publisher' ), 'swpp-search' ); ?>
				<?php $table->display(); ?>
			</form>
			<?php if ( $listing['truncated'] ) : ?>
				<p class="description">
					<?php
					/* translators: %d: maximum number of pages analysed. */
					echo esc_html( sprintf( __( 'Showing the %d most recently modified pages. Counters and filters apply to these.', 'static-wp-publisher' ), $listing['limit'] ) );
					?>
				</p>
			<?php endif; ?>

			<?php $this->renderOtherProblems( $listing ); ?>

			<details class="swpp-details">
				<summary><?php esc_html_e( 'Technical details', 'static-wp-publisher' ); ?></summary>
				<?php $this->renderTechnical(); ?>
			</details>

			<?php do_action( 'swpp_admin_after_overview', $this ); ?>
		</div>
		<?php
	}

	/** No-JavaScript fallback for the bulk actions of the list table. */
	public function handleBulkAction(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below before acting.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-1';
		if ( '-1' === $action && isset( $_REQUEST['action2'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		$map = array(
			'swpp-' . PageActions::REGENERATE => PageActions::REGENERATE,
			'swpp-' . PageActions::VERIFY     => PageActions::VERIFY,
		);
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}
		$this->authorize( 'bulk-swpp-pages' );
		$ids = isset( $_REQUEST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['post_ids'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, self::BULK_MAX );
		if ( array() === $ids ) {
			$this->redirect( __( 'Select at least one page first.', 'static-wp-publisher' ), 'warning' );
		}

		$actions = $this->pageActions();
		$tally   = array(
			'ok'   => 0,
			'warn' => 0,
			'fail' => 0,
		);
		foreach ( $ids as $post_id ) {
			$result = $actions->run( $map[ $action ], $post_id );
			if ( null === $result || ! $result['ok'] ) {
				++$tally['fail'];
			} elseif ( 'success' === $result['level'] ) {
				++$tally['ok'];
			} else {
				++$tally['warn'];
			}
		}
		$this->redirect(
			/* translators: 1: pages that succeeded, 2: pages with a warning, 3: pages that failed. */
			sprintf( __( 'Done: %1$d OK, %2$d with warnings, %3$d failed.', 'static-wp-publisher' ), $tally['ok'], $tally['warn'], $tally['fail'] ),
			$tally['fail'] > 0 ? 'warning' : 'success'
		);
	}

	/** No-JavaScript fallback: queue everything and process one budgeted pass. */
	public function generateAll(): void {
		$this->authorize( 'swpp_generate_all' );
		$this->inventory->enqueueAll();
		$this->finishPass();
	}

	/** No-JavaScript fallback: process one budgeted pass of what is already queued. */
	public function processPending(): void {
		$this->authorize( 'swpp_process_pending' );
		$this->finishPass();
	}

	public function regenerate(): void {
		$this->singleAction( PageActions::REGENERATE );
	}

	public function verify(): void {
		$this->singleAction( PageActions::VERIFY );
	}

	public function toggleServing(): void {
		$this->authorize( 'swpp_toggle_serving' );
		$settings            = get_option( 'swpp_settings', array() );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = empty( $settings['enabled'] );
		update_option( 'swpp_settings', $settings, false );
		$this->redirect( $settings['enabled'] ? __( 'Static serving is on.', 'static-wp-publisher' ) : __( 'Static serving is off. Everyone receives normal WordPress pages.', 'static-wp-publisher' ) );
	}

	private function singleAction( string $action ): void {
		$this->authorize( 'swpp_' . $action );
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in authorize().
		$result  = $post_id >= 0 ? $this->pageActions()->run( $action, $post_id ) : null;
		if ( null === $result ) {
			$this->redirect( __( 'That page is not public content of this site.', 'static-wp-publisher' ), 'error' );
		}
		$url = $this->report->urlForContent( $post_id );
		$this->redirect( ( null !== $url ? $url . ' — ' : '' ) . $result['message'], $result['level'] );
	}

	private function finishPass(): void {
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

	/**
	 * @param array{counts:array<string,int>,covered:int,publishable:int} $listing
	 */
	private function renderCards( array $listing ): void {
		$counts  = $listing['counts'];
		$percent = $listing['publishable'] > 0 ? (int) floor( 100 * $listing['covered'] / $listing['publishable'] ) : 0;
		?>
		<div class="swpp-cards">
			<a class="swpp-card swpp-card--<?php echo $percent >= 100 ? 'good' : 'info'; ?>" href="<?php echo esc_url( add_query_arg( 'swpp_view', PageStatus::GROUP_STATIC, $this->pageUrl() ) ); ?>">
				<span class="swpp-card__label"><?php esc_html_e( 'Static coverage', 'static-wp-publisher' ); ?></span>
				<span class="swpp-card__value">
					<?php
					/* translators: 1: pages with a static copy, 2: pages that can be static. */
					echo esc_html( sprintf( __( '%1$s of %2$s', 'static-wp-publisher' ), number_format_i18n( $listing['covered'] ), number_format_i18n( $listing['publishable'] ) ) );
					?>
				</span>
				<progress class="swpp-card__meter" max="100" value="<?php echo esc_attr( (string) $percent ); ?>"><?php echo esc_html( $percent . '%' ); ?></progress>
				<span class="swpp-card__hint">
					<?php
					/* translators: %d: percentage of publishable pages that have a static copy. */
					echo esc_html( sprintf( __( '%d%% of the pages that can be static are delivered as HTML.', 'static-wp-publisher' ), $percent ) );
					?>
				</span>
			</a>
			<?php
			$this->card( PageStatus::GROUP_PENDING, __( 'Pending', 'static-wp-publisher' ), $counts[ PageStatus::GROUP_PENDING ], __( 'Queued, generating, or retrying.', 'static-wp-publisher' ), $counts[ PageStatus::GROUP_PENDING ] > 0 ? 'info' : 'neutral' );
			$this->card( PageStatus::GROUP_DYNAMIC, __( 'Served by WordPress', 'static-wp-publisher' ), $counts[ PageStatus::GROUP_DYNAMIC ], __( 'Password-protected or personalized pages. They keep working normally.', 'static-wp-publisher' ), 'neutral' );
			$this->card( PageStatus::GROUP_ATTENTION, __( 'Needs attention', 'static-wp-publisher' ), $counts[ PageStatus::GROUP_ATTENTION ], __( 'Errors, outdated copies, or protected pages still public.', 'static-wp-publisher' ), $counts[ PageStatus::GROUP_ATTENTION ] > 0 ? 'bad' : 'neutral' );
			?>
		</div>
		<?php
	}

	private function card( string $view, string $label, int $value, string $hint, string $tone ): void {
		?>
		<a class="swpp-card swpp-card--<?php echo esc_attr( $tone ); ?>" href="<?php echo esc_url( add_query_arg( 'swpp_view', $view, $this->pageUrl() ) ); ?>" data-swpp-card="<?php echo esc_attr( $view ); ?>">
			<span class="swpp-card__label"><?php echo esc_html( $label ); ?></span>
			<span class="swpp-card__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
			<span class="swpp-card__hint"><?php echo esc_html( $hint ); ?></span>
		</a>
		<?php
	}

	/**
	 * @param array{urls:list<string>} $listing
	 */
	private function renderOtherProblems( array $listing ): void {
		$problems = $this->report->otherProblems( $listing['urls'] );
		if ( array() === $problems ) {
			return;
		}
		$labels = array(
			'pending' => __( 'Retrying', 'static-wp-publisher' ),
			'failed'  => __( 'Error', 'static-wp-publisher' ),
			'skipped' => __( 'Served by WordPress', 'static-wp-publisher' ),
		);
		?>
		<h2><?php esc_html_e( 'Other addresses', 'static-wp-publisher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Archive and category pages whose latest generation did not succeed.', 'static-wp-publisher' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Address', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'static-wp-publisher' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'static-wp-publisher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $problems as $problem ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $problem['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $problem['url'] ); ?></a></td>
						<td><?php echo esc_html( $labels[ $problem['status'] ] ?? $problem['status'] ); ?></td>
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
			<li><?php esc_html_e( 'Page-builder templates (headers, footers, popups) are not pages; editing one regenerates every page.', 'static-wp-publisher' ); ?></li>
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

	private function pageActions(): PageActions {
		return new PageActions( new Worker( $this->queue, $this->publisher ), $this->report, $this->verifier );
	}

	private function pageUrl(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
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
				$this->pageUrl()
			)
		);
		exit;
	}
}
