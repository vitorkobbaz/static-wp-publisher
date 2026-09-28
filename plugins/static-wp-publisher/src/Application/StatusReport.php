<?php
/**
 * Read model for the administration dashboard.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\PageStatus;
use SWPP\Core\Infrastructure\Database;
use WP_Post;
use WP_Query;

final class StatusReport {
	public function __construct(
		private readonly Database $database,
		private readonly Queue $queue,
	) {}

	/**
	 * Site-wide numbers. Content counts come from cheap aggregate queries, so this stays
	 * fast on large sites.
	 *
	 * @return array{content:int,protected:int,static:int,queued:int,retrying:int,running:int,failed:int,skipped:int}
	 */
	public function summary(): array {
		global $wpdb;
		$types        = $this->postTypes();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$content      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$types
			)
		);
		$protected    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password <> '' AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$types
			)
		);
		$artifacts    = $this->database->table( 'artifacts' );
		$static       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$artifacts}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// The home page has its own row unless a static front page already represents it.
		if ( ! $this->frontPageIsPost() ) {
			++$content;
		}

		return array_merge(
			array(
				'content'   => $content,
				'protected' => $protected,
				'static'    => $static,
			),
			$this->queue->latestCounts()
		);
	}

	/**
	 * One page of public content with its publication state, most recently changed first.
	 *
	 * @return array{rows:list<array{post_id:int,title:string,type:string,url:string,status:PageStatus,published_at:string,bytes:int}>,pages:int}
	 */
	public function rows( int $paged, int $per_page = 50 ): array {
		$paged    = max( 1, $paged );
		$per_page = max( 10, min( 200, $per_page ) );
		$query    = new WP_Query(
			array(
				'post_type'              => $this->postTypes(),
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $paged,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$entries = array();
		if ( 1 === $paged && ! $this->frontPageIsPost() ) {
			$entries[] = array(
				'post_id'   => 0,
				'title'     => __( 'Home page', 'static-wp-publisher' ),
				'type'      => __( 'Home', 'static-wp-publisher' ),
				'url'       => home_url( '/' ),
				'protected' => false,
			);
		}
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$url = get_permalink( $post );
			$type      = get_post_type_object( $post->post_type );
			$entries[] = array(
				'post_id'   => (int) $post->ID,
				'title'     => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'static-wp-publisher' ),
				'type'      => null !== $type ? (string) $type->labels->singular_name : $post->post_type,
				'url'       => $url,
				'protected' => '' !== $post->post_password,
			);
		}

		$hashes    = array_map( static fn( array $entry ): string => hash( 'sha256', $entry['url'] ), $entries );
		$jobs      = $this->queue->latestByHash( $hashes );
		$artifacts = $this->artifactsByHash( $hashes );

		$rows = array();
		foreach ( $entries as $index => $entry ) {
			$hash     = $hashes[ $index ];
			$artifact = $artifacts[ $hash ] ?? null;
			$rows[]   = array(
				'post_id'      => $entry['post_id'],
				'title'        => $entry['title'],
				'type'         => $entry['type'],
				'url'          => $entry['url'],
				'status'       => PageStatus::resolve( $jobs[ $hash ] ?? null, $artifact, $entry['protected'] ),
				'published_at' => null === $artifact ? '' : $artifact['published_at'],
				'bytes'        => null === $artifact ? 0 : $artifact['bytes'],
			);
		}

		return array(
			'rows'  => $rows,
			'pages' => max( 1, (int) $query->max_num_pages ),
		);
	}

	/**
	 * Resolves an administrator-supplied content id to its public URL. Only published,
	 * publicly viewable content (or 0 for the home page) is accepted; never a raw path.
	 */
	public function urlForContent( int $post_id ): ?string {
		if ( 0 === $post_id ) {
			return home_url( '/' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, $this->postTypes(), true ) ) {
			return null;
		}
		return get_permalink( $post );
	}

	/** @return list<string> */
	private function postTypes(): array {
		$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$types = array_values( array_diff( $types, array( 'attachment' ) ) );
		return array() === $types ? array( 'page' ) : $types;
	}

	private function frontPageIsPost(): bool {
		return 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0;
	}

	/**
	 * @param list<string> $hashes
	 * @return array<string,array{published_at:string,bytes:int}>
	 */
	private function artifactsByHash( array $hashes ): array {
		global $wpdb;
		$hashes = array_values( array_unique( $hashes ) );
		if ( array() === $hashes ) {
			return array();
		}
		$table        = $this->database->table( 'artifacts' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_hash, published_at, bytes FROM {$table} WHERE url_hash IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$hashes
			)
		);
		$out          = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->url_hash ] = array(
				'published_at' => (string) $row->published_at,
				'bytes'        => (int) $row->bytes,
			);
		}
		return $out;
	}
}
