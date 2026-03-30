<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing a bundle definition from the labki-ontology repo.
 */
class BundleInfo {

	private string $id;
	private string $label;
	private string $description;
	/** @var string[] Module IDs included in this bundle */
	private array $modules;

	/**
	 * @param string $id
	 * @param string $label
	 * @param string $description
	 * @param string[] $modules
	 */
	public function __construct(
		string $id,
		string $label,
		string $description,
		array $modules
	) {
		$this->id = $id;
		$this->label = $label;
		$this->description = $description;
		$this->modules = $modules;
	}

	/**
	 * Create from a parsed bundles/*.json file.
	 */
	public static function fromJson( array $data ): self {
		return new self(
			$data['id'] ?? '',
			$data['label'] ?? '',
			$data['description'] ?? '',
			$data['modules'] ?? []
		);
	}

	public function getId(): string {
		return $this->id;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @return string[]
	 */
	public function getModules(): array {
		return $this->modules;
	}
}
