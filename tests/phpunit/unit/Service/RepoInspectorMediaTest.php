<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Service;

use MediaWiki\Extension\OntologySync\Service\RepoInspector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\OntologySync\Service\RepoInspector::listMediaFiles
 */
class RepoInspectorMediaTest extends TestCase {

	private RepoInspector $inspector;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->inspector = new RepoInspector();
		$this->tmpDir = sys_get_temp_dir() . '/ontologysync_media_test_' . uniqid();
		mkdir( $this->tmpDir, 0777, true );
	}

	protected function tearDown(): void {
		$this->removeDir( $this->tmpDir );
		parent::tearDown();
	}

	private function removeDir( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->removeDir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	public function testReturnsEmptyArrayWhenMediaDirMissing(): void {
		$result = $this->inspector->listMediaFiles( $this->tmpDir );
		$this->assertSame( [], $result );
	}

	public function testDiscoversSupportedMediaFiles(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/photo.png', 'PNG' );
		file_put_contents( $mediaDir . '/diagram.svg', '<svg/>' );
		file_put_contents( $mediaDir . '/shot.jpg', 'JPG' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertCount( 3, $result );
		$this->assertArrayHasKey( 'photo.png', $result );
		$this->assertArrayHasKey( 'diagram.svg', $result );
		$this->assertArrayHasKey( 'shot.jpg', $result );
	}

	public function testReturnsCorrectFilePaths(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/logo.webp', 'WEBP' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertSame( $mediaDir . '/logo.webp', $result['logo.webp'] );
	}

	public function testFiltersOutUnsupportedExtensions(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/photo.png', 'PNG' );
		file_put_contents( $mediaDir . '/readme.txt', 'text' );
		file_put_contents( $mediaDir . '/data.json', '{}' );
		file_put_contents( $mediaDir . '/script.php', '<?php' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'photo.png', $result );
	}

	public function testHandlesMixedCaseExtensions(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/photo.PNG', 'PNG' );
		file_put_contents( $mediaDir . '/image.Jpg', 'JPG' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'photo.PNG', $result );
		$this->assertArrayHasKey( 'image.Jpg', $result );
	}

	public function testAllSupportedExtensions(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		$extensions = [ 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp' ];
		foreach ( $extensions as $ext ) {
			file_put_contents( $mediaDir . '/file.' . $ext, 'data' );
		}

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertSameSize( $extensions, $result );
		foreach ( $extensions as $ext ) {
			$this->assertArrayHasKey( 'file.' . $ext, $result );
		}
	}

	public function testIgnoresSubdirectories(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir . '/subdir', 0777, true );
		file_put_contents( $mediaDir . '/photo.png', 'PNG' );
		file_put_contents( $mediaDir . '/subdir/nested.png', 'PNG' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'photo.png', $result );
	}
}
