<?php

namespace MediaWiki\Extension\OntologySync\Service;

/**
 * Parses template call syntax from .wikitext files in the labki-ontology repo.
 *
 * Extracts {{Template|param=value}} calls from between OntologySync markers,
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

		[ , $params ] = $this->extractTemplateParams( $wikitext );

		$result = [
			'parents' => $this->splitAndConvert( $params['has_parent_category'] ?? '' ),
			'required_properties' => $this->splitAndConvert( $params['has_required_property'] ?? '' ),
			'optional_properties' => $this->splitAndConvert( $params['has_optional_property'] ?? '' ),
			'required_subobjects' => $this->splitAndConvert( $params['has_required_subobject'] ?? '' ),
			'optional_subobjects' => $this->splitAndConvert( $params['has_optional_subobject'] ?? '' ),
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

		[ , $params ] = $this->extractTemplateParams( $wikitext );

		$result = [
			'required_properties' => $this->splitAndConvert( $params['has_required_property'] ?? '' ),
			'optional_properties' => $this->splitAndConvert( $params['has_optional_property'] ?? '' ),
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

		[ , $params ] = $this->extractTemplateParams( $wikitext );

		$templateValue = $params['has_template'] ?? null;
		$result = [
			'has_display_template' => $templateValue !== null
				? $this->pageNameToEntityKey( $templateValue )
				: null,
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
	 * Extract template name and parameters from a template call within OntologySync markers.
	 *
	 * Parses content like:
	 *   <!-- OntologySync Start -->
	 *   {{Property
	 *   |has_type=Text
	 *   |has_description=A description
	 *   }}
	 *   <!-- OntologySync End -->
	 *
	 * @return array{0: string, 1: array<string, string>} [template_name, params]
	 */
	private function extractTemplateParams( string $wikitext ): array {
		$startPos = strpos( $wikitext, self::MARKER_START );
		$endPos = strpos( $wikitext, self::MARKER_END );

		if ( $startPos === false || $endPos === false || $endPos <= $startPos ) {
			return [ '', [] ];
		}

		$block = trim( substr(
			$wikitext,
			$startPos + strlen( self::MARKER_START ),
			$endPos - $startPos - strlen( self::MARKER_START )
		) );

		// Must be a template call
		if ( !str_starts_with( $block, '{{' ) || !str_ends_with( $block, '}}' ) ) {
			return [ '', [] ];
		}

		// Strip {{ and }}
		$inner = substr( $block, 2, -2 );

		// Split on | at start of line
		$parts = preg_split( '/\n\|/', $inner );
		$templateName = trim( $parts[0] );

		$params = [];
		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$eqPos = strpos( $parts[$i], '=' );
			if ( $eqPos !== false ) {
				$key = trim( substr( $parts[$i], 0, $eqPos ) );
				$value = trim( substr( $parts[$i], $eqPos + 1 ) );
				if ( $value !== '' ) {
					$params[$key] = $value;
				}
			}
		}

		return [ $templateName, $params ];
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
	 * Split a comma-separated value string and convert each page name to an entity key.
	 *
	 * "Has name, Has email" -> ["Has_name", "Has_email"]
	 *
	 * @return string[] Entity keys
	 */
	private function splitAndConvert( string $value ): array {
		if ( $value === '' ) {
			return [];
		}

		$parts = explode( ',', $value );
		$result = [];
		foreach ( $parts as $part ) {
			$trimmed = trim( $part );
			if ( $trimmed !== '' ) {
				$result[] = $this->pageNameToEntityKey( $trimmed );
			}
		}
		return $result;
	}

	/**
	 * Convert a wiki page name (spaces) to an entity key (underscores).
	 *
	 * "Has name" -> "Has_name"
	 */
	private function pageNameToEntityKey( string $pageName ): string {
		return str_replace( ' ', '_', $pageName );
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
