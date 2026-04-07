<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Service;

use MediaWiki\Extension\OntologySync\Service\HashService;
use MediaWiki\Extension\OntologySync\Service\MediaUploadService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

	private function callGenerateFilePageText( ?array $metadata ): string {
		$method = new ReflectionMethod( MediaUploadService::class, 'generateFilePageText' );
		$method->setAccessible( true );
		return $method->invoke( $this->service, $metadata );
	}

	public function testGenerateFilePageTextWithFullMetadata(): void {
		$metadata = [
			'description' => 'Photo of the equipment',
			'source' => 'Aharoni Lab, UCLA',
			'license' => 'CC-BY-4.0',
			'author' => 'Daniel Aharoni',
		];

		$result = $this->callGenerateFilePageText( $metadata );

		$this->assertStringContainsString( '== Summary ==', $result );
		$this->assertStringContainsString( 'Photo of the equipment', $result );
		$this->assertStringContainsString( '! Source', $result );
		$this->assertStringContainsString( '| Aharoni Lab, UCLA', $result );
		$this->assertStringContainsString( '! Author', $result );
		$this->assertStringContainsString( '| Daniel Aharoni', $result );
		$this->assertStringContainsString( '! License', $result );
		$this->assertStringContainsString( '| CC-BY-4.0', $result );
		$this->assertStringContainsString( '[[Category:OntologySync-managed-media]]', $result );
	}

	public function testGenerateFilePageTextWithPartialMetadata(): void {
		$metadata = [
			'source' => 'Test Lab',
			'license' => 'MIT',
		];

		$result = $this->callGenerateFilePageText( $metadata );

		$this->assertStringContainsString( '== Summary ==', $result );
		$this->assertStringContainsString( '! Source', $result );
		$this->assertStringContainsString( '| Test Lab', $result );
		$this->assertStringContainsString( '! License', $result );
		$this->assertStringContainsString( '| MIT', $result );
		$this->assertStringNotContainsString( '! Author', $result );
		$this->assertStringContainsString( '[[Category:OntologySync-managed-media]]', $result );
	}

	public function testGenerateFilePageTextWithNullMetadata(): void {
		$result = $this->callGenerateFilePageText( null );

		$this->assertStringContainsString(
			'Uploaded by OntologySync from the labki-ontology repository.',
			$result
		);
		$this->assertStringContainsString( '[[Category:OntologySync-managed-media]]', $result );
		$this->assertStringNotContainsString( '== Summary ==', $result );
	}

	public function testGenerateFilePageTextWithEmptyMetadata(): void {
		$result = $this->callGenerateFilePageText( [] );

		// Empty array is not null, so it should produce the table structure
		$this->assertStringContainsString( '== Summary ==', $result );
		$this->assertStringContainsString( '{| class="wikitable"', $result );
		$this->assertStringContainsString( '[[Category:OntologySync-managed-media]]', $result );
	}

	public function testGenerateFilePageTextContainsWikitableMarkup(): void {
		$metadata = [
			'source' => 'Lab',
			'license' => 'CC0',
		];

		$result = $this->callGenerateFilePageText( $metadata );

		$this->assertStringContainsString( '{| class="wikitable"', $result );
		$this->assertStringContainsString( '|}', $result );
	}
}
