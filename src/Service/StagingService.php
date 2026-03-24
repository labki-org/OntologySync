<?php

namespace MediaWiki\Extension\OntologySync\Service;

/**
 * Manages the staging directory where import artifacts are prepared
 * before running update.php.
 */
class StagingService {

	private RepoInspector $repoInspector;
	private HashService $hashService;

	public function __construct( RepoInspector $repoInspector, HashService $hashService ) {
		$this->repoInspector = $repoInspector;
		$this->hashService = $hashService;
	}

	/**
	 * Resolve the staging path from config or default.
	 */
	public function getStagingPath( ?string $configuredPath, ?string $cacheDirectory ): string {
		if ( $configuredPath !== null ) {
			return $configuredPath;
		}
		if ( $cacheDirectory !== null ) {
			return $cacheDirectory . '/ontologysync-staging';
		}
		return sys_get_temp_dir() . '/ontologysync-staging';
	}

	/**
	 * Copy a bundle version artifact directory to the staging path.
	 *
	 * @return bool True if staging was successful
	 */
	public function buildStaging( string $bundleArtifactPath, string $stagingPath ): bool {
		// Clear any existing staging
		$this->clearStaging( $stagingPath );

		// Create staging directory
		if ( !mkdir( $stagingPath, 0755, true ) && !is_dir( $stagingPath ) ) {
			return false;
		}

		// Recursively copy the artifact directory
		return $this->copyDirectory( $bundleArtifactPath, $stagingPath );
	}

	/**
	 * Remove the staging directory.
	 */
	public function clearStaging( string $stagingPath ): bool {
		if ( !is_dir( $stagingPath ) ) {
			return true;
		}
		return $this->removeDirectory( $stagingPath );
	}

	/**
	 * Check if staging directory has a vocab.json ready for import.
	 */
	public function isStagingReady( string $stagingPath ): bool {
		return $this->repoInspector->findVocabJson( $stagingPath ) !== null;
	}

	/**
	 * Get content hashes for all .wikitext files referenced in the staged vocab.json.
	 *
	 * @return array<string,string> Map of source file path => hash
	 */
	public function getStagedFileHashes( string $stagingPath ): array {
		$vocabJson = $this->repoInspector->findVocabJson( $stagingPath );
		if ( $vocabJson === null ) {
			return [];
		}

		$entries = $this->repoInspector->getImportEntries( $vocabJson );
		$hashes = [];

		foreach ( $entries as $entry ) {
			$filePath = $stagingPath . '/' . $entry->getImportFrom();
			$hash = $this->hashService->hashWikitextFile( $filePath );
			if ( $hash !== null ) {
				$hashes[$entry->getImportFrom()] = $hash;
			}
		}

		return $hashes;
	}

	private function copyDirectory( string $src, string $dst ): bool {
		$entries = scandir( $src );
		if ( $entries === false ) {
			return false;
		}

		foreach ( $entries as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}

			$srcPath = $src . '/' . $file;
			$dstPath = $dst . '/' . $file;

			if ( is_dir( $srcPath ) ) {
				if ( !mkdir( $dstPath, 0755, true ) && !is_dir( $dstPath ) ) {
					return false;
				}
				if ( !$this->copyDirectory( $srcPath, $dstPath ) ) {
					return false;
				}
			} elseif ( !copy( $srcPath, $dstPath ) ) {
				return false;
			}
		}

		return true;
	}

	private function removeDirectory( string $path ): bool {
		if ( !is_dir( $path ) ) {
			return true;
		}

		$entries = scandir( $path );
		if ( $entries === false ) {
			return false;
		}

		foreach ( $entries as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}

			$filePath = $path . '/' . $file;
			if ( is_dir( $filePath ) ) {
				$this->removeDirectory( $filePath );
			} else {
				unlink( $filePath );
			}
		}

		return rmdir( $path );
	}
}
