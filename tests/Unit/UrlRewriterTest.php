<?php
declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Export\UrlRewriter;

final class UrlRewriterTest extends TestCase {
	private UrlRewriter $rewriter;

	protected function setUp(): void {
		$this->rewriter = new UrlRewriter();
	}

	public function testMakesInternalUrlsRelative(): void {
		self::assertSame(
			'../../wp-content/uploads/photo.jpg',
			$this->rewriter->rewriteUrl( 'https://example.com/wp-content/uploads/photo.jpg', 'news/story/index.html', 'https://example.com/', 'relocatable', '' )
		);
	}

	public function testRebasesInternalUrlsForTargetDomain(): void {
		self::assertSame(
			'https://static.example.net/about/',
			$this->rewriter->rewriteUrl( 'https://example.com/about/', 'index.html', 'https://example.com/', 'publishable', 'https://static.example.net/' )
		);
	}

	public function testLeavesExternalAndSpecialUrlsUntouched(): void {
		self::assertSame( 'https://cdn.example.net/app.js', $this->rewriter->rewriteUrl( 'https://cdn.example.net/app.js', 'index.html', 'https://example.com/', 'relocatable', '' ) );
		self::assertSame( 'mailto:hello@example.com', $this->rewriter->rewriteUrl( 'mailto:hello@example.com', 'index.html', 'https://example.com/', 'relocatable', '' ) );
	}

	public function testDoesNotConfuseLookalikeHostWithSource(): void {
		self::assertSame(
			'https://example.com.evil.test/private.js',
			$this->rewriter->rewriteUrl( 'https://example.com.evil.test/private.js', 'index.html', 'https://example.com/', 'relocatable', '' )
		);
	}

	public function testPreservesQueryAndFragment(): void {
		self::assertSame(
			'../assets/app.js?v=2#module',
			$this->rewriter->rewriteUrl( '/assets/app.js?v=2#module', 'news/index.html', 'https://example.com/', 'relocatable', '' )
		);
	}

	public function testRecognizesDefaultPortAsSameOrigin(): void {
		self::assertSame(
			'assets/app.js',
			$this->rewriter->rewriteUrl( 'https://example.com:443/assets/app.js', 'index.html', 'https://example.com/', 'relocatable', '' )
		);
	}

	public function testLeavesDifferentSchemeOrPortUntouched(): void {
		self::assertSame(
			'http://example.com/assets/app.js',
			$this->rewriter->rewriteUrl( 'http://example.com/assets/app.js', 'index.html', 'https://example.com/', 'relocatable', '' )
		);
		self::assertSame(
			'https://example.com:8443/assets/app.js',
			$this->rewriter->rewriteUrl( 'https://example.com:8443/assets/app.js', 'index.html', 'https://example.com/', 'relocatable', '' )
		);
	}

	public function testRewritesCssUrlsAndImports(): void {
		$css = '@import "/wp-content/theme/base.css"; .hero{background:url(https://example.com/media/hero.webp?v=1)}';
		self::assertSame(
			'@import "../../wp-content/theme/base.css"; .hero{background:url(../../media/hero.webp?v=1)}',
			$this->rewriter->rewriteCss( $css, 'wp-content/theme/site.css', 'https://example.com/', 'relocatable', '' )
		);
	}
}
