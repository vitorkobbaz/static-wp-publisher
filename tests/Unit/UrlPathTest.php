<?php
declare(strict_types=1);

namespace SWPP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SWPP\Core\Domain\UrlPath;

final class UrlPathTest extends TestCase {
	public function testMapsHomeToIndex(): void {
		self::assertSame( 'index.html', UrlPath::relative( 'https://example.com/', 'https://example.com/' ) );
	}

	public function testMapsPrettyPermalinkToDirectoryIndex(): void {
		self::assertSame( 'about/team/index.html', UrlPath::relative( 'https://example.com/about/team/?utm_source=test', 'https://example.com/' ) );
	}

	public function testSupportsWordPressInSubdirectory(): void {
		self::assertSame( 'about/index.html', UrlPath::relative( 'https://example.com/site/about/', 'https://example.com/site/' ) );
	}

	public function testRejectsAnotherOrigin(): void {
		$this->expectException( InvalidArgumentException::class );
		UrlPath::relative( 'https://evil.example/path/', 'https://example.com/' );
	}
}
