<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\ResolvedEntities;

/**
 * Resolves the full dependency chain from a set of manually-picked categories.
 *
 * Given categories from modules, transitively resolves parent categories,
 * then collects all properties, subobjects, templates, and resources.
 */
class DependencyResolver {

	private WikitextParser $wikitextParser;
	private RepoInspector $repoInspector;

	public function __construct(
		WikitextParser $wikitextParser,
		RepoInspector $repoInspector
	) {
		$this->wikitextParser = $wikitextParser;
		$this->repoInspector = $repoInspector;
	}

	/** @var string Dashboard category auto-added when dashboards are present */
	private const DASHBOARD_CATEGORY = 'Dashboard';

	/**
	 * Resolve all entities needed for a set of manually-picked categories.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string[] $manualCategories Category keys from module definitions
	 * @param string[] $dashboards Dashboard keys from module/bundle definitions
	 * @return ResolvedEntities
	 */
	public function resolve(
		string $repoPath,
		array $manualCategories,
		array $dashboards
	): ResolvedEntities {
		// Auto-include Dashboard category when any dashboards are present
		if ( $dashboards !== [] && !in_array( self::DASHBOARD_CATEGORY, $manualCategories, true ) ) {
			$manualCategories[] = self::DASHBOARD_CATEGORY;
		}

		// Phase 1: Transitively resolve parent categories
		$allCategories = $this->resolveParentCategories( $repoPath, $manualCategories );

		// Phase 2: Collect properties and subobjects from all categories
		$properties = [];
		$subobjects = [];

		foreach ( $allCategories as $catKey ) {
			$cat = $this->wikitextParser->parseCategory( $repoPath, $catKey );
			if ( $cat === null ) {
				continue;
			}

			foreach ( $cat['required_properties'] as $prop ) {
				$properties[$prop] = true;
			}
			foreach ( $cat['optional_properties'] as $prop ) {
				$properties[$prop] = true;
			}
			foreach ( $cat['required_subobjects'] as $sub ) {
				$subobjects[$sub] = true;
			}
			foreach ( $cat['optional_subobjects'] as $sub ) {
				$subobjects[$sub] = true;
			}
		}

		// Phase 3: Collect properties from subobjects
		foreach ( array_keys( $subobjects ) as $subKey ) {
			$sub = $this->wikitextParser->parseSubobject( $repoPath, $subKey );
			if ( $sub === null ) {
				continue;
			}

			foreach ( $sub['required_properties'] as $prop ) {
				$properties[$prop] = true;
			}
			foreach ( $sub['optional_properties'] as $prop ) {
				$properties[$prop] = true;
			}
		}

		// Phase 4: Collect templates from properties
		$templates = [];
		foreach ( array_keys( $properties ) as $propKey ) {
			$prop = $this->wikitextParser->parseProperty( $repoPath, $propKey );
			if ( $prop === null ) {
				continue;
			}

			if ( $prop['has_display_template'] !== null ) {
				$templates[$prop['has_display_template']] = true;
			}
		}

		// Phase 5: Discover resources
		$resources = $this->wikitextParser->discoverResources( $repoPath, $allCategories );

		// Build sorted, deduplicated result
		$categoryList = $allCategories;
		sort( $categoryList );

		$propertyList = array_keys( $properties );
		sort( $propertyList );

		$subobjectList = array_keys( $subobjects );
		sort( $subobjectList );

		$templateList = array_keys( $templates );
		sort( $templateList );

		$dashboardList = array_unique( $dashboards );
		sort( $dashboardList );

		return new ResolvedEntities(
			$categoryList,
			$propertyList,
			$subobjectList,
			$templateList,
			$resources,
			$dashboardList
		);
	}

	/**
	 * Transitively resolve parent categories using BFS.
	 *
	 * @param string $repoPath
	 * @param string[] $startCategories
	 * @return string[] All categories including transitive parents
	 */
	private function resolveParentCategories( string $repoPath, array $startCategories ): array {
		$visited = [];
		$queue = $startCategories;

		while ( $queue !== [] ) {
			$current = array_shift( $queue );

			if ( isset( $visited[$current] ) ) {
				continue;
			}
			$visited[$current] = true;

			$cat = $this->wikitextParser->parseCategory( $repoPath, $current );
			if ( $cat === null ) {
				continue;
			}

			foreach ( $cat['parents'] as $parent ) {
				if ( !isset( $visited[$parent] ) ) {
					$queue[] = $parent;
				}
			}
		}

		return array_keys( $visited );
	}
}
