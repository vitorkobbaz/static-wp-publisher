<?php
/**
 * Converts WordPress changes into regeneration jobs.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

final class Invalidator {
	public function __construct( private readonly Queue $queue ) {}

	public function register(): void {
		add_action( 'save_post', array( $this, 'postChanged' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'postDeleted' ) );
		add_action( 'edited_term', array( $this, 'termChanged' ), 20, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'globalChanged' ) );
		add_action( 'customize_save_after', array( $this, 'globalChanged' ) );
		add_action( 'switch_theme', array( $this, 'globalChanged' ) );
		add_action( 'upgrader_process_complete', array( $this, 'globalChanged' ) );
	}

	public function postChanged( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( is_string( $url ) ) {
			$this->queue->enqueue( $url, 'post_changed' );
		}
		$this->queue->enqueue( home_url( '/' ), 'post_dependency' );

		foreach ( wp_get_post_terms( $post_id, get_object_taxonomies( $post->post_type ) ) as $term ) {
			$term_url = get_term_link( $term );
			if ( is_string( $term_url ) ) {
				$this->queue->enqueue( $term_url, 'term_dependency' );
			}
		}
	}

	public function postDeleted( int $post_id ): void {
		$url = get_permalink( $post_id );
		if ( is_string( $url ) ) {
			$this->queue->enqueue( $url, 'post_deleted' );
		}
		$this->queue->enqueue( home_url( '/' ), 'post_deleted_dependency' );
	}

	public function termChanged( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_taxonomy_id );
		$url = get_term_link( $term_id, $taxonomy );
		if ( is_string( $url ) ) {
			$this->queue->enqueue( $url, 'term_changed' );
		}
	}

	public function globalChanged(): void {
		$this->queue->enqueue( home_url( '/' ), 'global_changed' );
		update_option( 'swpp_full_rebuild_recommended', time(), false );
	}
}
