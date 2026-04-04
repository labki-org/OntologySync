<?php

namespace MediaWiki\Extension\OntologySync\Service;

/**
 * Parses semantic annotations from .wikitext files in the labki-ontology repo.
 *
 * Extracts [[Property::Value]] annotations from between OntologySync markers,
 * and [[Category:X]] annotations from outside the markers.
 */
class WikitextParser {

	private const MARKER_START = '<!-- OntologySync Start -->';
	private const MARKER_END = '<!-- OntologySync End -->';

	private RepoInspector $repoInspector;

	/** @var array<string, ?array> Cache of parsed entities keyed by "entityType/entityKey" */
	private array $cache = [];

	public function __construct( RepoInspector $repoInspector ) {
		$this->repoInspector = $repoInspector;
	}

	/**
	 * Parse a category wikitext file.
	 *
	 * @return ?array Parsed category with parents, required/optional properties and subobjects
	 */
	public function parseCategory( string $repoPath, string $entityKey ): ?array {
		$cacheKey = "categories/$entityKey";
		if ( array_key_exists( $cacheKey, $this->cache ) ) {
			return $this->cache[$cacheKey];
		}

		$wikitext = $this->readEntityWikitext( $repoPath, 'categories', $entityKey );
		if ( $wikitext === null ) {
			$this->cache[$cacheKey] = null;
			return null;
		}

		$annotations = $this->extractAnnotations( $wikitext );

		$result = [
			'parents' => $this->resolveRefs( $annotations, 'Has parent category', 'Category' ),
			'required_properties' => $this->resolveRefs( $annotations, 'Has required property', 'Property' ),
			'optional_properties' => $this->resolveRefs( $annotations, 'Has optional property', 'Property' ),
			'required_subobjects' => $this->resolveRefs( $annotations, 'Has required subobject', 'Subobject' ),
			'optional_subobjects' => $this->resolveRefs( $annotations, 'Has optional subobject', 'Subobject' ),
		];

		$this->cache[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Parse a subobject wikitext file.
	 *
	 * @return ?array{required_properties: string[], optional_properties: string[]}
	 */
	public function parseSubobject( string $repoPath, string $entityKey ): ?array {
		$cacheKey = "subobjects/$entityKey";
		if ( array_key_exists( $cacheKey, $this->cache ) ) {
			return $this->cache[$cacheKey];
		}

		$wikitext = $this->readEntityWikitext( $repoPath, 'subobjects', $entityKey );
		if ( $wikitext === null ) {
			$this->cache[$cacheKey] = null;
			return null;
		}

		$annotations = $this->extractAnnotations( $wikitext );

		$result = [
			'required_properties' => $this->resolveRefs( $annotations, 'Has required property', 'Property' ),
			'optional_properties' => $this->resolveRefs( $annotations, 'Has optional property', 'Property' ),
		];

		$this->cache[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Parse a property wikitext file for template reference.
	 *
	 * @return ?array{has_display_template: ?string}
	 */
	public function parseProperty( string $repoPath, string $entityKey ): ?array {
		$cacheKey = "properties/$entityKey";
		if ( array_key_exists( $cacheKey, $this->cache ) ) {
			return $this->cache[$cacheKey];
		}

		$wikitext = $this->readEntityWikitext( $repoPath, 'properties', $entityKey );
		if ( $wikitext === null ) {
			$this->cache[$cacheKey] = null;
			return null;
		}

		$annotations = $this->extractAnnotations( $wikitext );
		$templateRefs = $this->resolveRefs( $annotations, 'Has template', 'Template' );

		$result = [
			'has_display_template' => $templateRefs[0] ?? null,
		];

		$this->cache[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Discover resources whose category matches any in the given set.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string[] $categorySet Category keys to match against
	 * @return string[] Resource entity keys
	 */
	public function discoverResources( string $repoPath, array $categorySet ): array {
		$resourcesDir = $repoPath . '/resources';
		if ( !is_dir( $resourcesDir ) ) {
			return [];
		}

		$categoryLookup = array_flip( $categorySet );
		$resources = [];

		$this->scanResourceDir( $resourcesDir, $resourcesDir, $categoryLookup, $resources );

		sort( $resources );
		return $resources;
	}

	/**
	 * Extract [[Property::Value]] annotations from between OntologySync markers.
	 *
	 * @return array<string, string[]> Property name => array of values
	 */
	private function extractAnnotations( string $wikitext ): array {
		$startPos = strpos( $wikitext, self::MARKER_START );
		$endPos = strpos( $wikitext, self::MARKER_END );

		if ( $startPos === false || $endPos === false || $endPos <= $startPos ) {
			return [];
		}

		$block = substr(
			$wikitext,
			$startPos + strlen( self::MARKER_START ),
			$endPos - $startPos - strlen( self::MARKER_START )
		);

		$annotations = [];
		foreach ( explode( "\n", $block ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^\[\[([^:\[\]]+)::(.+)\]\]$/', $line, $matches ) ) {
				$annotations[$matches[1]][] = $matches[2];
			}
		}

		return $annotations;
	}

	/**
	 * Extract [[Category:X]] annotations from outside the OntologySync markers.
	 *
	 * @return string[] Category names
	 */
	private function extractCategories( string $wikitext ): array {
		// Remove the marker block so we only get categories outside it
		$startPos = strpos( $wikitext, self::MARKER_START );
		$endPos = strpos( $wikitext, self::MARKER_END );

		$outside = $wikitext;
		if ( $startPos !== false && $endPos !== false && $endPos > $startPos ) {
			$outside = substr( $wikitext, 0, $startPos )
				. substr( $wikitext, $endPos + strlen( self::MARKER_END ) );
		}

		$categories = [];
		if ( preg_match_all( '/\[\[Category:([^\]]+)\]\]/', $outside, $matches ) ) {
			$categories = $matches[1];
		}

		return $categories;
	}

	/**
	 * Resolve annotation values to entity keys by stripping namespace prefixes.
	 *
	 * @param array<string, string[]> $annotations
	 * @param string $annotationName The annotation property name
	 * @param string $namespace Namespace prefix to strip (e.g. "Property", "Category")
	 * @return string[] Entity keys
	 */
	private function resolveRefs( array $annotations, string $annotationName, string $namespace ): array {
		$values = $annotations[$annotationName] ?? [];
		$result = [];

		foreach ( $values as $value ) {
			$result[] = $this->stripNamespaceAndConvert( $value, $namespace );
		}

		return $result;
	}

	/**
	 * Strip namespace prefix and convert page name to entity key.
	 *
	 * "Property:Has name" -> "Has_name"
	 * "Category:Agent" -> "Agent"
	 */
	private function stripNamespaceAndConvert( string $value, string $namespace ): string {
		$prefix = $namespace . ':';
		if ( str_starts_with( $value, $prefix ) ) {
			$value = substr( $value, strlen( $prefix ) );
		}

		// Convert spaces to underscores (page name -> entity key)
		return str_replace( ' ', '_', $value );
	}

	/**
	 * Read a wikitext file for a given entity.
	 */
	private function readEntityWikitext( string $repoPath, string $entityType, string $entityKey ): ?string {
		$path = $this->repoInspector->getEntityFile( $repoPath, $entityType, $entityKey );
		if ( $path === null ) {
			return null;
		}

		$content = file_get_contents( $path );
		return $content !== false ? $content : null;
	}

	/**
	 * Recursively scan resource directory for matching files.
	 *
	 * @param string $dir Current directory to scan
	 * @param string $baseDir Base resources directory (for computing relative entity keys)
	 * @param array<string, int> $categoryLookup Flipped category set for fast lookup
	 * @param string[] &$resources Accumulated resource entity keys
	 */
	private function scanResourceDir(
		string $dir, string $baseDir, array $categoryLookup, array &$resources
	): void {
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}

			$fullPath = $dir . '/' . $entry;
			if ( is_dir( $fullPath ) ) {
				$this->scanResourceDir( $fullPath, $baseDir, $categoryLookup, $resources );
				continue;
			}

			if ( !str_ends_with( $entry, '.wikitext' ) ) {
				continue;
			}

			$content = file_get_contents( $fullPath );
			if ( $content === false ) {
				continue;
			}

			$categories = $this->extractCategories( $content );

			// Filter out management categories
			$managementCategories = [
				'OntologySync-managed',
				'OntologySync-managed-property',
				'OntologySync-managed-subobject',
			];

			foreach ( $categories as $cat ) {
				if ( in_array( $cat, $managementCategories, true ) ) {
					continue;
				}
				if ( isset( $categoryLookup[$cat] ) ) {
					// Compute entity key: relative path from resources/ without .wikitext
					$relativePath = substr( $fullPath, strlen( $baseDir ) + 1 );
					$entityKey = substr( $relativePath, 0, -strlen( '.wikitext' ) );
					$resources[] = $entityKey;
					break;
				}
			}
		}
	}
}
