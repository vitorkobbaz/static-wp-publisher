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
	public function __construct( private readonly Queue $queue ) {}

	public function enqueueAll(): int {
		$count = 0;
		$count += $this->queue->enqueue( home_url( '/' ), 'full_build' ) ? 1 : 0;
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$page = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => array_values( $post_types ),
					'post_status'            => 'publish',
					'posts_per_page'         => 500,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $query->posts as $post_id ) {
				$url = get_permalink( (int) $post_id );
				if ( is_string( $url ) && $this->queue->enqueue( $url, 'full_build' ) ) {
					++$count;
				}
			}
			++$page;
		} while ( $page <= (int) $query->max_num_pages );

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => true, 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id ) {
				$url = get_term_link( (int) $term_id, $taxonomy->name );
				if ( is_string( $url ) && $this->queue->enqueue( $url, 'full_build' ) ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
