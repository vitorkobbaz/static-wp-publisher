<?php
/**
 * Decides which public content types represent visitor-facing pages.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Domain;

final class ContentScope {
	/**
	 * Post types that WordPress or page builders register as "public" but that hold
	 * templates, fragments, or configuration rather than pages for visitors.
	 */
	public const EXCLUDED_POST_TYPES = array(
		'attachment',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'elementor_library',
		'elementor_snippet',
		'elementor_font',
		'elementor_icons',
		'e-floating-buttons',
		'elementor-hf',
		'et_pb_layout',
		'et_template',
		'fl-builder-template',
		'fl-theme-layout',
		'ct_template',
		'oxy_user_library',
		'oceanwp_library',
		'astra-advanced-hook',
		'brizy_template',
		'jet-theme-core',
		'jet-engine',
		'bricks_template',
		'kadence_element',
		'gp_elements',
	);

	/**
	 * @param list<string> $public_types All public post types.
	 * @param list<string> $excluded     Types to leave out.
	 * @return list<string>
	 */
	public static function pageTypes( array $public_types, array $excluded = self::EXCLUDED_POST_TYPES ): array {
		$excluded = array_map( 'strtolower', $excluded );
		return array_values(
			array_filter(
				$public_types,
				static fn( string $type ): bool => ! in_array( strtolower( $type ), $excluded, true )
			)
		);
	}

	/** Addresses with a query string can never be served from a static file. */
	public static function isStaticAddress( string $url ): bool {
		return ! str_contains( $url, '?' ) && ! str_contains( $url, '#' );
	}
}
