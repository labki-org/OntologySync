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
	/** @var string[] Dashboard IDs for this bundle */
	private array $dashboards;

	/**
	 * @param string $id
	 * @param string $label
	 * @param string $description
	 * @param string[] $modules
	 * @param string[] $dashboards
	 */
	public function __construct(
		string $id,
		string $label,
		string $description,
		array $modules,
		array $dashboards
	) {
		$this->id = $id;
		$this->label = $label;
		$this->description = $description;
		$this->modules = $modules;
		$this->dashboards = $dashboards;
	}

	/**
	 * Create from a parsed bundles/*.json file.
	 */
	public static function fromJson( array $data ): self {
		return new self(
			$data['id'] ?? '',
			$data['label'] ?? '',
			$data['description'] ?? '',
			$data['modules'] ?? [],
			$data['dashboards'] ?? []
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

	/**
	 * @return string[]
	 */
	public function getDashboards(): array {
		return $this->dashboards;
	}
}
