<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\VocabResult;

/**
 * Manages the staging directory where import artifacts are prepared
 * before running update.php.
 *
 * Each bundle gets its own subdirectory under the staging root:
 *   staging/Default/  (vocab.json + .wikitext files)
 *   staging/Lab/      (vocab.json + .wikitext files)
 *
 * VocabBuilder generates the vocab.json at install time from module
 * definitions. StagingService copies the referenced wikitext files
 * from the flat repo structure and performs pre-merge with existing
 * wiki page content where applicable.
 */
class StagingService {

	private const MARKER_START = '<!-- OntologySync Start -->';
	private const MARKER_END = '<!-- OntologySync End -->';
	private const MEDIA_PREFIX = 'OntologySync-';

	private RepoInspector $repoInspector;
	private HashService $hashService;
	private VocabBuilder $vocabBuilder;

	public function __construct(
		RepoInspector $repoInspector,
		HashService $hashService,
		VocabBuilder $vocabBuilder
	) {
		$this->repoInspector = $repoInspector;
		$this->hashService = $hashService;
		$this->vocabBuilder = $vocabBuilder;
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
	 * Stage a bundle for import: write vocab.json and copy wikitext files.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string $bundleId Bundle identifier
	 * @param string $stagingRoot Staging root directory
	 * @param VocabResult $vocabResult Built vocabulary
	 * @param array<string,string> $existingPageContent Map of "NS_CONSTANT:PageName" => wikitext
	 * @param string[] $skipPages Pages to exclude (format: "NS_CONSTANT:PageName")
	 * @return bool Success
	 */
	public function stageBundle(
		string $repoPath,
		string $bundleId,
		string $stagingRoot,
		VocabResult $vocabResult,
		array $existingPageContent = [],
		array $skipPages = []
	): bool {
		$bundlePath = $this->getBundleStagingPath( $stagingRoot, $bundleId );

		// Clear this bundle's staging subdir (leave other bundles intact)
		$this->removeDirectory( $bundlePath );

		// Create the bundle subdirectory
		if ( !mkdir( $bundlePath, 0755, true ) && !is_dir( $bundlePath ) ) {
			return false;
		}

		// Write vocab.json (excluding skipped pages)
		$vocabPath = $bundlePath . '/vocab.json';
		if ( !$this->vocabBuilder->writeVocabJson( $vocabPath, $vocabResult, $skipPages ) ) {
			return false;
		}

		$skipSet = array_flip( $skipPages );

		// Copy wikitext files from repo to staging, with pre-merge
		foreach ( $vocabResult->getEntries() as $entry ) {
			$entryKey = $entry->getNamespace() . ':' . $entry->getPage();
			if ( isset( $skipSet[$entryKey] ) ) {
				continue;
			}

			$srcPath = $repoPath . '/' . $entry->getImportFrom();
			$dstPath = $bundlePath . '/' . $entry->getImportFrom();

			// Create subdirectories as needed
			$dstDir = dirname( $dstPath );
			if ( !is_dir( $dstDir ) ) {
				if ( !mkdir( $dstDir, 0755, true ) && !is_dir( $dstDir ) ) {
					return false;
				}
			}

			if ( !is_readable( $srcPath ) ) {
				continue;
			}

			$repoContent = file_get_contents( $srcPath );
			if ( $repoContent === false ) {
				continue;
			}

			// Rewrite [[File:X]] references to [[File:OntologySync-X]]
			$repoContent = $this->rewriteMediaReferences( $repoContent );

			// Pre-merge: if existing wiki page content is available and has markers,
			// replace the marker block in the existing content with the new repo content
			$existingContent = $existingPageContent[$entryKey] ?? null;
			if ( $existingContent !== null ) {
				$merged = $this->preMergeContent( $repoContent, $existingContent );
				if ( $merged !== null ) {
					$repoContent = $merged;
				}
			}

			if ( file_put_contents( $dstPath, $repoContent ) === false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Pre-merge: inject the OntologySync marker block from the staged file
	 * into the existing wiki page content.
	 *
	 * This preserves user content outside the markers while updating the
	 * managed section.
	 *
	 * @param string $repoContent New content from the repo
	 * @param string $existingContent Current wiki page content
	 * @return string|null Merged content, or null if merge not possible
	 */
	private function preMergeContent( string $repoContent, string $existingContent ): ?string {
		// Both must have markers for merge to work
		$repoStart = strpos( $repoContent, self::MARKER_START );
		$repoEnd = strpos( $repoContent, self::MARKER_END );
		$existStart = strpos( $existingContent, self::MARKER_START );
		$existEnd = strpos( $existingContent, self::MARKER_END );

		if ( $repoStart === false || $repoEnd === false
			|| $existStart === false || $existEnd === false
		) {
			return null;
		}

		if ( $repoEnd <= $repoStart || $existEnd <= $existStart ) {
			return null;
		}

		// Extract the new marker block (including markers)
		$newBlock = substr(
			$repoContent,
			$repoStart,
			$repoEnd + strlen( self::MARKER_END ) - $repoStart
		);

		// Replace the old marker block in existing content with the new one
		$before = substr( $existingContent, 0, $existStart );
		$after = substr( $existingContent, $existEnd + strlen( self::MARKER_END ) );

		return $before . $newBlock . $after;
	}

	/**
	 * Rewrite [[File:X]] references to [[File:OntologySync-X]] in wikitext content.
	 *
	 * Uses a negative lookahead to prevent double-prefixing on re-imports.
	 *
	 * @param string $content Wikitext content
	 * @return string Content with rewritten file references
	 */
	private function rewriteMediaReferences( string $content ): string {
		return preg_replace(
			'/\[\[File:(?!OntologySync-)([^\]|]+)/u',
			'[[File:' . self::MEDIA_PREFIX . '$1',
			$content
		);
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
