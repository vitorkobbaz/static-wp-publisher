<?php
declare(strict_types=1);

namespace SWPP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SWPP\Export\Domain\ExportRequest;

final class ExportRequestTest extends TestCase {
	public function testRequiresTargetForPublishableMode(): void {
		$this->expectException( InvalidArgumentException::class );
		new ExportRequest( 'publishable', '', false, false );
	}

	/** @dataProvider unsafeTargets */
	public function testRejectsUnsafeTargetBase( string $target ): void {
		$this->expectException( InvalidArgumentException::class );
		new ExportRequest( 'publishable', $target, false, false );
	}

	/** @return array<string,array{string}> */
	public function unsafeTargets(): array {
		return array(
			'credentials' => array( 'https://user:pass@example.com/' ),
			'query'       => array( 'https://example.com/?preview=1' ),
			'fragment'    => array( 'https://example.com/#preview' ),
			'non-http'    => array( 'ftp://example.com/' ),
		);
	}

	public function testAcceptsTargetSubdirectory(): void {
		$request = new ExportRequest( 'publishable', 'https://static.example.com/site/', true, true );
		self::assertSame( 'https://static.example.com/site/', $request->targetBase );
	}
}
