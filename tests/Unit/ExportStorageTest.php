<?php
declare(strict_types=1);

namespace SWPP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWPP\Core\Export\Infrastructure\ExportStorage;

final class ExportStorageTest extends TestCase {
	private string $temporary;
	private ExportStorage $storage;

	protected function setUp(): void {
		$this->temporary = sys_get_temp_dir() . '/swpp-export-storage-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temporary, 0777, true );
		$this->storage = new ExportStorage();
	}

	protected function tearDown(): void {
		$this->removeTree( $this->temporary );
	}

	public function testAcceptsDirectoryOutsidePublicRoot(): void {
		$public  = $this->temporary . '/public';
		$private = $this->temporary . '/private';
		mkdir( $public );
		mkdir( $private );

		self::assertTrue( $this->storage->isPrivateLocation( $private, array( $public ) ) );
	}

	public function testRejectsPublicRootAndItsDescendants(): void {
		$public = $this->temporary . '/public';
		$nested = $public . '/exports';
		mkdir( $nested, 0777, true );

		self::assertFalse( $this->storage->isPrivateLocation( $public, array( $public ) ) );
		self::assertFalse( $this->storage->isPrivateLocation( $nested, array( $public ) ) );
	}

	public function testRejectsMissingCandidate(): void {
		self::assertFalse( $this->storage->isPrivateLocation( $this->temporary . '/missing', array( $this->temporary ) ) );
	}

	public function testCreatesPrivateDirectory(): void {
		$directory = $this->temporary . '/private';
		$this->storage->ensurePrivateDirectory( $directory );

		self::assertDirectoryExists( $directory );
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			self::assertSame( 0700, fileperms( $directory ) & 0777 );
		}
	}

	public function testTightensPermissiveExistingDirectory(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			self::markTestSkipped( 'POSIX permissions only.' );
		}
		$directory = $this->temporary . '/open';
		mkdir( $directory, 0777 );
		chmod( $directory, 0777 );
		$this->storage->ensurePrivateDirectory( $directory );

		self::assertSame( 0700, fileperms( $directory ) & 0777 );
	}

	public function testRejectsSymlinkInPlaceOfDirectory(): void {
		$target = $this->temporary . '/elsewhere';
		$link   = $this->temporary . '/link';
		mkdir( $target );
		if ( ! @symlink( $target, $link ) ) {
			self::markTestSkipped( 'Symbolic links are not available here.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->storage->ensurePrivateDirectory( $link );
	}

	public function testRejectsFileInPlaceOfDirectory(): void {
		$file = $this->temporary . '/file';
		file_put_contents( $file, 'x' );

		$this->expectException( \RuntimeException::class );
		$this->storage->ensurePrivateDirectory( $file );
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
