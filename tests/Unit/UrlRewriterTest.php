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
}
