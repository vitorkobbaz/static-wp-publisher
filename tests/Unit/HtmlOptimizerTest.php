<?php
/**
 * Static-copy optimizer tests.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\AssetUrl;
use SWPP\Core\Domain\HtmlOptimizer;
use SWPP\Core\Domain\HtmlTag;
use SWPP\Core\Domain\OptimizerAssets;
use SWPP\Core\Domain\OptimizerOptions;

final class HtmlOptimizerTest extends TestCase {
	private const SITE = 'https://example.com';

	/** @var list<string> */
	public array $stored = array();

	private function assets( array $css = array() ): OptimizerAssets {
		$test = $this;
		return new class( $css, $test ) implements OptimizerAssets {
			/** @param array<string,string> $css */
			public function __construct( private array $css, private HtmlOptimizerTest $test ) {}

			public function stylesheet( string $url ): ?string {
				return $this->css[ preg_replace( '~\?.*$~', '', $url ) ] ?? null;
			}

			public function imageSize( string $url ): ?array {
				return str_starts_with( $url, 'https://example.com/wp-content/uploads/' ) ? array( 640, 480 ) : null;
			}

			public function isLocal( string $url ): bool {
				return str_starts_with( $url, 'https://example.com/wp-content/' );
			}

			public function storeStylesheet( string $css ): ?string {
				$this->test->stored[] = $css;
				return 'https://example.com/wp-content/uploads/static-wp-publisher/site-1/assets/css/' . substr( hash( 'sha256', $css ), 0, 16 ) . '.css';
			}
		};
	}

	private static function page( string $head, string $body ): string {
		return "<!doctype html>\n<html><head>\n<meta charset=\"UTF-8\">\n" . $head . "\n</head>\n<body>\n" . $body . "\n</body></html>";
	}

	public function test_images_after_the_first_two_are_lazy_and_get_local_sizes(): void {
		$body = '';
		for ( $i = 1; $i <= 4; ++$i ) {
			$body .= '<img src="' . self::SITE . '/wp-content/uploads/a' . $i . '.png" alt="">';
		}
		$body .= '<img src="https://cdn.other.com/b.png" alt="">';
		$out   = ( new HtmlOptimizer() )->optimize( self::page( '', $body ), new OptimizerOptions(), $this->assets() );

		self::assertStringNotContainsString( 'a1.png" alt="" loading="lazy"', $out );
		self::assertMatchesRegularExpression( '~a1\.png" alt="" decoding="async" width="640" height="480">~', $out );
		self::assertMatchesRegularExpression( '~a2\.png" alt="" decoding="async" width="640" height="480">~', $out );
		self::assertMatchesRegularExpression( '~a3\.png" alt="" loading="lazy" decoding="async" width="640" height="480">~', $out );
		self::assertMatchesRegularExpression( '~b\.png" alt="" loading="lazy" decoding="async">~', $out );
	}

	public function test_high_priority_image_is_preloaded_and_never_lazy(): void {
		$img = '<img fetchpriority="high" loading="lazy" src="' . self::SITE . '/wp-content/uploads/hero.png" srcset="' . self::SITE . '/wp-content/uploads/hero-800.png 800w" sizes="100vw" width="1" height="1">';
		$out = ( new HtmlOptimizer() )->optimize( self::page( '', '<p>x</p><img src="a.png"><img src="b.png"><img src="c.png">' . $img ), new OptimizerOptions(), $this->assets() );

		self::assertStringContainsString( '<meta charset="UTF-8">' . "\n" . '<link rel="preload" as="image" href="https://example.com/wp-content/uploads/hero.png" imagesrcset="https://example.com/wp-content/uploads/hero-800.png 800w" imagesizes="100vw" fetchpriority="high" data-swpp-opt="preload" />', $out );
		self::assertStringNotContainsString( 'loading="lazy" src="https://example.com/wp-content/uploads/hero.png"', $out );
		self::assertMatchesRegularExpression( '~<img fetchpriority="high" src="[^"]+hero\.png"~', $out );
	}

	public function test_elementor_background_is_preloaded_from_the_page_stylesheet(): void {
		$sheet = self::SITE . '/wp-content/uploads/elementor/css/post-49.css';
		$css   = array(
			$sheet => '@media (max-width:767px){.elementor-49 .elementor-element.elementor-element-6f222db{background-image:url("../mobile.png");}}'
				. '.elementor-49 .elementor-element.elementor-element-6f222db:not(.x), .y{background-image:url("../../2022/12/wide-background.png");background-size:contain;}'
				. '.elementor-element-6f222dbz{background-image:url(no.png)}',
		);
		$head  = '<link rel="stylesheet" id="elementor-post-49-css" href="' . $sheet . '?ver=1" media="all" />';
		$body  = '<div class="elementor-element elementor-element-6f222db e-con" data-id="6f222db" data-settings="{&quot;background_background&quot;:&quot;classic&quot;}">x</div>';
		$out   = ( new HtmlOptimizer() )->optimize( self::page( $head, $body ), new OptimizerOptions(), $this->assets( $css ) );

		self::assertStringContainsString( '<link rel="preload" as="image" href="https://example.com/wp-content/uploads/2022/12/wide-background.png" fetchpriority="high" data-swpp-opt="preload" />', $out );
		self::assertStringNotContainsString( 'mobile.png', $out );
	}

	public function test_at_most_two_images_are_preloaded(): void {
		$sheet = self::SITE . '/wp-content/uploads/elementor/css/post-1.css';
		$css   = array( $sheet => '.elementor-element-a1{background-image:url(/wp-content/uploads/1.png)}.elementor-element-a2{background-image:url(/wp-content/uploads/2.png)}.elementor-element-a3{background-image:url(/wp-content/uploads/3.png)}' );
		$body  = '';
		foreach ( array( 'a1', 'a2', 'a3' ) as $id ) {
			$body .= '<div data-id="' . $id . '" data-settings="{&quot;background_background&quot;:&quot;classic&quot;}"></div>';
		}
		$out = ( new HtmlOptimizer() )->optimize( self::page( '<link rel="stylesheet" href="' . $sheet . '">', $body ), new OptimizerOptions(), $this->assets( $css ) );

		self::assertSame( 2, substr_count( $out, 'rel="preload"' ) );
	}

	public function test_inline_font_faces_swap_and_other_css_is_untouched(): void {
		$head = "<style id=\"fonts\">\n@font-face {\n\tfont-family: 'Rinter';\n\tfont-display: auto;\n\tsrc: url(a.woff2);\n}\n@font-face{font-family:X;src:url(b.woff2)}\n.auto{font-display:auto}\n</style>";
		$out  = ( new HtmlOptimizer() )->optimize( self::page( $head, '<p>x</p>' ), new OptimizerOptions(), $this->assets() );

		self::assertStringContainsString( 'font-display: swap;', $out );
		self::assertStringContainsString( '@font-face{font-display:swap;font-family:X', $out );
		self::assertStringContainsString( '.auto{font-display:auto}', $out );
	}

	public function test_scripts_and_comments_are_never_treated_as_markup(): void {
		$body = '<script>var s = "<img src=\'x.png\'>";</script><!-- <img src="y.png"> --><noscript><img src="z.png"></noscript>';
		$out  = ( new HtmlOptimizer() )->optimize( self::page( '', $body ), new OptimizerOptions( true, false, 0 ), $this->assets() );

		self::assertStringContainsString( $body, $out );
	}

	public function test_consecutive_local_stylesheets_are_combined_in_order(): void {
		$base = self::SITE . '/wp-content/plugins/p/css/';
		$css  = array(
			$base . 'one.css'   => '@charset "UTF-8";.one{background:url(../img/a.png)}',
			$base . 'two.css'   => '.two{color:red}',
			$base . 'three.css' => '.three{color:blue}',
			$base . 'four.css'  => '.four{color:green}',
			$base . 'print.css' => '.print{color:black}',
			$base . 'imp.css'   => '@import url(x.css);.imp{}',
		);
		$head = '<link rel="stylesheet" id="one-css" href="' . $base . 'one.css?ver=1" media="all" />' . "\n"
			. '<link rel=\'stylesheet\' href=\'' . $base . 'two.css\' />' . "\n"
			. '<style id="two-inline">.inline{}</style>' . "\n"
			. '<link rel="stylesheet" href="' . $base . 'three.css" media="all">' . "\n"
			. '<link rel="stylesheet" href="' . $base . 'four.css" media="all">' . "\n"
			. '<link rel="stylesheet" href="' . $base . 'print.css" media="print">' . "\n"
			. '<link rel="stylesheet" href="' . $base . 'imp.css">' . "\n"
			. '<link rel="stylesheet" href="https://fonts.example.net/x.css">';
		$this->stored = array();
		$out          = ( new HtmlOptimizer() )->optimize( self::page( $head, '<p>x</p>' ), new OptimizerOptions( false, true ), $this->assets( $css ) );

		self::assertCount( 2, $this->stored );
		self::assertStringContainsString( '.one{background:url(https://example.com/wp-content/plugins/p/img/a.png)}', $this->stored[0] );
		self::assertStringNotContainsString( '@charset', $this->stored[0] );
		self::assertLessThan( strpos( $this->stored[0], '.two' ), strpos( $this->stored[0], '.one' ) );
		self::assertStringContainsString( '.three{color:blue}', $this->stored[1] );
		self::assertSame( 2, substr_count( $out, 'data-swpp-opt="css"' ) );
		self::assertLessThan( strpos( $out, '<style id="two-inline">' ), strpos( $out, 'data-swpp-opt="css"' ) );
		self::assertStringContainsString( 'print.css" media="print">', $out );
		self::assertStringContainsString( 'imp.css">', $out );
		self::assertStringContainsString( 'fonts.example.net/x.css', $out );
		self::assertStringNotContainsString( 'one.css?ver=1', $out );
	}

	public function test_optimized_output_is_left_alone_and_disabled_options_change_nothing(): void {
		$page = self::page( '', '<img src="a.png"><img src="b.png"><img src="c.png">' );
		$once = ( new HtmlOptimizer() )->optimize( $page . '<link data-swpp-opt="preload">', new OptimizerOptions(), $this->assets() );

		self::assertSame( $page . '<link data-swpp-opt="preload">', $once );
		self::assertSame( $page, ( new HtmlOptimizer() )->optimize( $page, new OptimizerOptions( false, false ), $this->assets() ) );
		self::assertSame( '<p>no head</p>', ( new HtmlOptimizer() )->optimize( '<p>no head</p>', new OptimizerOptions(), $this->assets() ) );
	}

	public function test_tag_parser_keeps_untouched_attributes_verbatim(): void {
		$tag = HtmlTag::parse( "<img class='a b' data-x=\"{&quot;k&quot;:1}\" src=x.png>" );

		self::assertNotNull( $tag );
		self::assertSame( '{"k":1}', $tag->get( 'data-x' ) );
		$tag->set( 'loading', 'lazy' );
		self::assertSame( "<img class='a b' data-x=\"{&quot;k&quot;:1}\" src=x.png loading=\"lazy\">", $tag->toHtml() );
	}

	public function test_asset_urls_stay_inside_their_prefix(): void {
		self::assertSame( 'uploads/a b.png', AssetUrl::relative( 'http://example.com/wp-content/uploads/a%20b.png?ver=1', 'https://example.com/wp-content/' ) );
		self::assertNull( AssetUrl::relative( 'https://example.com/wp-content/uploads/../../wp-config.php', 'https://example.com/wp-content/' ) );
		self::assertNull( AssetUrl::relative( 'https://example.com/wp-content/uploads/%2e%2e/x.css', 'https://example.com/wp-content/' ) );
		self::assertNull( AssetUrl::relative( 'https://evil.com/wp-content/a.css', 'https://example.com/wp-content/' ) );
		self::assertNull( AssetUrl::relative( 'https://example.com/other/a.css', 'https://example.com/wp-content/' ) );
		self::assertSame( 'https://example.com/a/img/x.png', AssetUrl::resolve( 'https://example.com/a/css/s.css?ver=1', '../img/x.png' ) );
		self::assertSame( 'https://example.com/abs.png', AssetUrl::resolve( 'https://example.com/a/css/s.css', '/abs.png' ) );
		self::assertSame( 'data:image/png;base64,AA', AssetUrl::resolve( 'https://example.com/a/s.css', 'data:image/png;base64,AA' ) );
		self::assertSame( 'https://example.com/a/css/f.woff2#iefix', AssetUrl::resolve( 'https://example.com/a/css/s.css', 'f.woff2#iefix' ) );
	}
}
