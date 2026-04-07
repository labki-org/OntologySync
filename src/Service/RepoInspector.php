<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\BundleInfo;
use MediaWiki\Extension\OntologySync\Model\ImportEntry;
use MediaWiki\Extension\OntologySync\Model\ModuleInfo;

/**
 * Reads bundle and module definitions from the local labki-ontology clone.
 */
class RepoInspector {

	/** @var string[] Allowed media file extensions */
	private const MEDIA_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp' ];

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
	 * Expand dashboard IDs to include subpages.
	 *
	 * For each dashboard ID, checks if a subdirectory exists containing
	 * subpage .wikitext files and adds them to the list.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string[] $dashboardIds Root dashboard entity keys
	 * @return string[] All dashboard entity keys including subpages
	 */
	public function expandDashboardSubpages( string $repoPath, array $dashboardIds ): array {
		$expanded = [];
		$dashboardsDir = $repoPath . '/dashboards';

		foreach ( $dashboardIds as $dashId ) {
			$expanded[] = $dashId;

			$subDir = $dashboardsDir . '/' . $dashId;
			if ( !is_dir( $subDir ) ) {
				continue;
			}

			foreach ( scandir( $subDir ) as $entry ) {
				if ( $entry === '.' || $entry === '..' ) {
					continue;
				}
				if ( str_ends_with( $entry, '.wikitext' ) ) {
					$subpageName = substr( $entry, 0, -strlen( '.wikitext' ) );
					$expanded[] = $dashId . '/' . $subpageName;
				}
			}
		}

		return $expanded;
	}

	/**
	 * List all media files in the repo's media/ directory.
	 *
	 * For each media file, checks for a matching JSON sidecar file
	 * (same base name with .json extension) containing metadata.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @return array<string, array{path: string, metadata: array|null}> Map of filename => file info
	 */
	public function listMediaFiles( string $repoPath ): array {
		$mediaDir = $repoPath . '/media';
		if ( !is_dir( $mediaDir ) ) {
			return [];
		}
		$files = [];
		foreach ( scandir( $mediaDir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::MEDIA_EXTENSIONS, true ) ) {
				$baseName = pathinfo( $entry, PATHINFO_FILENAME );
				$jsonPath = $mediaDir . '/' . $baseName . '.json';
				$metadata = $this->readJson( $jsonPath );
				$files[$entry] = [
					'path' => $mediaDir . '/' . $entry,
					'metadata' => $metadata,
				];
			}
		}
		return $files;
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
