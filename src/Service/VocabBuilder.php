<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\ImportEntry;
use MediaWiki\Extension\OntologySync\Model\VocabResult;

/**
 * Builds vocab.json at install time from module definitions.
 *
 * Collects categories from modules, uses DependencyResolver to resolve
 * the full entity graph, then produces ImportEntry objects that SMW's
 * content importer understands.
 */
class VocabBuilder {

	private RepoInspector $repoInspector;
	private DependencyResolver $dependencyResolver;

	/** @var array<string,string> Entity type => namespace constant string */
	private const TYPE_NS_MAP = [
		'categories' => 'NS_CATEGORY',
		'properties' => 'SMW_NS_PROPERTY',
		'subobjects' => 'NS_SUBOBJECT',
		'templates' => 'NS_TEMPLATE',
		'resources' => 'NS_ONTOLOGY_RESOURCE',
		'dashboards' => 'NS_ONTOLOGY_DASHBOARD',
	];

	public function __construct(
		RepoInspector $repoInspector,
		DependencyResolver $dependencyResolver
	) {
		$this->repoInspector = $repoInspector;
		$this->dependencyResolver = $dependencyResolver;
	}

	/**
	 * Build vocabulary from a set of modules.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string[] $moduleIds Module IDs to include
	 * @return VocabResult
	 */
	public function buildVocab( string $repoPath, array $moduleIds ): VocabResult {
		// Collect all categories and dashboards from modules
		$allCategories = [];
		$allDashboards = [];
		$categoryModuleMap = []; // category => first module that declared it

		foreach ( $moduleIds as $moduleId ) {
			$module = $this->repoInspector->getModule( $repoPath, $moduleId );
			if ( $module === null ) {
				continue;
			}

			foreach ( $module->getCategories() as $cat ) {
				if ( !isset( $categoryModuleMap[$cat] ) ) {
					$categoryModuleMap[$cat] = $moduleId;
				}
				$allCategories[$cat] = true;
			}

			foreach ( $module->getDashboards() as $dash ) {
				$allDashboards[] = $dash;
			}
		}

		// Resolve full dependency chain
		$resolved = $this->dependencyResolver->resolve(
			$repoPath,
			array_keys( $allCategories ),
			$allDashboards
		);

		// Build import entries
		$entries = [];
		$entityModuleMap = [];
		$seen = [];

		foreach ( $resolved->getAllEntities() as $entityType => $entityKeys ) {
			$nsConstant = self::TYPE_NS_MAP[$entityType] ?? null;
			if ( $nsConstant === null ) {
				continue;
			}

			foreach ( $entityKeys as $entityKey ) {
				$dedupeKey = $nsConstant . ':' . $entityKey;
				if ( isset( $seen[$dedupeKey] ) ) {
					continue;
				}
				$seen[$dedupeKey] = true;

				$relPath = $this->repoInspector->getEntityRelativePath(
					$entityType, $entityKey
				);

				$entries[] = new ImportEntry(
					$entityKey,
					$nsConstant,
					$relPath,
					true
				);

				// Attribute to first module that contributed a relevant category
				$entityModuleMap[$dedupeKey] = $this->findAttributionModule(
					$entityKey, $entityType, $categoryModuleMap, $moduleIds
				);
			}
		}

		return new VocabResult( $entries, $entityModuleMap );
	}

	/**
	 * Write a vocab.json file to disk in SMW-compatible format.
	 *
	 * @param string $outputPath Full path to write (e.g. .../staging/Default/vocab.json)
	 * @param VocabResult $result The vocabulary to write
	 * @param string[] $skipPages Pages to exclude (format: "NS_CONSTANT:PageName")
	 * @return bool Success
	 */
	public function writeVocabJson(
		string $outputPath,
		VocabResult $result,
		array $skipPages = []
	): bool {
		$skipSet = array_flip( $skipPages );

		$importArray = [];
		foreach ( $result->getEntries() as $entry ) {
			$key = $entry->getNamespace() . ':' . $entry->getPage();
			if ( isset( $skipSet[$key] ) ) {
				continue;
			}

			$importArray[] = [
				'page' => $entry->getPage(),
				'namespace' => $entry->getNamespace(),
				'contents' => [
					'importFrom' => $entry->getImportFrom(),
				],
				'options' => [
					'replaceable' => $entry->isReplaceable(),
				],
			];
		}

		$json = json_encode(
			[
				'import' => $importArray,
				'meta' => [ 'version' => '1' ],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return file_put_contents( $outputPath, $json ) !== false;
	}

	/**
	 * Attribute an entity to the first module that contributed it.
	 *
	 * For categories, use direct module membership.
	 * For other types, attribute to the first module in the list.
	 */
	private function findAttributionModule(
		string $entityKey,
		string $entityType,
		array $categoryModuleMap,
		array $moduleIds
	): string {
		if ( $entityType === 'categories' && isset( $categoryModuleMap[$entityKey] ) ) {
			return $categoryModuleMap[$entityKey];
		}

		// For derived entities, attribute to the first module
		return $moduleIds[0] ?? '';
	}
}
