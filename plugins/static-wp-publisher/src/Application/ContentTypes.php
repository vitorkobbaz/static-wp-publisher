<?php
/**
 * WordPress adapter for ContentScope.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\ContentScope;

final class ContentTypes {
	/**
	 * Public post types that are real pages for visitors. Extend the exclusion list with
	 * the `swpp_excluded_post_types` filter.
	 *
	 * @return list<string>
	 */
	public static function pageTypes(): array {
		$public   = array_values( array_map( 'strval', get_post_types( array( 'public' => true ), 'names' ) ) );
		$excluded = array_values( array_map( 'strval', (array) apply_filters( 'swpp_excluded_post_types', ContentScope::EXCLUDED_POST_TYPES ) ) );
		return ContentScope::pageTypes( $public, $excluded );
	}

	public static function isPageType( string $type ): bool {
		return in_array( $type, self::pageTypes(), true );
	}

	/** Excluded types other than media: templates and fragments that affect page layout. */
	public static function isTemplateType( string $type ): bool {
		$excluded = array_values( array_map( 'strval', (array) apply_filters( 'swpp_excluded_post_types', ContentScope::EXCLUDED_POST_TYPES ) ) );
		return 'attachment' !== $type && in_array( strtolower( $type ), array_map( 'strtolower', $excluded ), true );
	}
}
