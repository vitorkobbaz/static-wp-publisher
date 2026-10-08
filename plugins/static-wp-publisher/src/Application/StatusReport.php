<?php
/**
 * Read model for the administration dashboard.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\ContentScope;
use SWPP\Core\Domain\PageStatus;
use SWPP\Core\Infrastructure\Database;
use WP_Post;

/**
 * @phpstan-type Row array{post_id:int,title:string,type:string,url:string,modified:string,status:PageStatus,published_at:string,bytes:int}
 * @phpstan-type Entry array{post_id:int,title:string,type:string,url:string,modified:string,protected:bool}
 */
final class StatusReport {
	/** Items resolved per dashboard load; adjustable with `swpp_dashboard_item_limit`. */
	public const DEFAULT_LIMIT = 2000;

	public const VIEWS = array( 'all', PageStatus::GROUP_STATIC, PageStatus::GROUP_PENDING, PageStatus::GROUP_ATTENTION, PageStatus::GROUP_DYNAMIC, PageStatus::GROUP_MISSING );

	public function __construct(
		private readonly Database $database,
		private readonly Queue $queue,
	) {}

	/**
	 * Resolves every visitor-facing page, then filters, searches and paginates.
	 *
	 * @return array{rows:list<Row>,filtered:int,counts:array<string,int>,covered:int,publishable:int,truncated:bool,limit:int,urls:list<string>,choices:list<array{post_id:int,title:string,url:string}>,fix:list<string>,exposed:list<array{post_id:int,title:string}>}
	 */
	public function listing( string $view, string $search, int $paged, int $per_page ): array {
		$limit   = max( 50, (int) apply_filters( 'swpp_dashboard_item_limit', self::DEFAULT_LIMIT ) );
		$entries = $this->entries( $limit + 1 );
		$trunc   = count( $entries ) > $limit;
		$rows    = $this->resolve( array_slice( $entries, 0, $limit ) );

		$counts      = array_fill_keys( self::VIEWS, 0 );
		$covered     = 0;
		$publishable = 0;
		foreach ( $rows as $row ) {
			++$counts['all'];
			++$counts[ $row['status']->group() ];
			if ( $row['status']->isPublishable() ) {
				++$publishable;
				if ( $row['status']->isServedStatically() ) {
					++$covered;
				}
			}
		}

		$urls    = array_map( static fn( array $row ): string => $row['url'], $rows );
		$home    = home_url( '/' );
		$choices = array();
		$fix     = array();
		$exposed = array();
		foreach ( $rows as $row ) {
			$group = $row['status']->group();
			if ( PageStatus::GROUP_ATTENTION === $group || PageStatus::GROUP_MISSING === $group ) {
				$fix[] = $row['url'];
			}
			if ( PageStatus::EXPOSED === $row['status']->key ) {
				$exposed[] = array(
					'post_id' => $row['post_id'],
					'title'   => $row['title'],
				);
			}
			if ( $row['status']->isPublishable() && count( $choices ) < 25 ) {
				$choice = array(
					'post_id' => $row['post_id'],
					'title'   => $row['title'],
					'url'     => $row['url'],
				);
				// The home page leads the page picker.
				if ( $home === $row['url'] ) {
					array_unshift( $choices, $choice );
				} else {
					$choices[] = $choice;
				}
			}
		}
		$view   = in_array( $view, self::VIEWS, true ) ? $view : 'all';
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $search ) ) : strtolower( trim( $search ) );
		$rows   = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $view, $needle ): bool {
					if ( 'all' !== $view && $row['status']->group() !== $view ) {
						return false;
					}
					if ( '' === $needle ) {
						return true;
					}
					$haystack = $row['title'] . ' ' . $row['url'];
					$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
					return str_contains( $haystack, $needle );
				}
			)
		);

		$per_page = max( 10, min( 200, $per_page ) );
		return array(
			'rows'        => array_slice( $rows, ( max( 1, $paged ) - 1 ) * $per_page, $per_page ),
			'filtered'    => count( $rows ),
			'counts'      => $counts,
			'covered'     => $covered,
			'publishable' => $publishable,
			'truncated'   => $trunc,
			'limit'       => $limit,
			'urls'        => $urls,
			'choices'     => $choices,
			'fix'         => $fix,
			'exposed'     => $exposed,
		);
	}

	/**
	 * Current row for one content item (0 = home page), or null if it is not a public page.
	 *
	 * @return Row|null
	 */
	public function rowFor( int $post_id ): ?array {
		if ( 0 === $post_id ) {
			$entry = $this->homeEntry();
		} else {
			$post  = get_post( $post_id );
			$entry = $post instanceof WP_Post && 'publish' === $post->post_status && ContentTypes::isPageType( $post->post_type ) ? $this->entryFor( $post ) : null;
		}
		return null === $entry ? null : $this->resolve( array( $entry ) )[0];
	}

	/**
	 * Resolves an administrator-supplied content id to its public URL. Only published
	 * visitor-facing content (or 0 for the home page) is accepted; never a raw path.
	 */
	public function urlForContent( int $post_id ): ?string {
		$row = $this->rowFor( $post_id );
		return null === $row ? null : $row['url'];
	}

	/**
	 * Latest problems for addresses that are not in the page list (archives, feeds...).
	 *
	 * @param list<string> $listed_urls
	 * @return list<array{url:string,status:string,attempts:int,available_at:string,last_error:string}>
	 */
	public function otherProblems( array $listed_urls ): array {
		return array_values(
			array_filter(
				$this->queue->problems( 50 ),
				static fn( array $problem ): bool => ! in_array( $problem['url'], $listed_urls, true )
			)
		);
	}

	/** @return list<Entry> */
	private function entries( int $limit ): array {
		global $wpdb;
		$types = ContentTypes::pageTypes();
		$out   = array();
		$home  = $this->homeEntry();
		if ( null !== $home ) {
			$out[] = $home;
		}
		if ( array() === $types ) {
			return $out;
		}

		// Only the columns permalinks need: loading post_content for thousands of builder
		// pages would exhaust memory.
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$records      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_author, post_title, post_name, post_type, post_status, post_parent, post_date, post_date_gmt, post_modified, post_modified_gmt, post_password, menu_order FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) ORDER BY post_modified_gmt DESC, ID DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...array_merge( $types, array( $limit ) )
			),
			ARRAY_A
		);
		if ( array() !== $records ) {
			update_object_term_cache( array_map( static fn( array $record ): int => (int) $record['ID'], $records ), $types );
		}
		foreach ( $records as $record ) {
			// sanitize_post() casts ID/post_parent to int like get_post() does; core compares
			// them strictly (the static front page is detected with `===`).
			$post  = sanitize_post( (object) $record, 'raw' );
			$entry = $this->entryFor( new WP_Post( $post ) );
			if ( null !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/** @return Entry|null */
	private function entryFor( WP_Post $post ): ?array {
		$url = get_permalink( $post );
		if ( '' === $url || ! ContentScope::isStaticAddress( $url ) ) {
			return null;
		}
		$type = get_post_type_object( $post->post_type );
		return array(
			'post_id'   => (int) $post->ID,
			'title'     => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'static-wp-publisher' ),
			'type'      => null !== $type ? (string) $type->labels->singular_name : $post->post_type,
			'url'       => $url,
			'modified'  => (string) $post->post_modified_gmt,
			'protected' => '' !== $post->post_password,
		);
	}

	/** @return Entry|null The home page, unless a static front page already represents it. */
	private function homeEntry(): ?array {
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0 ) {
			return null;
		}
		return array(
			'post_id'   => 0,
			'title'     => __( 'Home page', 'static-wp-publisher' ),
			'type'      => __( 'Home', 'static-wp-publisher' ),
			'url'       => home_url( '/' ),
			'modified'  => '',
			'protected' => false,
		);
	}

	/**
	 * @param list<Entry> $entries
	 * @return list<Row>
	 */
	private function resolve( array $entries ): array {
		$hashes    = array_map( static fn( array $entry ): string => hash( 'sha256', $entry['url'] ), $entries );
		$jobs      = array();
		$artifacts = array();
		foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
			$jobs      += $this->queue->latestByHash( $chunk );
			$artifacts += $this->artifactsByHash( $chunk );
		}

		$rows = array();
		foreach ( $entries as $index => $entry ) {
			$artifact = $artifacts[ $hashes[ $index ] ] ?? null;
			$rows[]   = array(
				'post_id'      => $entry['post_id'],
				'title'        => $entry['title'],
				'type'         => $entry['type'],
				'url'          => $entry['url'],
				'modified'     => $entry['modified'],
				'status'       => PageStatus::resolve( $jobs[ $hashes[ $index ] ] ?? null, $artifact, $entry['protected'] ),
				'published_at' => null === $artifact ? '' : $artifact['published_at'],
				'bytes'        => null === $artifact ? 0 : $artifact['bytes'],
			);
		}
		return $rows;
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
