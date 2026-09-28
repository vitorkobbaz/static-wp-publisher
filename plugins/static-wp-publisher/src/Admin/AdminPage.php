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
use SWPP\Core\Infrastructure\SpeedCheck;
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
		add_action( 'admin_post_swpp_speed_check', array( $this, 'speedCheck' ) );
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
					'measuring'    => __( 'Measuring…', 'static-wp-publisher' ),
					'measureAgain' => __( 'Measure again', 'static-wp-publisher' ),
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
		$counts  = $listing['counts'];
		$pending = $counts[ PageStatus::GROUP_PENDING ];
		?>
		<div class="swpp-header">
			<div class="swpp-header__title">
				<h1><?php esc_html_e( 'Static Publisher', 'static-wp-publisher' ); ?></h1>
				<span class="swpp-serving swpp-serving--<?php echo $enabled ? 'on' : 'off'; ?>" data-swpp-serving="<?php echo $enabled ? 'on' : 'off'; ?>">
					<span class="swpp-dot" aria-hidden="true"></span>
					<?php echo $enabled ? esc_html__( 'Static serving on', 'static-wp-publisher' ) : esc_html__( 'Static serving off', 'static-wp-publisher' ); ?>
				</span>
			</div>
			<div class="swpp-header__actions">
				<?php $this->actionButton( 'swpp_toggle_serving', $enabled ? __( 'Turn off', 'static-wp-publisher' ) : __( 'Turn on', 'static-wp-publisher' ), 'button' ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-generate="build" class="swpp-inline-form">
					<input type="hidden" name="action" value="swpp_generate_all">
					<?php wp_nonce_field( 'swpp_generate_all' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate all pages', 'static-wp-publisher' ); ?></button>
				</form>
			</div>
		</div>

		<div class="wrap swpp-admin">
			<hr class="wp-header-end">
			<?php $this->renderNotice(); ?>

			<?php $this->renderSummary( $listing, $enabled ); ?>

			<div class="swpp-activity" data-swpp-activity<?php echo $pending > 0 ? '' : ' hidden'; ?>>
				<div class="swpp-activity__text" data-swpp-live>
					<span class="spinner is-active" aria-hidden="true"></span>
					<p>
						<?php
						/* translators: %d: number of pages waiting to be generated. */
						echo esc_html( sprintf( _n( '%d page is waiting to be updated. It is processed in the background about once a minute.', '%d pages are waiting to be updated. They are processed in the background about once a minute.', $pending, 'static-wp-publisher' ), $pending ) );
						?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-generate="process" class="swpp-inline-form">
					<input type="hidden" name="action" value="swpp_process_pending">
					<?php wp_nonce_field( 'swpp_process_pending' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Update now', 'static-wp-publisher' ); ?></button>
				</form>
			</div>
			<div class="swpp-progress" data-swpp-progress hidden>
				<progress data-swpp-progress-bar></progress>
				<p data-swpp-progress-text role="status" aria-live="polite"></p>
			</div>

			<h2 class="swpp-section-title"><?php esc_html_e( 'Pages', 'static-wp-publisher' ); ?></h2>
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

	/** No-JavaScript fallback for the home page speed check. */
	public function speedCheck(): void {
		$this->authorize( 'swpp_speed_check' );
		$result = ( new SpeedCheck( $this->verifier ) )->measure( home_url( '/' ) );
		$this->redirect(
			'' !== $result['error'] ? $result['error'] : __( 'Speed measured.', 'static-wp-publisher' ),
			'' !== $result['error'] ? 'error' : 'success'
		);
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
	 * Coverage sentence, composition bar with a clickable legend, and the speed check.
	 *
	 * @param array{counts:array<string,int>,covered:int,publishable:int} $listing
	 */
	private function renderSummary( array $listing, bool $enabled ): void {
		$counts = $listing['counts'];
		$total  = max( 1, $counts['all'] );
		$labels = StatusPresenter::viewLabels();
		$empty  = 0 === $listing['covered'] && 0 === $counts[ PageStatus::GROUP_PENDING ];

		if ( $empty ) {
			$headline = __( 'No static copies yet', 'static-wp-publisher' );
			$note     = __( 'Generate all pages to create them. Visitors keep getting normal WordPress pages until you turn static serving on.', 'static-wp-publisher' );
		} elseif ( $enabled ) {
			/* translators: 1: pages served as static HTML, 2: pages that can be static. */
			$headline = sprintf( __( '%1$s of %2$s pages are served as static HTML', 'static-wp-publisher' ), number_format_i18n( $listing['covered'] ), number_format_i18n( $listing['publishable'] ) );
			$note     = $counts[ PageStatus::GROUP_ATTENTION ] > 0
				/* translators: %d: number of pages that need attention. */
				? sprintf( _n( '%d page needs attention.', '%d pages need attention.', $counts[ PageStatus::GROUP_ATTENTION ], 'static-wp-publisher' ), $counts[ PageStatus::GROUP_ATTENTION ] )
				: __( 'Logged-in users, search, and forms keep using WordPress.', 'static-wp-publisher' );
		} else {
			/* translators: 1: pages with a static copy, 2: pages that can be static. */
			$headline = sprintf( __( '%1$s of %2$s pages have a static copy ready', 'static-wp-publisher' ), number_format_i18n( $listing['covered'] ), number_format_i18n( $listing['publishable'] ) );
			$note     = __( 'Static serving is off, so visitors get normal WordPress pages. Turn it on when you are ready.', 'static-wp-publisher' );
		}

		$parts = array();
		foreach ( StatusPresenter::groupTones() as $group => $tone ) {
			if ( $counts[ $group ] > 0 ) {
				$parts[] = array(
					'group' => $group,
					'tone'  => $tone,
					'count' => $counts[ $group ],
					'label' => $labels[ $group ],
				);
			}
		}
		$described = implode( ', ', array_map( static fn( array $part ): string => $part['count'] . ' ' . $part['label'], $parts ) );
		$last      = SpeedCheck::last();
		?>
		<section class="swpp-summary" aria-labelledby="swpp-coverage-title">
			<div class="swpp-summary__coverage">
				<h2 id="swpp-coverage-title" class="swpp-summary__headline" data-swpp-headline><?php echo esc_html( $headline ); ?></h2>
				<p class="swpp-summary__note"><?php echo esc_html( $note ); ?></p>
				<?php if ( array() !== $parts ) : ?>
					<div class="swpp-meter" role="img" aria-label="<?php echo esc_attr( $described ); ?>">
						<?php foreach ( $parts as $part ) : ?>
							<span class="swpp-meter__part swpp-tone--<?php echo esc_attr( $part['tone'] ); ?>" style="width: <?php echo esc_attr( (string) round( 100 * $part['count'] / $total, 2 ) ); ?>%"></span>
						<?php endforeach; ?>
					</div>
					<ul class="swpp-legend">
						<?php foreach ( $parts as $part ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( 'swpp_view', $part['group'], $this->pageUrl() ) ); ?>" data-swpp-legend="<?php echo esc_attr( $part['group'] ); ?>">
									<span class="swpp-dot swpp-tone--<?php echo esc_attr( $part['tone'] ); ?>" aria-hidden="true"></span>
									<span class="swpp-legend__label"><?php echo esc_html( $part['label'] ); ?></span>
									<span class="swpp-legend__count swpp-num"><?php echo esc_html( number_format_i18n( $part['count'] ) ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<div class="swpp-summary__speed" aria-labelledby="swpp-speed-title">
				<h3 id="swpp-speed-title" class="swpp-summary__label"><?php esc_html_e( 'Home page speed', 'static-wp-publisher' ); ?></h3>
				<div class="swpp-speed" data-swpp-speed aria-live="polite">
					<?php echo wp_kses_post( StatusPresenter::forCurrentSettings()->speedHtml( $last ) ); ?>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-speed-form>
					<input type="hidden" name="action" value="swpp_speed_check">
					<?php wp_nonce_field( 'swpp_speed_check' ); ?>
					<button type="submit" class="button"><?php echo null === $last ? esc_html__( 'Measure speed', 'static-wp-publisher' ) : esc_html__( 'Measure again', 'static-wp-publisher' ); ?></button>
				</form>
			</div>
		</section>
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
