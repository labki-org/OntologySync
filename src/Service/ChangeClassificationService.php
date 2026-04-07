<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\ChangeClassification;
use MediaWiki\Extension\OntologySync\Model\VocabResult;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Classifies changes between the repo state and the installed wiki state.
 *
 * Compares each entity in a VocabResult against what is currently installed
 * in the wiki, producing ChangeClassification objects with impact levels,
 * user modification flags, and admin decision requirements.
 */
class ChangeClassificationService {

	private PageStore $pageStore;
	private HashService $hashService;
	private RepoInspector $repoInspector;
	private PageResolver $pageResolver;

	/** @var string[] Entity types where content is fully managed (no markers) */
	private const FULLY_MANAGED_TYPES = [ 'templates' ];

	/** @var string[] Entity types where marker blocks are always updated */
	private const MARKER_MANAGED_TYPES = [ 'categories', 'properties', 'subobjects', 'dashboards' ];

	public function __construct(
		PageStore $pageStore,
		HashService $hashService,
		RepoInspector $repoInspector,
		PageResolver $pageResolver
	) {
		$this->pageStore = $pageStore;
		$this->hashService = $hashService;
		$this->repoInspector = $repoInspector;
		$this->pageResolver = $pageResolver;
	}

	/**
	 * Classify all changes for a bundle install/update.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param VocabResult $vocabResult Built vocabulary
	 * @param int|null $bundleDbId DB ID of existing bundle, or null for fresh install
	 * @return ChangeClassification[]
	 */
	public function classifyChanges(
		string $repoPath,
		VocabResult $vocabResult,
		?int $bundleDbId
	): array {
		$classifications = [];
		$newEntityKeys = [];

		foreach ( $vocabResult->getEntries() as $entry ) {
			$nsConstant = $entry->getNamespace();
			$nsId = $this->pageResolver->resolveNamespace( $nsConstant );
			$moduleId = $vocabResult->getModuleForEntity( $nsConstant, $entry->getPage() ) ?? '';
			$entityType = $this->nsConstantToEntityType( $nsConstant );

			$newEntityKeys[$nsConstant . ':' . $entry->getPage()] = true;

			if ( $bundleDbId === null ) {
				// Fresh install — all pages are new
				$classifications[] = ChangeClassification::newEntity(
					$entry->getPage(),
					$nsConstant,
					$entityType,
					$entry->getImportFrom(),
					$moduleId
				);
				continue;
			}

			// Update scenario — check existing state
			$pageRecord = null;
			if ( $nsId !== null ) {
				$pageRecord = $this->pageStore->getPageByTitle( $nsId, $entry->getPage() );
			}

			if ( $pageRecord === null ) {
				// New entity in this update
				$classifications[] = ChangeClassification::newEntity(
					$entry->getPage(),
					$nsConstant,
					$entityType,
					$entry->getImportFrom(),
					$moduleId
				);
				continue;
			}

			// Existing entity — compare hashes
			$installedHash = $pageRecord['osp_content_hash'] ?? null;
			$repoFile = $this->repoInspector->getEntityFile(
				$repoPath, $entityType, $entry->getPage()
			);
			$repoHash = $repoFile !== null
				? $this->hashService->hashWikitextFile( $repoFile )
				: null;

			// Check if wiki page has user modifications
			$userModified = $this->isPageUserModified(
				$pageRecord, $nsId, $entry->getPage()
			);

			if ( $repoHash !== null && $installedHash !== null
				&& $repoHash === $installedHash
			) {
				// No change in repo
				$classifications[] = ChangeClassification::unchanged(
					$entry->getPage(),
					$nsConstant,
					$entityType,
					$entry->getImportFrom(),
					$moduleId,
					$userModified
				);
				continue;
			}

			// Content changed in repo
			$requiresAdmin = $this->requiresAdminDecision(
				$entityType, $userModified
			);

			$classifications[] = new ChangeClassification(
				$entry->getPage(),
				$nsConstant,
				$entityType,
				$entry->getImportFrom(),
				$moduleId,
				'changed',
				'minor',
				$userModified,
				$requiresAdmin,
				$userModified
					? 'Repo content changed; page also has user modifications'
					: 'Repo content changed'
			);
		}

		// Detect deletions: pages in DB for this bundle not in new VocabResult
		if ( $bundleDbId !== null ) {
			$existingPages = $this->pageStore->getPagesForBundle( $bundleDbId );
			foreach ( $existingPages as $page ) {
				$nsId = (int)$page['osp_page_namespace'];
				$nsConstant = $this->nsIdToConstant( $nsId );
				$key = $nsConstant . ':' . $page['osp_page_name'];

				if ( !isset( $newEntityKeys[$key] ) ) {
					$entityType = $this->nsConstantToEntityType( $nsConstant );
					$classifications[] = ChangeClassification::deletedEntity(
						$page['osp_page_name'],
						$nsConstant,
						$entityType,
						$page['osp_source_file'] ?? '',
						''
					);
				}
			}
		}

		return $classifications;
	}

