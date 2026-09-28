<?php
/**
 * WordPress administration screen.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Admin;

use SWPP\Core\Application\Inventory;
use SWPP\Core\Application\PageOptimizer;
use SWPP\Core\Application\Publisher;
use SWPP\Core\Application\Queue;
use SWPP\Core\Application\StatusReport;
use SWPP\Core\Application\Worker;
use SWPP\Core\Domain\PageStatus;
use SWPP\Core\Infrastructure\SpeedCheck;
use SWPP\Core\Infrastructure\Storage;
use SWPP\Core\Infrastructure\Verifier;

/**
 * @phpstan-type Listing array{rows:list<array<string,mixed>>,filtered:int,counts:array<string,int>,covered:int,publishable:int,truncated:bool,limit:int,urls:list<string>,choices:list<array{post_id:int,title:string,url:string}>,fix:list<string>,exposed:list<array{post_id:int,title:string}>}
 */
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
		add_action( 'admin_post_swpp_fix_pages', array( $this, 'fixPages' ) );
		add_action( 'admin_post_swpp_process_pending', array( $this, 'processPending' ) );
		add_action( 'admin_post_swpp_' . PageActions::REGENERATE, array( $this, 'regenerate' ) );
		add_action( 'admin_post_swpp_' . PageActions::VERIFY, array( $this, 'verify' ) );
		add_action( 'admin_post_swpp_toggle_serving', array( $this, 'toggleServing' ) );
		add_action( 'admin_post_swpp_speed_check', array( $this, 'speedCheck' ) );
		add_action( 'admin_post_swpp_save_speed', array( $this, 'saveSpeedOptions' ) );
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
		wp_enqueue_style( 'swpp-admin', $base . 'admin.css', array(), self::assetVersion( 'admin.css' ) );
		wp_enqueue_script( 'swpp-admin', $base . 'admin.js', array(), self::assetVersion( 'admin.js' ), true );
		wp_localize_script(
			'swpp-admin',
			'swppAdmin',
			array(
				'root'       => esc_url_raw( rest_url( 'swpp/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'pageUrl'    => $this->pageUrl(),
				'inProgress' => $counts['queued'] + $counts['running'],
				'i18n'       => array(
					/* translators: 1: pages ready, 2: pages that always use WordPress, 3: errors, 4: pages still waiting. */
					'progress'     => __( 'Working… %1$d ready, %2$d WordPress only, %3$d errors, %4$d waiting.', 'static-wp-publisher' ),
					/* translators: 1: pages ready, 2: pages that always use WordPress, 3: errors. */
					'done'         => __( 'Done: %1$d static copies ready, %2$d pages always use WordPress, %3$d errors.', 'static-wp-publisher' ),
					/* translators: 1: pages ready, 2: pages waiting to retry. */
					'retrying'     => __( 'Paused: %1$d static copies ready; %2$d page(s) will be retried automatically after an error.', 'static-wp-publisher' ),
					'failed'       => __( 'Stopped because the server returned an error. Reload the page and try again.', 'static-wp-publisher' ),
					'starting'     => __( 'Finding the pages of your site…', 'static-wp-publisher' ),
					/* translators: 1: items done, 2: items selected. */
					'bulkProgress' => __( 'Working on %1$d of %2$d selected pages…', 'static-wp-publisher' ),
					/* translators: 1: pages that succeeded, 2: pages with a warning, 3: pages that failed. */
					'bulkDone'     => __( 'Done: %1$d OK, %2$d with warnings, %3$d failed. Reload the page to refresh the counters.', 'static-wp-publisher' ),
					'noSelection'  => __( 'Select at least one page first.', 'static-wp-publisher' ),
					'working'      => __( 'Working…', 'static-wp-publisher' ),
					'requestError' => __( 'The request failed. Reload the page and try again.', 'static-wp-publisher' ),
					'measuring'    => __( 'Testing… (about 5 seconds)', 'static-wp-publisher' ),
					'measure'      => __( 'Test this page', 'static-wp-publisher' ),
					'confirmPause' => __( 'Visitors will get normal WordPress pages until you resume. Pause static delivery?', 'static-wp-publisher' ),
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
		$fresh   = 0 === $listing['covered'] && 0 === $pending && 0 === $counts[ PageStatus::GROUP_ATTENTION ];
		?>
		<div class="wrap swpp-admin">
			<div class="swpp-title">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Static Publisher', 'static-wp-publisher' ); ?></h1>
				<?php $this->renderPrimaryAction( $listing, $fresh ); ?>
			</div>
			<p class="swpp-intro"><?php esc_html_e( 'Visitors who are not logged in get a saved static copy of each page. You, search, and forms always use live WordPress.', 'static-wp-publisher' ); ?></p>
			<?php $this->renderDelivery( $enabled ); ?>
			<hr class="wp-header-end">
			<?php $this->renderNotice(); ?>
			<?php $this->renderExposed( $listing['exposed'] ); ?>

			<?php if ( $fresh ) : ?>
				<?php $this->renderFirstRun(); ?>
			<?php else : ?>
				<?php $this->renderSummary( $listing, $enabled ); ?>
			<?php endif; ?>

			<div class="swpp-activity" data-swpp-activity<?php echo $pending > 0 ? '' : ' hidden'; ?>>
				<div class="swpp-activity__text">
					<span class="spinner is-active" aria-hidden="true"></span>
					<p>
						<?php
						/* translators: %d: number of pages being updated. */
						echo esc_html( sprintf( _n( '%d page is being updated in the background.', '%d pages are being updated in the background.', $pending, 'static-wp-publisher' ), $pending ) );
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

			<?php if ( ! $fresh ) : ?>
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
			<?php endif; ?>

			<?php $this->renderSpeedOptions(); ?>

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

	/** No-JavaScript fallback: queue the pages without a good copy and process one pass. */
	public function fixPages(): void {
		$this->authorize( 'swpp_fix_pages' );
		foreach ( $this->report->listing( 'all', '', 1, 10 )['fix'] as $url ) {
			$this->queue->enqueue( $url, 'manual' );
		}
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

	/** No-JavaScript fallback for the page test in the summary. */
	public function speedCheck(): void {
		$this->authorize( 'swpp_speed_check' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$result  = $this->pageActions()->run( PageActions::VERIFY, $post_id );
		if ( null === $result ) {
			$this->redirect( __( 'That page is not public content of this site.', 'static-wp-publisher' ), 'error' );
		}
		// The result is shown in the summary; only failures need a notice.
		if ( $result['ok'] ) {
			$this->redirect( '' );
		}
		$this->redirect( $result['message'], $result['level'] );
	}

	/** Saves the speed options and rebuilds every copy when they change. */
	public function saveSpeedOptions(): void {
		$this->authorize( 'swpp_save_speed' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$optimize = ! empty( $_POST['optimize'] );
		$combine  = ! empty( $_POST['combine_css'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$before                  = PageOptimizer::settings();
		$settings                = get_option( 'swpp_settings', array() );
		$settings                = is_array( $settings ) ? $settings : array();
		$settings['optimize']    = $optimize;
		$settings['combine_css'] = $combine;
		update_option( 'swpp_settings', $settings, false );
		if ( $before['optimize'] === $optimize && $before['combine_css'] === $combine ) {
			$this->redirect( __( 'Speed options unchanged.', 'static-wp-publisher' ), 'info' );
		}
		update_option( 'swpp_full_rebuild_recommended', time(), false );
		$this->redirect( __( 'Speed options saved. Every static copy is being rebuilt in the background.', 'static-wp-publisher' ) );
	}

	public function toggleServing(): void {
		$this->authorize( 'swpp_toggle_serving' );
		$settings            = get_option( 'swpp_settings', array() );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = empty( $settings['enabled'] );
		update_option( 'swpp_settings', $settings, false );
		$this->redirect( $settings['enabled'] ? __( 'Static delivery resumed. Visitors get the static copies again.', 'static-wp-publisher' ) : '' );
	}

	private function singleAction( string $action ): void {
		$this->authorize( 'swpp_' . $action );
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in authorize().
		$row     = $post_id >= 0 ? $this->report->rowFor( $post_id ) : null;
		$result  = null !== $row ? $this->pageActions()->run( $action, $post_id ) : null;
		if ( null === $row || null === $result ) {
			$this->redirect( __( 'That page is not public content of this site.', 'static-wp-publisher' ), 'error' );
		}
		$this->redirect( $row['title'] . ' — ' . $result['message'], $result['level'] );
	}

	private function finishPass(): void {
		$report  = ( new Worker( $this->queue, $this->publisher ) )->runPass( $this->inventory, Worker::requestBudget() );
		$pending = $this->queue->counts()['pending'];
		$notice  = sprintf(
			/* translators: 1: static copies ready, 2: pages that always use WordPress, 3: errors, 4: still waiting. */
			__( '%1$d static copies ready, %2$d pages always use WordPress, %3$d errors. %4$d page(s) still waiting; click again to continue or let the background worker finish.', 'static-wp-publisher' ),
			$report->succeeded,
			$report->skipped,
			$report->failed,
			$pending
		);
		$this->redirect( $notice, $report->failed > 0 ? 'warning' : 'success' );
	}

	/**
	 * One contextual primary action, next to the title like core's "Add New":
	 * create copies on a fresh site, fix pages when some lack a good copy, and a quiet
	 * "update all" when everything is healthy.
	 *
	 * @param Listing $listing
	 */
	private function renderPrimaryAction( array $listing, bool $fresh ): void {
		$broken = count( $listing['fix'] );
		if ( $fresh ) {
			$this->queueForm( 'swpp_generate_all', 'build', __( 'Create static copies', 'static-wp-publisher' ), true );
		} elseif ( $broken > 0 ) {
			/* translators: %d: number of pages to fix. */
			$this->queueForm( 'swpp_fix_pages', 'fix', sprintf( _n( 'Fix %d page', 'Fix %d pages', $broken, 'static-wp-publisher' ), $broken ), true );
			$this->queueForm( 'swpp_generate_all', 'build', __( 'Update all static copies', 'static-wp-publisher' ), false );
		} else {
			$this->queueForm( 'swpp_generate_all', 'build', __( 'Update all static copies', 'static-wp-publisher' ), false );
		}
	}

	private function queueForm( string $action, string $mode, string $label, bool $primary ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-swpp-generate="<?php echo esc_attr( $mode ); ?>" class="swpp-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="page-title-action<?php echo $primary ? ' swpp-title-action--primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/** Delivery state and its control on one line; a paused site gets a persistent notice. */
	private function renderDelivery( bool $enabled ): void {
		?>
		<div class="swpp-delivery" data-swpp-serving="<?php echo $enabled ? 'on' : 'off'; ?>">
			<span class="swpp-delivery__state">
				<span class="swpp-dot<?php echo $enabled ? ' swpp-tone--good' : ''; ?>" aria-hidden="true"></span>
				<?php echo $enabled ? esc_html__( 'Static delivery: On', 'static-wp-publisher' ) : esc_html__( 'Static delivery: Paused', 'static-wp-publisher' ); ?>
			</span>
			<?php if ( $enabled ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swpp-inline-form" data-swpp-pause>
					<input type="hidden" name="action" value="swpp_toggle_serving">
					<?php wp_nonce_field( 'swpp_toggle_serving' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Pause', 'static-wp-publisher' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-warning inline swpp-paused">
				<p><?php esc_html_e( 'Static delivery is paused. Visitors get normal WordPress pages.', 'static-wp-publisher' ); ?></p>
				<?php $this->actionButton( 'swpp_toggle_serving', __( 'Resume static delivery', 'static-wp-publisher' ), 'button button-primary' ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/** @param list<array{post_id:int,title:string}> $exposed */
	private function renderExposed( array $exposed ): void {
		foreach ( $exposed as $page ) {
			?>
			<div class="notice notice-error inline swpp-exposed">
				<p>
					<?php
					/* translators: %s: page title. */
					echo esc_html( sprintf( __( '"%s" now has a password, but its old static copy is still public.', 'static-wp-publisher' ), $page['title'] ) );
					?>
				</p>
				<?php $this->rowForm( 'swpp_' . PageActions::REGENERATE, $page['post_id'], __( 'Remove the public copy', 'static-wp-publisher' ) ); ?>
			</div>
			<?php
		}
	}

	private function renderFirstRun(): void {
		?>
		<section class="swpp-summary swpp-summary--intro" aria-labelledby="swpp-intro-title">
			<div class="swpp-summary__coverage">
				<h2 id="swpp-intro-title" class="swpp-summary__headline" data-swpp-headline><?php esc_html_e( 'Make your site faster for visitors', 'static-wp-publisher' ); ?></h2>
				<p class="swpp-summary__note"><?php esc_html_e( 'Static Publisher saves a ready-made copy of each public page and serves it to visitors who are not logged in. Copies update automatically when you edit. Nothing changes while you work in WordPress.', 'static-wp-publisher' ); ?></p>
				<div class="swpp-summary__cta">
					<?php $this->queueForm( 'swpp_generate_all', 'build', __( 'Create static copies', 'static-wp-publisher' ), true ); ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Coverage sentence counted against every page, what the rest are, and the page test.
	 *
	 * @param Listing $listing
	 */
	private function renderSummary( array $listing, bool $enabled ): void {
		$counts = $listing['counts'];
		$total  = max( 1, $counts['all'] );
		$static = $counts[ PageStatus::GROUP_STATIC ];

		$headline = $enabled
			/* translators: 1: pages delivered as static copies, 2: all pages. */
			? sprintf( _n( '%1$s of %2$s page loads as fast static HTML', '%1$s of %2$s pages load as fast static HTML', $counts['all'], 'static-wp-publisher' ), number_format_i18n( $listing['covered'] ), number_format_i18n( $counts['all'] ) )
			/* translators: 1: pages with a static copy, 2: all pages. */
			: sprintf( _n( '%1$s of %2$s page has a static copy ready', '%1$s of %2$s pages have a static copy ready', $counts['all'], 'static-wp-publisher' ), number_format_i18n( $listing['covered'] ), number_format_i18n( $counts['all'] ) );

		$clauses = array();
		$explain = array(
			/* translators: %d: number of pages. */
			PageStatus::GROUP_PENDING   => _n_noop( '%d is being updated.', '%d are being updated.', 'static-wp-publisher' ),
			/* translators: %d: number of pages. */
			PageStatus::GROUP_ATTENTION => _n_noop( '%d needs attention.', '%d need attention.', 'static-wp-publisher' ),
			/* translators: %d: number of pages. */
			PageStatus::GROUP_DYNAMIC   => _n_noop( '%d always uses WordPress (for example, password-protected).', '%d always use WordPress (for example, password-protected).', 'static-wp-publisher' ),
			/* translators: %d: number of pages. */
			PageStatus::GROUP_MISSING   => _n_noop( '%d has no static copy yet.', '%d have no static copy yet.', 'static-wp-publisher' ),
		);
		foreach ( $explain as $group => $noop ) {
			if ( $counts[ $group ] > 0 ) {
				$clauses[] = sprintf(
					'<a href="%1$s" data-swpp-legend="%2$s">%3$s</a>',
					esc_url( add_query_arg( 'swpp_view', $group, $this->pageUrl() ) ),
					esc_attr( $group ),
					esc_html( sprintf( translate_nooped_plural( $noop, $counts[ $group ], 'static-wp-publisher' ), $counts[ $group ] ) )
				);
			}
		}

		$described = array();
		foreach ( StatusPresenter::viewLabels() as $group => $label ) {
			if ( 'all' !== $group && $counts[ $group ] > 0 ) {
				$described[] = $counts[ $group ] . ' ' . $label;
			}
		}
		$last    = SpeedCheck::last();
		$choices = $listing['choices'];
		$current = null !== $last ? $last['post_id'] : ( array() !== $choices ? $choices[0]['post_id'] : 0 );
		$method  = null !== $last ? $last['url'] : home_url( '/' );
		?>
		<section class="swpp-summary" aria-labelledby="swpp-coverage-title">
			<div class="swpp-summary__coverage">
				<h2 id="swpp-coverage-title" class="swpp-summary__headline" data-swpp-headline><?php echo esc_html( $headline ); ?></h2>
				<div class="swpp-meter" role="img" aria-label="<?php echo esc_attr( implode( ', ', $described ) ); ?>">
					<?php foreach ( StatusPresenter::groupTones() as $group => $tone ) : ?>
						<?php if ( $counts[ $group ] > 0 ) : ?>
							<span class="swpp-meter__part swpp-tone--<?php echo esc_attr( $tone ); ?>" style="width: <?php echo esc_attr( (string) round( 100 * $counts[ $group ] / $total, 2 ) ); ?>%"></span>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<p class="swpp-summary__note">
					<?php
					echo array() !== $clauses
						? wp_kses_post( implode( ' ', $clauses ) )
						: ( $static === $counts['all'] ? esc_html__( 'Every page is up to date.', 'static-wp-publisher' ) : '' );
					?>
				</p>
			</div>
			<div class="swpp-summary__speed">
				<h3 class="swpp-summary__label"><?php esc_html_e( 'Server response time', 'static-wp-publisher' ); ?></h3>
				<div class="swpp-speed" data-swpp-speed aria-live="polite">
					<?php echo wp_kses_post( StatusPresenter::forCurrentSettings()->speedHtml( $last ) ); ?>
				</div>
				<?php if ( array() !== $choices ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swpp-speed__form" data-swpp-speed-form>
						<input type="hidden" name="action" value="swpp_speed_check">
						<?php wp_nonce_field( 'swpp_speed_check' ); ?>
						<label for="swpp-speed-page"><?php esc_html_e( 'Page to test', 'static-wp-publisher' ); ?></label>
						<select id="swpp-speed-page" name="post_id">
							<?php foreach ( $choices as $choice ) : ?>
								<option value="<?php echo esc_attr( (string) $choice['post_id'] ); ?>" <?php selected( $current, $choice['post_id'] ); ?>><?php echo esc_html( $choice['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Test this page', 'static-wp-publisher' ); ?></button>
					</form>
				<?php endif; ?>
				<?php echo wp_kses_post( StatusPresenter::methodHtml( $method ) ); ?>
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
			'failed'  => __( 'Could not be created', 'static-wp-publisher' ),
			'skipped' => __( 'WordPress only', 'static-wp-publisher' ),
		);
		?>
		<h2 class="swpp-section-title"><?php esc_html_e( 'Archive pages with problems', 'static-wp-publisher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Category, tag, and other archive pages whose latest static copy could not be created.', 'static-wp-publisher' ); ?></p>
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

	/** What the optimizer may change in static copies; the WordPress page itself is never modified. */
	private function renderSpeedOptions(): void {
		$options = PageOptimizer::settings();
		$summary = sprintf(
			/* translators: 1: state of image and font optimization, 2: state of CSS combining. */
			__( 'Images and fonts: %1$s · Combine CSS: %2$s', 'static-wp-publisher' ),
			$options['optimize'] ? __( 'on', 'static-wp-publisher' ) : __( 'off', 'static-wp-publisher' ),
			$options['combine_css'] ? __( 'on', 'static-wp-publisher' ) : __( 'off', 'static-wp-publisher' )
		);
		?>
		<details class="swpp-details swpp-speed-options" data-swpp-speed-options>
			<summary><?php esc_html_e( 'Speed options', 'static-wp-publisher' ); ?> <span class="swpp-details__meta"><?php echo esc_html( $summary ); ?></span></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="swpp_save_speed">
				<?php wp_nonce_field( 'swpp_save_speed' ); ?>
				<p class="swpp-intro"><?php esc_html_e( 'These changes apply only to the static copies visitors receive. Your WordPress pages are never modified.', 'static-wp-publisher' ); ?></p>
				<fieldset>
					<label class="swpp-option">
						<input type="checkbox" name="optimize" value="1" <?php checked( $options['optimize'] ); ?>>
						<span>
							<strong><?php esc_html_e( 'Optimize images and fonts', 'static-wp-publisher' ); ?></strong>
							<span class="swpp-option__help"><?php esc_html_e( 'Loads the main image first, delays images further down the page, adds missing image sizes to prevent layout jumps, and shows text while custom fonts load.', 'static-wp-publisher' ); ?></span>
						</span>
					</label>
					<label class="swpp-option">
						<input type="checkbox" name="combine_css" value="1" <?php checked( $options['combine_css'] ); ?>>
						<span>
							<strong><?php esc_html_e( 'Combine CSS files (experimental)', 'static-wp-publisher' ); ?></strong>
							<span class="swpp-option__help"><?php esc_html_e( 'Joins style files that load one after another into a single file, so the page can appear sooner. Check a few pages after turning it on. If anything looks different, turn it off: every copy is rebuilt automatically.', 'static-wp-publisher' ); ?></span>
						</span>
					</label>
				</fieldset>
				<?php submit_button( __( 'Save and rebuild copies', 'static-wp-publisher' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	private function renderTechnical(): void {
		$counts = $this->queue->counts();
		$next   = wp_next_scheduled( 'swpp_process_queue' );
		?>
		<table class="widefat striped swpp-technical">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Static copies folder', 'static-wp-publisher' ); ?></th><td><code><?php echo esc_html( $this->storage->publishedRoot() ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Next background run', 'static-wp-publisher' ); ?></th><td><?php echo false !== $next ? esc_html( (string) wp_date( 'Y-m-d H:i:s', $next ) ) : esc_html__( 'Not scheduled — configure WP-Cron or a real scheduler.', 'static-wp-publisher' ); ?></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Job history', 'static-wp-publisher' ); ?></th>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: waiting jobs, 2: running jobs, 3: failed jobs, 4: skipped jobs, 5: completed jobs. */
								__( '%1$d waiting · %2$d running · %3$d failed · %4$d skipped · %5$d completed', 'static-wp-publisher' ),
								$counts['pending'],
								$counts['running'],
								$counts['failed'],
								$counts['skipped'],
								$counts['succeeded']
							)
						);
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Static copies are currently delivered through PHP, so WordPress still starts for each visit. Web-server rules that skip PHP entirely are planned and will make the gain much larger.', 'static-wp-publisher' ); ?></li>
			<li><?php esc_html_e( 'Page-builder templates (headers, footers, popups) are not pages; editing one updates every page.', 'static-wp-publisher' ); ?></li>
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
		if ( '' === $notice ) {
			return;
		}
		$level = in_array( $level, array( 'success', 'warning', 'error', 'info' ), true ) ? $level : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $level ); ?> is-dismissible" data-swpp-notice><p><?php echo esc_html( $notice ); ?></p></div>
		<?php
	}

	/**
	 * Content-based asset version: a plugin update that changes CSS or JS must bypass
	 * browser and CDN caches even when the plugin version number stays the same.
	 */
	private static function assetVersion( string $file ): string {
		$hash = hash_file( 'crc32b', SWPP_DIR . 'assets/' . $file );
		return SWPP_VERSION . ( false !== $hash ? '-' . $hash : '' );
	}

	private function pageActions(): PageActions {
		return new PageActions( new Worker( $this->queue, $this->publisher ), $this->report, $this->verifier );
	}

	private function pageUrl(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	private function rowForm( string $action, int $post_id, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swpp-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
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
		$args = '' === $notice ? array() : array(
			'swpp_notice' => rawurlencode( $notice ),
			'swpp_level'  => $level,
		);
		wp_safe_redirect( add_query_arg( $args, $this->pageUrl() ) );
		exit;
	}
}
