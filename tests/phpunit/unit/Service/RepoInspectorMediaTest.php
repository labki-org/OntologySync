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

	public function testReturnsCorrectFileInfoStructure(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/logo.webp', 'WEBP' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertArrayHasKey( 'path', $result['logo.webp'] );
		$this->assertArrayHasKey( 'metadata', $result['logo.webp'] );
		$this->assertSame( $mediaDir . '/logo.webp', $result['logo.webp']['path'] );
		$this->assertNull( $result['logo.webp']['metadata'] );
	}

	public function testLoadsJsonSidecarMetadata(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/photo.png', 'PNG' );
		file_put_contents( $mediaDir . '/photo.json', json_encode( [
			'description' => 'Photo of the equipment',
			'source' => 'Aharoni Lab, UCLA',
			'license' => 'CC-BY-4.0',
			'author' => 'Daniel Aharoni',
		] ) );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertNotNull( $result['photo.png']['metadata'] );
		$this->assertSame( 'Aharoni Lab, UCLA', $result['photo.png']['metadata']['source'] );
		$this->assertSame( 'CC-BY-4.0', $result['photo.png']['metadata']['license'] );
		$this->assertSame( 'Daniel Aharoni', $result['photo.png']['metadata']['author'] );
		$this->assertSame( 'Photo of the equipment', $result['photo.png']['metadata']['description'] );
	}

	public function testMetadataNullWhenJsonMissing(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/photo.png', 'PNG' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertNull( $result['photo.png']['metadata'] );
	}

	public function testMetadataNullWhenJsonInvalid(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/photo.png', 'PNG' );
		file_put_contents( $mediaDir . '/photo.json', 'not valid json' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertNull( $result['photo.png']['metadata'] );
	}

	public function testMixedFilesWithAndWithoutMetadata(): void {
		$mediaDir = $this->tmpDir . '/media';
		mkdir( $mediaDir, 0777, true );
		file_put_contents( $mediaDir . '/with_meta.png', 'PNG' );
		file_put_contents( $mediaDir . '/with_meta.json', json_encode( [
			'source' => 'Test Lab',
			'license' => 'MIT',
		] ) );
		file_put_contents( $mediaDir . '/no_meta.jpg', 'JPG' );

		$result = $this->inspector->listMediaFiles( $this->tmpDir );

		$this->assertNotNull( $result['with_meta.png']['metadata'] );
		$this->assertSame( 'Test Lab', $result['with_meta.png']['metadata']['source'] );
		$this->assertNull( $result['no_meta.jpg']['metadata'] );
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
