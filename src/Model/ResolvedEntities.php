<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object holding fully resolved entity lists for a set of categories.
 *
 * Produced by DependencyResolver, consumed by VocabBuilder.
 */
class ResolvedEntities {

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
	 * @param string[] $categories
	 * @param string[] $properties
	 * @param string[] $subobjects
	 * @param string[] $templates
	 * @param string[] $resources
	 * @param string[] $dashboards
	 */
	public function __construct(
		array $categories,
		array $properties,
		array $subobjects,
		array $templates,
		array $resources,
		array $dashboards
	) {
		$this->categories = $categories;
		$this->properties = $properties;
		$this->subobjects = $subobjects;
		$this->templates = $templates;
		$this->resources = $resources;
		$this->dashboards = $dashboards;
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
	 * Get all entities grouped by type, matching VocabBuilder's TYPE_NS_MAP keys.
	 *
	 * @return array<string, string[]>
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
