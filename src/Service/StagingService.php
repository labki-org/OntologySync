<?php

namespace MediaWiki\Extension\OntologySync\Service;

/**
 * Manages the staging directory where import artifacts are prepared
 * before running update.php.
 *
 * Each bundle gets its own subdirectory under the staging root:
 *   staging/Default/  (vocab.json + .wikitext files)
 *   staging/Lab/      (vocab.json + .wikitext files)
 *
 * This allows multiple bundles to be staged and imported in one update.php run.
 */
class StagingService {

	private RepoInspector $repoInspector;
	private HashService $hashService;

	public function __construct( RepoInspector $repoInspector, HashService $hashService ) {
		$this->repoInspector = $repoInspector;
		$this->hashService = $hashService;
	}

	/**
	 * Resolve the staging root path from config or default.
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
	 * Get the staging subdirectory for a specific bundle.
	 */
	public function getBundleStagingPath( string $stagingRoot, string $bundleId ): string {
		return $stagingRoot . '/' . $bundleId;
	}

	/**
	 * Copy a bundle version artifact to its staging subdirectory.
	 */
	public function stageBundle( string $bundleArtifactPath, string $stagingRoot, string $bundleId ): bool {
		$bundlePath = $this->getBundleStagingPath( $stagingRoot, $bundleId );

		// Clear this bundle's staging subdir (leave other bundles intact)
		$this->removeDirectory( $bundlePath );

		// Create the bundle subdirectory
		if ( !mkdir( $bundlePath, 0755, true ) && !is_dir( $bundlePath ) ) {
			return false;
		}

		return $this->copyDirectory( $bundleArtifactPath, $bundlePath );
	}

	/**
	 * Remove a specific bundle's staging subdirectory.
	 */
	public function clearBundleStaging( string $stagingRoot, string $bundleId ): bool {
		$bundlePath = $this->getBundleStagingPath( $stagingRoot, $bundleId );
		return $this->removeDirectory( $bundlePath );
	}

	/**
	 * Remove the entire staging root directory.
	 */
	public function clearAllStaging( string $stagingRoot ): bool {
		if ( !is_dir( $stagingRoot ) ) {
			return true;
		}
		return $this->removeDirectory( $stagingRoot );
	}

	/**
	 * List bundle IDs that have staged artifacts (subdirectories with a vocab.json).
	 *
	 * @return string[] Bundle IDs
	 */
	public function getStagedBundleIds( string $stagingRoot ): array {
		if ( !is_dir( $stagingRoot ) ) {
			return [];
		}

		$entries = scandir( $stagingRoot );
		if ( $entries === false ) {
			return [];
		}

		$bundleIds = [];
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$subdir = $stagingRoot . '/' . $entry;
			if ( is_dir( $subdir ) && $this->repoInspector->findVocabJson( $subdir ) !== null ) {
				$bundleIds[] = $entry;
			}
		}

		return $bundleIds;
	}

	/**
	 * Check if any bundle is staged and ready for import.
	 */
	public function hasStagedBundles( string $stagingRoot ): bool {
		return $this->getStagedBundleIds( $stagingRoot ) !== [];
	}

	/**
	 * Get content hashes for all .wikitext files in a bundle's staging subdir.
	 *
	 * @return array<string,string> Map of source file path => hash
	 */
	public function getStagedFileHashes( string $stagingRoot, string $bundleId ): array {
		$bundlePath = $this->getBundleStagingPath( $stagingRoot, $bundleId );
		$vocabJson = $this->repoInspector->findVocabJson( $bundlePath );
		if ( $vocabJson === null ) {
			return [];
		}

		$entries = $this->repoInspector->getImportEntries( $vocabJson );
		$hashes = [];

		foreach ( $entries as $entry ) {
			$filePath = $bundlePath . '/' . $entry->getImportFrom();
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
