<?php

namespace MediaWiki\Extension\OntologySync\Service;

/**
 * Resolves namespace string constants from vocab.json to MediaWiki namespace IDs.
 */
class PageResolver {

	/** @var array<string,int> */
	private const NAMESPACE_MAP = [
		'NS_CATEGORY' => NS_CATEGORY,
		'NS_TEMPLATE' => NS_TEMPLATE,
	];

	/**
	 * Resolve a namespace constant string to its integer ID.
	 *
	 * Handles constants that are only available when SMW or OntologySync is loaded.
	 */
	public function resolveNamespace( string $nsConstant ): ?int {
		// Static well-known MW namespaces
		if ( isset( self::NAMESPACE_MAP[$nsConstant] ) ) {
			return self::NAMESPACE_MAP[$nsConstant];
		}

		// SMW property namespace
		if ( $nsConstant === 'SMW_NS_PROPERTY' && defined( 'SMW_NS_PROPERTY' ) ) {
			return SMW_NS_PROPERTY;
		}

		// SemanticSchemas subobject namespace
		if ( $nsConstant === 'NS_SUBOBJECT' && defined( 'NS_SUBOBJECT' ) ) {
			return NS_SUBOBJECT;
		}

		// OntologySync custom namespaces
		if ( $nsConstant === 'NS_ONTOLOGY_DASHBOARD' && defined( 'NS_ONTOLOGY_DASHBOARD' ) ) {
			return NS_ONTOLOGY_DASHBOARD;
		}
		if ( $nsConstant === 'NS_ONTOLOGY_RESOURCE' && defined( 'NS_ONTOLOGY_RESOURCE' ) ) {
			return NS_ONTOLOGY_RESOURCE;
		}

		// Try as a PHP constant directly
		if ( defined( $nsConstant ) ) {
			return constant( $nsConstant );
		}

		return null;
	}

	/**
	 * Get a human-readable label for a namespace ID.
	 */
	public function getNamespaceLabel( int $nsId ): string {
		$labels = [
			NS_CATEGORY => 'Category',
			NS_TEMPLATE => 'Template',
		];

		if ( isset( $labels[$nsId] ) ) {
			return $labels[$nsId];
		}

		if ( defined( 'SMW_NS_PROPERTY' ) && $nsId === SMW_NS_PROPERTY ) {
			return 'Property';
		}
		if ( defined( 'NS_SUBOBJECT' ) && $nsId === NS_SUBOBJECT ) {
			return 'Subobject';
		}
		if ( defined( 'NS_ONTOLOGY_DASHBOARD' ) && $nsId === NS_ONTOLOGY_DASHBOARD ) {
			return 'OntologyDashboard';
		}
		if ( defined( 'NS_ONTOLOGY_RESOURCE' ) && $nsId === NS_ONTOLOGY_RESOURCE ) {
			return 'OntologyResource';
		}

		return "NS_$nsId";
	}
}
