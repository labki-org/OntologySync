<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Service;

use MediaWiki\Extension\OntologySync\Service\HashService;
use MediaWiki\Extension\OntologySync\Service\MediaUploadService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\OntologySync\Service\MediaUploadService
 */
class MediaUploadServiceTest extends TestCase {

	private MediaUploadService $service;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$hashService = new HashService();
		$this->service = new MediaUploadService( $hashService );
		$this->tmpDir = sys_get_temp_dir() . '/ontologysync_upload_test_' . uniqid();
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

	public function testLoadHashesReturnsEmptyForMissingFile(): void {
		$result = $this->service->loadHashes( $this->tmpDir . '/nonexistent.json' );
		$this->assertSame( [], $result );
	}

	public function testSaveAndLoadHashesRoundTrip(): void {
		$hashFile = $this->tmpDir . '/media-hashes.json';
		$hashes = [
			'photo.png' => 'sha256:abc123',
			'diagram.svg' => 'sha256:def456',
		];

		$this->service->saveHashes( $hashFile, $hashes );
		$loaded = $this->service->loadHashes( $hashFile );

		$this->assertSame( $hashes, $loaded );
	}

	public function testSaveHashesCreatesParentDirectory(): void {
		$hashFile = $this->tmpDir . '/subdir/nested/media-hashes.json';

		$this->service->saveHashes( $hashFile, [ 'file.png' => 'sha256:abc' ] );

		$this->assertFileExists( $hashFile );
		$loaded = $this->service->loadHashes( $hashFile );
		$this->assertSame( [ 'file.png' => 'sha256:abc' ], $loaded );
	}

	public function testLoadHashesReturnsEmptyForInvalidJson(): void {
		$hashFile = $this->tmpDir . '/bad.json';
		file_put_contents( $hashFile, 'not json' );

		$result = $this->service->loadHashes( $hashFile );
		$this->assertSame( [], $result );
	}

	public function testSaveHashesWritesPrettyJson(): void {
		$hashFile = $this->tmpDir . '/media-hashes.json';
		$hashes = [ 'photo.png' => 'sha256:abc123' ];

		$this->service->saveHashes( $hashFile, $hashes );

		$raw = file_get_contents( $hashFile );
		// Pretty-printed JSON has newlines
		$this->assertStringContainsString( "\n", $raw );
	}

	public function testLoadHashesReturnsEmptyForNonArrayJson(): void {
		$hashFile = $this->tmpDir . '/scalar.json';
		file_put_contents( $hashFile, '"just a string"' );

		$result = $this->service->loadHashes( $hashFile );
		$this->assertSame( [], $result );
	}
}