	/**
	 * Check if a wiki page has been modified by users since last install.
	 */
	private function isPageUserModified(
		array $pageRecord, ?int $nsId, string $pageName
	): bool {
		if ( $nsId === null ) {
			return false;
		}
		$storedHash = $pageRecord['osp_content_hash'] ?? null;
		if ( $storedHash === null ) {
			return false;
		}

		$title = Title::makeTitleSafe( $nsId, $pageName );
		if ( $title === null || !$title->exists() ) {
			return false;
		}

		$wikiPage = MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromTitle( $title );
		$content = $wikiPage->getContent();
		if ( $content === null ) {
			return false;
		}

		$currentHash = $this->hashService->hashPageContent( $content->serialize() );
		return $currentHash !== null && $currentHash !== $storedHash;
	}

	/**
	 * Determine if admin decision is required for this change.
	 */
	private function requiresAdminDecision(
		string $entityType, bool $userModified
	): bool {
		// Templates and dashboards are fully managed — admin decides on overwrites
		if ( in_array( $entityType, self::FULLY_MANAGED_TYPES, true ) ) {
			return true;
		}

		// Schema pages (categories/properties/subobjects) use markers — always safe
		if ( in_array( $entityType, self::MARKER_MANAGED_TYPES, true ) ) {
			return false;
		}

		// Resources: admin decides if user has modified
		return $userModified;
	}

	/**
	 * Map namespace constant string to entity type.
	 */
	private function nsConstantToEntityType( string $nsConstant ): string {
		$map = [
			'NS_CATEGORY' => 'categories',
			'SMW_NS_PROPERTY' => 'properties',
			'NS_SUBOBJECT' => 'subobjects',
			'NS_TEMPLATE' => 'templates',
			'NS_ONTOLOGY_RESOURCE' => 'resources',
			'NS_ONTOLOGY_DASHBOARD' => 'dashboards',
		];
		return $map[$nsConstant] ?? 'unknown';
	}

	/**
	 * Map namespace ID back to constant string.
	 */
	private function nsIdToConstant( int $nsId ): string {
		if ( $nsId === NS_CATEGORY ) {
			return 'NS_CATEGORY';
		}
		if ( $nsId === NS_TEMPLATE ) {
			return 'NS_TEMPLATE';
		}
		if ( defined( 'SMW_NS_PROPERTY' ) && $nsId === SMW_NS_PROPERTY ) {
			return 'SMW_NS_PROPERTY';
		}
		if ( defined( 'NS_SUBOBJECT' ) && $nsId === NS_SUBOBJECT ) {
			return 'NS_SUBOBJECT';
		}
		if ( defined( 'NS_ONTOLOGY_DASHBOARD' ) && $nsId === NS_ONTOLOGY_DASHBOARD ) {
			return 'NS_ONTOLOGY_DASHBOARD';
		}
		if ( defined( 'NS_ONTOLOGY_RESOURCE' ) && $nsId === NS_ONTOLOGY_RESOURCE ) {
			return 'NS_ONTOLOGY_RESOURCE';
		}
		return 'NS_' . $nsId;
	}
}
