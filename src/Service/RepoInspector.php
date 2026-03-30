<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\BundleInfo;
use MediaWiki\Extension\OntologySync\Model\ImportEntry;
use MediaWiki\Extension\OntologySync\Model\ModuleInfo;

/**
 * Reads bundle and module definitions from the local labki-ontology clone.
 */
class RepoInspector {

	/** @var array<string,string> Entity type => directory name in repo */
	private const ENTITY_TYPE_DIRS = [
		'categories' => 'categories',
		'properties' => 'properties',
		'subobjects' => 'subobjects',
		'templates' => 'templates',
		'resources' => 'resources',
		'dashboards' => 'dashboards',
	];

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
		foreach ( glob( $modulesDir . '/*.json' ) as $file ) {
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
		$file = $repoPath . '/modules/' . $moduleId . '.json';
		$data = $this->readJson( $file );
		if ( $data === null || !isset( $data['id'] ) ) {
			return null;
		}
		return ModuleInfo::fromJson( $data );
	}

	/**
	 * Get the full filesystem path to an entity's wikitext file.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string $entityType Entity type (categories, properties, etc.)
	 * @param string $entityKey Entity key (e.g. "Person", "SOP/My_SOP")
	 * @return string|null Full path, or null if file doesn't exist
	 */
	public function getEntityFile(
		string $repoPath, string $entityType, string $entityKey
	): ?string {
		$dir = self::ENTITY_TYPE_DIRS[$entityType] ?? null;
		if ( $dir === null ) {
			return null;
		}

		$path = $repoPath . '/' . $dir . '/' . $entityKey . '.wikitext';
		if ( !is_readable( $path ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Get the relative path for an entity's wikitext file (for vocab.json importFrom).
	 *
	 * @param string $entityType Entity type (categories, properties, etc.)
	 * @param string $entityKey Entity key (e.g. "Person", "SOP/My_SOP")
	 * @return string Relative path like "categories/Person.wikitext"
	 */
	public function getEntityRelativePath( string $entityType, string $entityKey ): string {
		$dir = self::ENTITY_TYPE_DIRS[$entityType] ?? $entityType;
		return $dir . '/' . $entityKey . '.wikitext';
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
	 * Find a vocab.json file within a directory.
	 */
	public function findVocabJson( string $dirPath ): ?string {
		if ( is_readable( $dirPath . '/vocab.json' ) ) {
			return $dirPath . '/vocab.json';
		}
		return null;
	}

	/**
	 * Read and parse a JSON file.
	 */
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
