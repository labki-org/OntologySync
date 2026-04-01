<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing a module definition from the labki-ontology repo.
 *
 * Modules declare manually-picked categories and dashboards.
 * Full dependency resolution (properties, subobjects, templates, resources)
 * is handled at install time by DependencyResolver.
 */
class ModuleInfo {

	private string $id;
	private string $label;
	private string $description;
	/** @var string[] */
	private array $categories;
	/** @var string[] */
	private array $dashboards;

	/**
	 * @param string $id
	 * @param string $label
	 * @param string $description
	 * @param string[] $categories
	 * @param string[] $dashboards
	 */
	public function __construct(
		string $id,
		string $label,
		string $description,
		array $categories,
		array $dashboards
	) {
		$this->id = $id;
		$this->label = $label;
		$this->description = $description;
		$this->categories = $categories;
		$this->dashboards = $dashboards;
	}

	/**
	 * Create from a parsed modules/*.json file.
	 */
	public static function fromJson( array $data ): self {
		return new self(
			$data['id'] ?? '',
			$data['label'] ?? '',
			$data['description'] ?? '',
			$data['categories'] ?? [],
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

	/** @return string[] */
	public function getCategories(): array {
		return $this->categories;
	}

	/** @return string[] */
	public function getDashboards(): array {
		return $this->dashboards;
	}
}
