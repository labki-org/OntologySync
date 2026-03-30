<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\ImportEntry;
use MediaWiki\Extension\OntologySync\Model\VocabResult;

/**
 * Builds vocab.json at install time from module definitions.
 *
 * Reads entity lists from module JSON files, deduplicates across modules,
 * maps entity types to SMW namespace constants, and produces ImportEntry
 * objects that SMW's content importer understands.
 */
class VocabBuilder {

	private RepoInspector $repoInspector;

	/** @var array<string,string> Entity type => namespace constant string */
	private const TYPE_NS_MAP = [
		'categories' => 'NS_CATEGORY',
		'properties' => 'SMW_NS_PROPERTY',
		'subobjects' => 'NS_SUBOBJECT',
		'templates' => 'NS_TEMPLATE',
		'resources' => 'NS_ONTOLOGY_RESOURCE',
		'dashboards' => 'NS_ONTOLOGY_DASHBOARD',
	];

	public function __construct( RepoInspector $repoInspector ) {
		$this->repoInspector = $repoInspector;
	}

	/**
	 * Build vocabulary from a set of modules.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string[] $moduleIds Module IDs to include
	 * @return VocabResult
	 */
	public function buildVocab( string $repoPath, array $moduleIds ): VocabResult {
		$entries = [];
		$entityModuleMap = [];
		// Track seen entities for deduplication: "NS_CONSTANT:PageName" => true
		$seen = [];

		foreach ( $moduleIds as $moduleId ) {
			$module = $this->repoInspector->getModule( $repoPath, $moduleId );
			if ( $module === null ) {
				continue;
			}

			foreach ( $module->getAllEntities() as $entityType => $entityKeys ) {
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

					$entityModuleMap[$dedupeKey] = $moduleId;
				}
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
}
