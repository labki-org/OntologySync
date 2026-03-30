<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing a module definition from the labki-ontology repo.
 *
 * Modules are simple JSON with entity lists:
 * { id, label, description, categories: [...], properties: [...],
 *   subobjects: [...], templates: [...], resources: [...], dashboards: [] }
 */
class ModuleInfo {

	private string $id;
	private string $label;
	private string $description;
	/** @var string[] */
	private array $categories;
	/** @var string[] */
	private array $properties;
	/** @var string[] */
	private array $subobjects;
	/** @var string[] */
	private array $templates;
	/** @var string[] */
	private array $resources;
	/** @var string[] */
	private array $dashboards;

	/**
	 * @param string $id
	 * @param string $label
	 * @param string $description
	 * @param string[] $categories
	 * @param string[] $properties
	 * @param string[] $subobjects
	 * @param string[] $templates
	 * @param string[] $resources
	 * @param string[] $dashboards
	 */
	public function __construct(
		string $id,
		string $label,
		string $description,
		array $categories,
		array $properties,
		array $subobjects,
		array $templates,
		array $resources,
		array $dashboards
	) {
		$this->id = $id;
		$this->label = $label;
		$this->description = $description;
		$this->categories = $categories;
		$this->properties = $properties;
		$this->subobjects = $subobjects;
		$this->templates = $templates;
		$this->resources = $resources;
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
			$data['properties'] ?? [],
			$data['subobjects'] ?? [],
			$data['templates'] ?? [],
			$data['resources'] ?? [],
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
	public function getProperties(): array {
		return $this->properties;
	}

	/** @return string[] */
	public function getSubobjects(): array {
		return $this->subobjects;
	}

	/** @return string[] */
	public function getTemplates(): array {
		return $this->templates;
	}

	/** @return string[] */
	public function getResources(): array {
		return $this->resources;
	}

	/** @return string[] */
	public function getDashboards(): array {
		return $this->dashboards;
	}

	/**
	 * Get total entity count across all types.
	 */
	public function getEntityCount(): int {
		return count( $this->categories )
			+ count( $this->properties )
			+ count( $this->subobjects )
			+ count( $this->templates )
			+ count( $this->resources )
			+ count( $this->dashboards );
	}

	/**
	 * Get all entities grouped by type.
	 *
	 * @return array<string,string[]>
	 */
	public function getAllEntities(): array {
		return [
			'categories' => $this->categories,
			'properties' => $this->properties,
			'subobjects' => $this->subobjects,
			'templates' => $this->templates,
			'resources' => $this->resources,
			'dashboards' => $this->dashboards,
		];
	}
}
