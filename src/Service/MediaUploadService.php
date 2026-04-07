<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Title\Title;
use MediaWiki\User\User;
use UploadBase;
use UploadFromFile;

/**
 * Uploads media files to MediaWiki with an OntologySync- prefix.
 *
 * Uses MediaWiki's UploadFromFile API (available in maintenance script context)
 * to upload files to the File: namespace. Supports hash-based skip-unchanged
 * optimization to avoid re-uploading identical files.
 */
class MediaUploadService {

	private const MEDIA_PREFIX = 'OntologySync-';

	private HashService $hashService;

	public function __construct( HashService $hashService ) {
		$this->hashService = $hashService;
	}

	/**
	 * Upload media files to MediaWiki with OntologySync- prefix.
	 *
	 * @param array<string, string> $mediaFiles Map of filename => full path
	 * @param array<string, string> $existingHashes Map of filename => hash from previous upload
	 * @return array{uploaded: string[], skipped: string[], errors: string[], hashes: array<string, string>}
	 */
	public function uploadMediaFiles(
		array $mediaFiles,
		array $existingHashes = []
	): array {
		$result = [
			'uploaded' => [],
			'skipped' => [],
			'errors' => [],
			'hashes' => [],
		];

		$user = User::newSystemUser( 'OntologySync', [ 'steal' => true ] );
		if ( $user === null ) {
			$result['errors'][] = 'Failed to create OntologySync system user';
			return $result;
		}

		foreach ( $mediaFiles as $filename => $filePath ) {
			$hash = 'sha256:' . hash_file( 'sha256', $filePath );
			$result['hashes'][$filename] = $hash;

			// Skip if hash unchanged from previous upload
			if ( isset( $existingHashes[$filename] ) && $existingHashes[$filename] === $hash ) {
				$result['skipped'][] = $filename;
				continue;
			}

			$prefixedName = self::MEDIA_PREFIX . $filename;

			$title = Title::makeTitleSafe( NS_FILE, $prefixedName );
			if ( $title === null ) {
				$result['errors'][] = "Invalid title for file: $prefixedName";
				continue;
			}

			$tempPath = wfTempDir() . '/' . $prefixedName;
			if ( !copy( $filePath, $tempPath ) ) {
				$result['errors'][] = "Failed to copy $filename to temp path";
				continue;
			}

			$fileSize = filesize( $tempPath );

			$upload = new UploadFromFile();
			$upload->initializePathInfo( $prefixedName, $tempPath, $fileSize, true );

			$verification = $upload->verifyUpload();
			if ( $verification['status'] !== UploadBase::OK ) {
				$result['errors'][] = "Verification failed for $filename: status " . $verification['status'];
				unlink( $tempPath );
				continue;
			}

			$status = $upload->performUpload(
				'OntologySync media import',
				'',
				false,
				$user
			);

			if ( $status->isGood() ) {
				$result['uploaded'][] = $filename;
			} else {
				$result['errors'][] = "Upload failed for $filename: " . $status->getMessage()->text();
			}
		}

		return $result;
	}

	/**
	 * Load previously saved media hashes from a JSON file.
	 *
	 * @param string $hashFilePath Path to the media-hashes.json file
	 * @return array<string, string> Map of filename => hash
	 */
	public function loadHashes( string $hashFilePath ): array {
		if ( !is_readable( $hashFilePath ) ) {
			return [];
		}
		$content = file_get_contents( $hashFilePath );
		if ( $content === false ) {
			return [];
		}
		$data = json_decode( $content, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Save media hashes to a JSON file.
	 *
	 * @param string $hashFilePath Path to save the media-hashes.json file
	 * @param array<string, string> $hashes Map of filename => hash
	 */
	public function saveHashes( string $hashFilePath, array $hashes ): void {
		$dir = dirname( $hashFilePath );
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents(
			$hashFilePath,
			json_encode( $hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}
}
