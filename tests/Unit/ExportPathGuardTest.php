<?php
declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Export\Infrastructure\ExportPathGuard;

final class ExportPathGuardTest extends TestCase {
	private string $temporary;
	private ExportPathGuard $guard;

	protected function setUp(): void {
		$this->temporary = sys_get_temp_dir() . '/swpp-path-guard-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temporary, 0777, true );
		$this->guard = new ExportPathGuard();
	}

	protected function tearDown(): void {
		$this->removeTree( $this->temporary );
	}

	public function testAcceptsDirectZipChild(): void {
		$archive = $this->temporary . '/export.zip';
		file_put_contents( $archive, 'zip fixture' );

		self::assertSame( str_replace( '\\', '/', realpath( $archive ) ?: '' ), $this->guard->resolve( $this->temporary, $archive ) );
	}

	public function testRejectsNestedArchive(): void {
		$nested = $this->temporary . '/nested';
		mkdir( $nested );
		$archive = $nested . '/export.zip';
		file_put_contents( $archive, 'zip fixture' );

		self::assertNull( $this->guard->resolve( $this->temporary, $archive ) );
	}

	public function testRejectsOutsideArchiveAndNonZipFile(): void {
		$outside = dirname( $this->temporary ) . '/outside-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		file_put_contents( $outside, 'zip fixture' );
		file_put_contents( $this->temporary . '/export.txt', 'not a zip' );

		try {
			self::assertNull( $this->guard->resolve( $this->temporary, $outside ) );
			self::assertNull( $this->guard->resolve( $this->temporary, $this->temporary . '/export.txt' ) );
		} finally {
			unlink( $outside );
		}
	}

	public function testRejectsMissingArchive(): void {
		self::assertNull( $this->guard->resolve( $this->temporary, $this->temporary . '/missing.zip' ) );
	}

	private function removeTree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		foreach ( scandir( $directory ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->removeTree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $directory );
	}
}
