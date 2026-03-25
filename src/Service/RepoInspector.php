<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\BundleInfo;
use MediaWiki\Extension\OntologySync\Model\ImportEntry;
use MediaWiki\Extension\OntologySync\Model\ModuleInfo;

/**
 * Reads bundle and module definitions from the local labki-ontology clone.
 */
class RepoInspector {

	/**
	 * List all bundles defined in the repo.
	 *
	 * @return BundleInfo[]
	 */
	public function listBundles( string $repoPath ): array {
		$bundlesDir = $repoPath . '/bundles';
		if ( !is_dir( $bundlesDir ) ) {
			return [];
		}

		$bundles = [];
		foreach ( glob( $bundlesDir . '/*.json' ) as $file ) {
			$data = $this->readJson( $file );
			if ( $data !== null && isset( $data['id'] ) ) {
				$bundles[] = BundleInfo::fromJson( $data );
			}
		}

		return $bundles;
	}

	/**
	 * List all modules defined in the repo.
	 *
	 * @return ModuleInfo[]
	 */
	public function listModules( string $repoPath ): array {
		$modulesDir = $repoPath . '/modules';
		if ( !is_dir( $modulesDir ) ) {
			return [];
		}

		$modules = [];
		foreach ( glob( $modulesDir . '/*.vocab.json' ) as $file ) {
			$data = $this->readJson( $file );
			if ( $data !== null && isset( $data['id'] ) ) {
				$modules[] = ModuleInfo::fromJson( $data );
			}
		}

		return $modules;
	}

	/**
	 * Get a specific bundle by ID.
	 */
	public function getBundle( string $repoPath, string $bundleId ): ?BundleInfo {
		$file = $repoPath . '/bundles/' . $bundleId . '.json';
		$data = $this->readJson( $file );
		if ( $data === null || !isset( $data['id'] ) ) {
			return null;
		}
		return BundleInfo::fromJson( $data );
	}

	/**
	 * Get a specific module by ID.
	 */
	public function getModule( string $repoPath, string $moduleId ): ?ModuleInfo {
		$file = $repoPath . '/modules/' . $moduleId . '.vocab.json';
		$data = $this->readJson( $file );
		if ( $data === null || !isset( $data['id'] ) ) {
			return null;
		}
		return ModuleInfo::fromJson( $data );
	}

	/**
	 * Get the path to a bundle's versioned artifact directory.
	 *
	 * @return string|null Filesystem path, or null if not found
	 */
	public function getBundleVersionArtifact( string $repoPath, string $bundleId, string $version ): ?string {
		$path = $repoPath . '/bundles/' . $bundleId . '/versions/' . $version;
		if ( !is_dir( $path ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Parse import entries from a vocab.json manifest file.
	 *
	 * @return ImportEntry[]
	 */
	public function getImportEntries( string $vocabJsonPath ): array {
		$data = $this->readJson( $vocabJsonPath );
		if ( $data === null ) {
			return [];
		}

		$entries = [];
		foreach ( $data['import'] ?? [] as $entry ) {
			$entries[] = ImportEntry::fromJson( $entry );
		}
		return $entries;
	}

	/**
	 * Find the vocab.json file within a bundle artifact directory.
	 */
	public function findVocabJson( string $artifactPath ): ?string {
		$files = glob( $artifactPath . '/*.vocab.json' );
		if ( $files === false || $files === [] ) {
			return null;
		}
		return $files[0];
	}

	private function readJson( string $path ): ?array {
		if ( !is_readable( $path ) ) {
			return null;
		}
		$content = file_get_contents( $path );
		if ( $content === false ) {
			return null;
		}
		$data = json_decode( $content, true );
		return is_array( $data ) ? $data : null;
	}
}
