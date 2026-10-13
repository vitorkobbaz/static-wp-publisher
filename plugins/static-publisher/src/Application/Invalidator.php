<?php
/**
 * Converts WordPress changes into regeneration jobs.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use Throwable;

final class Invalidator {
	/** @var array<int,string> Term addresses captured before an edit, by term ID. */
	private array $previousTermUrls = array();

	public function __construct(
		private readonly Queue $queue,
		private readonly ?Publisher $publisher = null,
	) {}

	public function register(): void {
		add_action( 'post_updated', array( $this, 'postUpdated' ), 5, 3 );
		add_action( 'wp_trash_post', array( $this, 'postTrashed' ), 5 );
		add_action( 'transition_post_status', array( $this, 'postStatusChanged' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'postDeleted' ) );
		add_action( 'edit_term', array( $this, 'termEditing' ), 5, 3 );
		add_action( 'edited_term', array( $this, 'termChanged' ), 20, 3 );
		add_action( 'delete_term', array( $this, 'termDeleted' ), 20, 4 );
		add_action( 'wp_update_nav_menu', array( $this, 'globalChanged' ) );
		add_action( 'customize_save_after', array( $this, 'globalChanged' ) );
		add_action( 'switch_theme', array( $this, 'globalChanged' ) );
		add_action( 'upgrader_process_complete', array( $this, 'globalChanged' ) );
	}

	/**
	 * Revokes the previous copy synchronously when an update hides the content or moves
	 * it: unpublishing, making it private, adding a password, or changing its address.
	 */
	public function postUpdated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( 'publish' !== $before->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ContentTypes::isTemplateType( $before->post_type ) ) {
			return;
		}
		$old_url = get_permalink( $before );
		$hidden  = 'publish' !== $after->post_status || ( '' === (string) $before->post_password && '' !== (string) $after->post_password );
		$moved   = 'publish' === $after->post_status && get_permalink( $after ) !== $old_url;
		if ( $hidden || $moved ) {
			$this->revoke( $old_url );
		}
	}

	public function postTrashed( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ContentTypes::isTemplateType( $post->post_type ) ) {
			return;
		}
		$this->revoke( get_permalink( $post ) );
	}

	public function postStatusChanged( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$this->postChanged( $post->ID, $post );
			return;
		}

		if ( 'publish' === $old_status && ContentTypes::isTemplateType( $post->post_type ) ) {
			$this->globalChanged();
			return;
		}
		if ( 'publish' === $old_status ) {
			$url = get_permalink( $post->ID );
			if ( is_string( $url ) ) {
				$this->queue->enqueue( $url, 'post_unpublished' );
			}
			$this->queue->enqueue( home_url( '/' ), 'post_unpublished_dependency' );
			$this->requestFullRebuild();
		}
	}

	public function postChanged( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ContentTypes::isTemplateType( $post->post_type ) ) {
			// Builder templates (headers, footers, popups) have no page of their own but
			// change how every page renders.
			$this->globalChanged();
			return;
		}
		$url = get_permalink( $post_id );
		if ( is_string( $url ) ) {
			$this->queue->enqueue( $url, 'post_changed' );
		}
		$this->queue->enqueue( home_url( '/' ), 'post_dependency' );
		$this->requestFullRebuild();

		$terms = wp_get_post_terms( $post_id, get_object_taxonomies( $post->post_type ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$term_url = get_term_link( $term );
			if ( is_string( $term_url ) ) {
				$this->queue->enqueue( $term_url, 'term_dependency' );
			}
		}
	}

	public function postDeleted( int $post_id ): void {
		$type = get_post_type( $post_id );
		if ( is_string( $type ) && ContentTypes::isTemplateType( $type ) ) {
			// A template's own address is never published, and queueing it would publish
			// whatever reuses that address next (for example a template recreated with the
			// same slug).
			$this->globalChanged();
			return;
		}
		$url = get_permalink( $post_id );
		if ( is_string( $url ) ) {
			if ( 'publish' === get_post_status( $post_id ) ) {
				$this->revoke( $url );
			}
			$this->queue->enqueue( $url, 'post_deleted' );
		}
		$this->queue->enqueue( home_url( '/' ), 'post_deleted_dependency' );
		$this->requestFullRebuild();
	}

	/** Captures a term's address before an edit so a slug change can retire it. */
	public function termEditing( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_taxonomy_id );
		$url = get_term_link( $term_id, $taxonomy );
		if ( is_string( $url ) ) {
			$this->previousTermUrls[ $term_id ] = $url;
		}
	}

	public function termChanged( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_taxonomy_id );
		$url      = get_term_link( $term_id, $taxonomy );
		$previous = $this->previousTermUrls[ $term_id ] ?? null;
		unset( $this->previousTermUrls[ $term_id ] );
		if ( is_string( $previous ) && $previous !== $url ) {
			$this->revoke( $previous );
		}
		if ( is_string( $url ) ) {
			$this->queue->enqueue( $url, 'term_changed' );
		}
		$this->requestFullRebuild();
	}

	public function termDeleted( int $term_id, int $term_taxonomy_id, string $taxonomy, \WP_Term $deleted_term ): void {
		unset( $term_id, $term_taxonomy_id, $taxonomy );
		$term_url = get_term_link( $deleted_term );
		if ( is_string( $term_url ) ) {
			$this->revoke( $term_url );
			$this->queue->enqueue( $term_url, 'term_deleted' );
		}
		$this->requestFullRebuild();
	}

	public function globalChanged( mixed ...$ignored ): void {
		unset( $ignored );
		$this->queue->enqueue( home_url( '/' ), 'global_changed' );
		$this->requestFullRebuild();
	}

	private function revoke( string $url ): void {
		if ( null === $this->publisher ) {
			return;
		}
		try {
			$this->publisher->revoke( $url );
		} catch ( Throwable $error ) {
			// The visitor-facing check in LocalServer still refuses the old copy.
			error_log( 'Static Publisher: unable to revoke ' . $url . ': ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private function requestFullRebuild(): void {
		update_option( 'swpp_full_rebuild_recommended', time(), false );
	}
}
