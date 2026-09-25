<?php
/**
 * Discovers public WordPress routes for a full build.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use WP_Query;

final class Inventory {
	private const STATE_OPTION = 'swpp_inventory_scan';

	public function __construct( private readonly Queue $queue ) {}

	/**
	 * Starts a fresh persistent inventory scan and enqueues its first batch.
	 */
	public function enqueueAll(): int {
		update_option(
			self::STATE_OPTION,
			array(
				'phase'          => 'posts',
				'post_page'      => 1,
				'taxonomy_index' => 0,
				'term_offset'    => 0,
			),
			false
		);

		return $this->enqueueBatch();
	}

	/**
	 * Continues an active scan without holding a worker for the entire site.
	 */
	public function enqueueBatch( int $limit = 500 ): int {
		$state = get_option( self::STATE_OPTION, null );
		if ( ! is_array( $state ) ) {
			return 0;
		}

		$limit = max( 10, min( 1000, $limit ) );
		if ( 'posts' === ( $state['phase'] ?? '' ) ) {
			return $this->enqueuePostBatch( $state, $limit );
		}

		return $this->enqueueTermBatch( $state, $limit );
	}

	public function scanInProgress(): bool {
		return false !== get_option( self::STATE_OPTION, false );
	}

	/** @param array<string,int|string> $state */
	private function enqueuePostBatch( array $state, int $limit ): int {
		$page  = max( 1, (int) ( $state['post_page'] ?? 1 ) );
		$count = 0;
		if ( 1 === $page && $this->queue->enqueue( home_url( '/' ), 'full_build' ) ) {
			++$count;
		}

		$post_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$query      = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $post_id ) {
			if ( ! is_int( $post_id ) ) {
				continue;
			}
			$url = get_permalink( (int) $post_id );
			if ( is_string( $url ) && $this->queue->enqueue( $url, 'full_build' ) ) {
				++$count;
			}
		}

		if ( count( $query->posts ) < $limit ) {
			$state['phase']          = 'terms';
			$state['taxonomy_index'] = 0;
			$state['term_offset']    = 0;
		} else {
			$state['post_page'] = $page + 1;
		}
		update_option( self::STATE_OPTION, $state, false );

		return $count;
	}

	/** @param array<string,int|string> $state */
	private function enqueueTermBatch( array $state, int $limit ): int {
		$taxonomies = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
		$index      = max( 0, (int) ( $state['taxonomy_index'] ?? 0 ) );
		$offset     = max( 0, (int) ( $state['term_offset'] ?? 0 ) );
		$count      = 0;

		if ( ! isset( $taxonomies[ $index ] ) ) {
			delete_option( self::STATE_OPTION );
			return 0;
		}

		$taxonomy = $taxonomies[ $index ];
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'fields'     => 'ids',
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		foreach ( $terms as $term_id ) {
			$url = get_term_link( (int) $term_id, $taxonomy );
			if ( is_string( $url ) && $this->queue->enqueue( $url, 'full_build' ) ) {
				++$count;
			}
		}

		if ( count( $terms ) < $limit ) {
			$state['taxonomy_index'] = $index + 1;
			$state['term_offset']    = 0;
			if ( ! isset( $taxonomies[ $index + 1 ] ) ) {
				delete_option( self::STATE_OPTION );
				return $count;
			}
		} else {
			$state['term_offset'] = $offset + $limit;
		}
		update_option( self::STATE_OPTION, $state, false );

		return $count;
	}
}
