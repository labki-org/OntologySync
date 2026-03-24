<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing a module definition from the labki-ontology repo.
 */
class ModuleInfo {

	private string $id;
	private string $version;
	private string $label;
	private string $description;
	/** @var array<string,string> Module ID => version */
	private array $dependencies;
	/** @var ImportEntry[] */
	private array $importEntries;

	/**
	 * @param string $id
	 * @param string $version
	 * @param string $label
	 * @param string $description
	 * @param array<string,string> $dependencies
	 * @param ImportEntry[] $importEntries
	 */
	public function __construct(
		string $id,
		string $version,
		string $label,
		string $description,
		array $dependencies,
		array $importEntries
	) {
		$this->id = $id;
		$this->version = $version;
		$this->label = $label;
		$this->description = $description;
		$this->dependencies = $dependencies;
		$this->importEntries = $importEntries;
	}

	/**
	 * Create from a parsed modules/*.vocab.json file.
	 */
	public static function fromJson( array $data ): self {
		$deps = $data['dependencies'] ?? [];
		// Source format may use array (old) or object (new)
		if ( is_array( $deps ) && !self::isAssoc( $deps ) ) {
			$deps = [];
		}

		$entries = [];
		foreach ( $data['import'] ?? [] as $entry ) {
			$entries[] = ImportEntry::fromJson( $entry );
		}

		return new self(
			$data['id'] ?? '',
			$data['version'] ?? '',
			$data['label'] ?? '',
			$data['description'] ?? '',
			$deps,
			$entries
		);
	}

	private static function isAssoc( array $arr ): bool {
		if ( $arr === [] ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	public function getId(): string {
		return $this->id;
	}

	public function getVersion(): string {
		return $this->version;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @return array<string,string>
	 */
	public function getDependencies(): array {
		return $this->dependencies;
	}

	/**
	 * @return ImportEntry[]
	 */
	public function getImportEntries(): array {
		return $this->importEntries;
	}

	public function getEntityCount(): int {
		return count( $this->importEntries );
	}
}
