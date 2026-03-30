<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object holding the result of building a vocab.json from module definitions.
 */
class VocabResult {

	/** @var ImportEntry[] */
	private array $entries;
	/** @var array<string,string> Entity key (NS_CONSTANT:PageName) => module ID */
	private array $entityModuleMap;

	/**
	 * @param ImportEntry[] $entries
	 * @param array<string,string> $entityModuleMap
	 */
	public function __construct( array $entries, array $entityModuleMap ) {
		$this->entries = $entries;
		$this->entityModuleMap = $entityModuleMap;
	}

	/**
	 * @return ImportEntry[]
	 */
	public function getEntries(): array {
		return $this->entries;
	}

	/**
	 * @return array<string,string>
	 */
	public function getEntityModuleMap(): array {
		return $this->entityModuleMap;
	}

	/**
	 * Get the module that owns a given entity.
	 *
	 * @param string $nsConstant Namespace constant string
	 * @param string $page Page name
	 * @return string|null Module ID, or null if not found
	 */
	public function getModuleForEntity( string $nsConstant, string $page ): ?string {
		$key = $nsConstant . ':' . $page;
		return $this->entityModuleMap[$key] ?? null;
	}
}
