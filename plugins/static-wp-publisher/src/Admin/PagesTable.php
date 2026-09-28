<?php
/**
 * Native WordPress list table of pages and their static state.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Admin;

use SWPP\Core\Application\StatusReport;
use SWPP\Core\Domain\PageStatus;
use WP_List_Table;

/**
 * Requires wp-admin/includes/class-wp-list-table.php to be loaded first.
 *
 * @phpstan-import-type Row from StatusReport
 */
final class PagesTable extends WP_List_Table {
	public const PER_PAGE = 50;

	/** @var array{rows:list<Row>,filtered:int,counts:array<string,int>,covered:int,publishable:int,truncated:bool,limit:int,urls:list<string>,choices:list<array{post_id:int,title:string,url:string}>,fix:list<string>,exposed:list<array{post_id:int,title:string}>}|null */
	private ?array $listing = null;

	public function __construct(
		private readonly StatusReport $report,
		private readonly StatusPresenter $presenter,
		private readonly string $page_url,
	) {
		parent::__construct(
			array(
				'singular' => 'swpp-page',
				'plural'   => 'swpp-pages',
				'ajax'     => false,
			)
		);
	}

	public static function currentView(): string {
		$view = isset( $_GET['swpp_view'] ) ? sanitize_key( wp_unslash( $_GET['swpp_view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $view, StatusReport::VIEWS, true ) ? $view : 'all';
	}

	public static function currentSearch(): string {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** @return array{rows:list<Row>,filtered:int,counts:array<string,int>,covered:int,publishable:int,truncated:bool,limit:int,urls:list<string>,choices:list<array{post_id:int,title:string,url:string}>,fix:list<string>,exposed:list<array{post_id:int,title:string}>} */
	public function listing(): array {
		if ( null === $this->listing ) {
			$this->listing = $this->report->listing( self::currentView(), self::currentSearch(), $this->get_pagenum(), self::PER_PAGE );
		}
		return $this->listing;
	}

	public function prepare_items(): void {
		$listing               = $this->listing();
		$this->items           = $listing['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $listing['filtered'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		$columns = array(
			'cb'      => '<input type="checkbox" />',
			'title'   => __( 'Page', 'static-wp-publisher' ),
			'status'  => __( 'Status', 'static-wp-publisher' ),
			'type'    => __( 'Type', 'static-wp-publisher' ),
			'updated' => __( 'Last updated', 'static-wp-publisher' ),
		);
		// A type column only helps when pages and posts (or custom types) are mixed.
		$types = array_unique( array_map( static fn( array $row ): string => $row['type'], $this->listing()['rows'] ) );
		if ( count( $types ) < 2 ) {
			unset( $columns['type'] );
		}
		return $columns;
	}

	/** @return array<string,string> */
	protected function get_bulk_actions(): array {
		return array(
			'swpp-' . PageActions::REGENERATE => __( 'Update static copy', 'static-wp-publisher' ),
			'swpp-' . PageActions::VERIFY     => __( 'Test visitor version', 'static-wp-publisher' ),
		);
	}

	/** @return array<string,string> */
	protected function get_views(): array {
		$counts  = $this->listing()['counts'];
		$current = self::currentView();
		$views   = array();
		foreach ( StatusPresenter::viewLabels() as $key => $label ) {
			// Empty tabs are noise; keep All and whichever tab is open.
			if ( 'all' !== $key && $current !== $key && 0 === ( $counts[ $key ] ?? 0 ) ) {
				continue;
			}
			$url           = 'all' === $key ? $this->page_url : add_query_arg( 'swpp_view', $key, $this->page_url );
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s data-swpp-view="%3$s">%4$s <span class="count">(%5$s)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_attr( $key ),
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $key ] ?? 0 ) )
			);
		}
		return $views;
	}

	public function no_items(): void {
		$messages = array(
			PageStatus::GROUP_STATIC    => __( 'No page has an up-to-date static copy yet.', 'static-wp-publisher' ),
			PageStatus::GROUP_PENDING   => __( 'Nothing is being updated right now.', 'static-wp-publisher' ),
			PageStatus::GROUP_ATTENTION => __( 'Nothing needs attention.', 'static-wp-publisher' ),
			PageStatus::GROUP_DYNAMIC   => __( 'Every page can have a static copy.', 'static-wp-publisher' ),
			PageStatus::GROUP_MISSING   => __( 'Every page has a static copy or a reason not to.', 'static-wp-publisher' ),
		);
		$search   = self::currentSearch();
		echo esc_html( '' !== $search ? __( 'No pages match your search.', 'static-wp-publisher' ) : ( $messages[ self::currentView() ] ?? __( 'No public pages found.', 'static-wp-publisher' ) ) );
	}

	/** @param Row $item */
	public function single_row( $item ): void {
		printf( '<tr data-swpp-post="%1$d" data-swpp-url="%2$s">', (int) $item['post_id'], esc_attr( $item['url'] ) );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/** @param Row $item */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="swpp-page-%1$d">%2$s</label><input type="checkbox" id="swpp-page-%1$d" name="post_ids[]" value="%1$d" />',
			(int) $item['post_id'],
			/* translators: %s: page title. */
			esc_html( sprintf( __( 'Select %s', 'static-wp-publisher' ), $item['title'] ) )
		);
	}

	/** @param Row $item */
	protected function column_title( array $item ): string {
		return sprintf(
			// The status column collapses on small screens, so the state is repeated here for them.
			'<strong>%1$s</strong><code class="swpp-url">%2$s</code><div class="swpp-mobile-status" data-swpp-cell="status">%3$s</div>',
			esc_html( $item['title'] ),
			esc_html( $item['url'] ),
			$this->presenter->statusHtml( $item['status'] )
		);
	}

	/** @param Row $item */
	protected function column_status( array $item ): string {
		return '<div data-swpp-cell="status">' . $this->presenter->statusHtml( $item['status'] ) . '</div>';
	}

	/**
	 * @param Row    $item
	 * @param string $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'type'    => esc_html( $item['type'] ),
			'updated' => '<span data-swpp-cell="updated">' . $this->presenter->timeHtml( $item['published_at'] ) . '</span>',
			default   => '',
		};
	}

	/**
	 * @param Row    $item
	 * @param string $column_name
	 * @param string $primary
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		if ( $primary !== $column_name ) {
			return '';
		}
		$actions = array(
			'view' => sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $item['url'] ), esc_html__( 'View', 'static-wp-publisher' ) ),
		);
		foreach ( array(
			PageActions::REGENERATE => __( 'Update static copy', 'static-wp-publisher' ),
			PageActions::VERIFY     => __( 'Test visitor version', 'static-wp-publisher' ),
		) as $action => $label ) {
			$url                = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'swpp_' . $action,
						'post_id' => (int) $item['post_id'],
					),
					admin_url( 'admin-post.php' )
				),
				'swpp_' . $action
			);
			$actions[ $action ] = sprintf( '<a href="%1$s" data-swpp-row-action="%2$s">%3$s</a>', esc_url( $url ), esc_attr( $action ), esc_html( $label ) );
		}
		return $this->row_actions( $actions, true ) . '<div class="swpp-result" data-swpp-result role="status" aria-live="polite"></div>';
	}
}
