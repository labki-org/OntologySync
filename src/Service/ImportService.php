<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\VocabResult;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Extension\OntologySync\Store\ModuleStore;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Orchestrates install, update, and remove operations for ontology bundles.
 */
class ImportService {

	private BundleStore $bundleStore;
	private ModuleStore $moduleStore;
	private PageStore $pageStore;
	private RepoInspector $repoInspector;
	private StagingService $stagingService;
	private HashService $hashService;
	private PageResolver $pageResolver;
	private VocabBuilder $vocabBuilder;
	private ChangeClassificationService $changeClassifier;

	public function __construct(
		BundleStore $bundleStore,
		ModuleStore $moduleStore,
		PageStore $pageStore,
		RepoInspector $repoInspector,
		StagingService $stagingService,
		HashService $hashService,
		PageResolver $pageResolver,
		VocabBuilder $vocabBuilder,
		ChangeClassificationService $changeClassifier
	) {
		$this->bundleStore = $bundleStore;
		$this->moduleStore = $moduleStore;
		$this->pageStore = $pageStore;
		$this->repoInspector = $repoInspector;
		$this->stagingService = $stagingService;
		$this->hashService = $hashService;
		$this->pageResolver = $pageResolver;
		$this->vocabBuilder = $vocabBuilder;
		$this->changeClassifier = $changeClassifier;
	}

	/**
	 * Preview what will be imported for a bundle.
	 *
	 * @return array{
	 *   changes: ChangeClassification[],
	 *   warnings: string[],
	 *   newCount: int,
	 *   changedCount: int,
	 *   deletedCount: int,
	 *   unchangedCount: int,
	 *   userModifiedCount: int,
	 *   adminDecisionCount: int
	 * }
	 */
	public function prepareInstall( string $repoPath, string $bundleId ): array {
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );
		if ( $bundle === null ) {
			return $this->emptyPreview( [ 'Bundle not found' ] );
		}

		$vocabResult = $this->vocabBuilder->buildVocab( $repoPath, $bundle->getModules() );
		if ( $vocabResult->getEntries() === [] ) {
			return $this->emptyPreview( [ 'No entities found in bundle modules' ] );
		}

		// Check if bundle is already installed
		$existing = $this->bundleStore->getInstalledBundle( $bundleId );
		$bundleDbId = $existing !== null ? (int)$existing['osb_id'] : null;

		$changes = $this->changeClassifier->classifyChanges(
			$repoPath, $vocabResult, $bundleDbId
		);

		$warnings = [];
		$newCount = 0;
		$changedCount = 0;
		$deletedCount = 0;
		$unchangedCount = 0;
		$userModifiedCount = 0;
		$adminDecisionCount = 0;

		foreach ( $changes as $change ) {
			switch ( $change->getChangeType() ) {
				case 'new':
					$newCount++;
					break;
				case 'changed':
					$changedCount++;
					break;
				case 'deleted':
					$deletedCount++;
					break;
				case 'none':
					$unchangedCount++;
					break;
			}

			if ( $change->isUserModified() ) {
				$userModifiedCount++;
				$nsLabel = $this->resolveNsLabel( $change->getNamespace() );
				$warnings[] = $nsLabel . ':' . $change->getPage()
					. ' has been modified by users';
			}

			if ( $change->requiresAdminDecision() ) {
				$adminDecisionCount++;
			}
		}

		return [
			'changes' => $changes,
			'warnings' => $warnings,
			'newCount' => $newCount,
			'changedCount' => $changedCount,
			'deletedCount' => $deletedCount,
			'unchangedCount' => $unchangedCount,
			'userModifiedCount' => $userModifiedCount,
			'adminDecisionCount' => $adminDecisionCount,
		];
	}

	/**
	 * Stage a bundle for import into its own subdirectory.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string $bundleId Bundle identifier
	 * @param string $stagingRoot Staging root directory
	 * @param string[] $skipPages Pages to exclude (format: "NS_CONSTANT:PageName")
	 * @return bool Success
	 */
	public function stageBundle(
		string $repoPath,
		string $bundleId,
		string $stagingRoot,
		array $skipPages = []
	): bool {
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );
		if ( $bundle === null ) {
			return false;
		}

		$vocabResult = $this->vocabBuilder->buildVocab( $repoPath, $bundle->getModules() );

		// Gather existing page content for pre-merge
		$existingPageContent = $this->gatherExistingPageContent( $vocabResult );

		return $this->stagingService->stageBundle(
			$repoPath, $bundleId, $stagingRoot, $vocabResult,
			$existingPageContent, $skipPages
		);
	}

	/**
	 * Record the install in DB after update.php has run.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string $bundleId Bundle identifier
	 * @param string $commit Git commit SHA
	 * @param int $userId Installing user's MW user ID
	 * @param string $stagingRoot Staging root directory (bundle subdir is appended)
	 * @return int The bundle's DB ID
	 */
	public function recordInstall(
		string $repoPath,
		string $bundleId,
		string $commit,
		int $userId,
		string $stagingRoot
	): int {
		$stagingPath = $this->stagingService->getBundleStagingPath( $stagingRoot, $bundleId );
		$now = wfTimestampNow();
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );

		// Upsert bundle record
		$existing = $this->bundleStore->getInstalledBundle( $bundleId );
		if ( $existing !== null ) {
			$this->bundleStore->updateBundle( $bundleId, [
				'osb_label' => $bundle ? $bundle->getLabel() : $bundleId,
				'osb_description' => $bundle ? $bundle->getDescription() : '',
				'osb_repo_commit' => $commit,
				'osb_updated_at' => $now,
				'osb_status' => 'installed',
			] );
			$bundleDbId = (int)$existing['osb_id'];

			// Clear old module and page records for re-recording
			$this->moduleStore->deleteModulesForBundle( $bundleDbId );
			$this->pageStore->deletePagesForBundle( $bundleDbId );
		} else {
			$bundleDbId = $this->bundleStore->insertBundle( [
				'osb_bundle_id' => $bundleId,
				'osb_label' => $bundle ? $bundle->getLabel() : $bundleId,
				'osb_description' => $bundle ? $bundle->getDescription() : '',
				'osb_repo_commit' => $commit,
				'osb_installed_by' => $userId,
				'osb_installed_at' => $now,
				'osb_updated_at' => $now,
				'osb_status' => 'installed',
			] );
		}

		// Record modules
		$moduleDbIds = $this->recordModules( $repoPath, $bundleId, $bundleDbId, $now );

		// Record pages from the staged vocab.json
		$vocabJson = $this->repoInspector->findVocabJson( $stagingPath );
		if ( $vocabJson !== null ) {
			$this->recordPages( $vocabJson, $stagingPath, $bundleDbId, $moduleDbIds, $now );
		}

		return $bundleDbId;
	}

	/**
	 * Remove a bundle: delete all managed pages and DB records.
	 *
	 * @return array{deletedPages: int, errors: string[]}
	 */
	public function removeBundle( string $bundleId ): array {
		$existing = $this->bundleStore->getInstalledBundle( $bundleId );
		if ( $existing === null ) {
			return [ 'deletedPages' => 0, 'errors' => [ 'Bundle not installed' ] ];
		}

		$bundleDbId = (int)$existing['osb_id'];
		$pages = $this->pageStore->getPagesForBundle( $bundleDbId );
		$deletedPages = 0;

		// Count wiki pages (actual deletion handled by caller)
		foreach ( $pages as $page ) {
			$title = Title::makeTitleSafe(
				(int)$page['osp_page_namespace'],
				$page['osp_page_name']
			);
			if ( $title !== null && $title->exists() ) {
				$deletedPages++;
			}
		}

		// Clean up DB records (cascade: pages -> modules -> bundle)
		$this->pageStore->deletePagesForBundle( $bundleDbId );
		$this->moduleStore->deleteModulesForBundle( $bundleDbId );
		$this->bundleStore->deleteBundle( $bundleId );

		return [ 'deletedPages' => $deletedPages, 'errors' => [] ];
	}

	/**
	 * Get list of pages that have been modified by users since import.
	 *
	 * @return array[] Each with 'page', 'namespace', 'stored_hash', 'current_hash'
	 */
	public function getModifiedPages( int $bundleDbId ): array {
		$pages = $this->pageStore->getPagesForBundle( $bundleDbId );
		$modified = [];

		foreach ( $pages as $page ) {
			$nsId = (int)$page['osp_page_namespace'];
			$isModified = $this->isPageModified( $page, $nsId, $page['osp_page_name'] );
			if ( $isModified ) {
				$modified[] = [
					'page' => $page['osp_page_name'],
					'namespace' => $this->pageResolver->getNamespaceLabel( $nsId ),
					'namespaceId' => $nsId,
					'stored_hash' => $page['osp_content_hash'],
				];
			}
		}

		return $modified;
	}

	/**
	 * Check if a tracked page has been modified by comparing hashes.
	 */
	private function isPageModified( array $pageRecord, int $nsId, string $pageName ): bool {
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

		$text = $content->serialize();
		$currentHash = $this->hashService->hashPageContent( $text );

		return $currentHash !== null && $currentHash !== $storedHash;
	}

	/**
	 * Gather existing wiki page content for entities in the vocab result.
	 *
	 * @param VocabResult $vocabResult Built vocabulary
	 * @return array<string,string> Map of "NS_CONSTANT:PageName" => wikitext content
	 */
	private function gatherExistingPageContent( VocabResult $vocabResult ): array {
		$content = [];
		foreach ( $vocabResult->getEntries() as $entry ) {
			$nsId = $this->pageResolver->resolveNamespace( $entry->getNamespace() );
			if ( $nsId === null ) {
				continue;
			}

			$title = Title::makeTitleSafe( $nsId, $entry->getPage() );
			if ( $title === null || !$title->exists() ) {
				continue;
			}

			$wikiPage = MediaWikiServices::getInstance()
				->getWikiPageFactory()
				->newFromTitle( $title );
			$pageContent = $wikiPage->getContent();
			if ( $pageContent === null ) {
				continue;
			}

			$key = $entry->getNamespace() . ':' . $entry->getPage();
			$content[$key] = $pageContent->serialize();
		}

		return $content;
	}

	/**
	 * @return array<string,int> Module ID => DB ID
	 */
	private function recordModules(
		string $repoPath,
		string $bundleId,
		int $bundleDbId,
		string $now
	): array {
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );
		if ( $bundle === null ) {
			return [];
		}

		$moduleDbIds = [];
		foreach ( $bundle->getModules() as $moduleId ) {
			$module = $this->repoInspector->getModule( $repoPath, $moduleId );
			$moduleDbIds[$moduleId] = $this->moduleStore->insertModule( [
				'osm_module_id' => $moduleId,
				'osm_bundle_id' => $bundleDbId,
				'osm_label' => $module ? $module->getLabel() : $moduleId,
				'osm_installed_at' => $now,
			] );
		}

		return $moduleDbIds;
	}

	private function recordPages(
		string $vocabJsonPath,
		string $stagingPath,
		int $bundleDbId,
		array $moduleDbIds,
		string $now
	): void {
		$entries = $this->repoInspector->getImportEntries( $vocabJsonPath );
		// Use first module ID if available (bundles typically have one module)
		$defaultModuleId = !empty( $moduleDbIds ) ? reset( $moduleDbIds ) : 0;

		foreach ( $entries as $entry ) {
			$nsId = $this->pageResolver->resolveNamespace( $entry->getNamespace() );
			if ( $nsId === null ) {
				continue;
			}

			// Compute content hash from the staged .wikitext file
			$filePath = $stagingPath . '/' . $entry->getImportFrom();
			$hash = $this->hashService->hashWikitextFile( $filePath );

			// Resolve wiki page ID if the page exists
			$wikiPageId = null;
			$title = Title::makeTitleSafe( $nsId, $entry->getPage() );
			if ( $title !== null && $title->exists() ) {
				$wikiPageId = $title->getArticleID();
			}

			$this->pageStore->insertPage( [
				'osp_bundle_id' => $bundleDbId,
				'osp_module_id' => $defaultModuleId,
				'osp_page_name' => $entry->getPage(),
				'osp_page_namespace' => $nsId,
				'osp_wiki_page_id' => $wikiPageId,
				'osp_content_hash' => $hash,
				'osp_source_file' => $entry->getImportFrom(),
				'osp_installed_at' => $now,
				'osp_updated_at' => $now,
			] );
		}
	}

	/**
	 * Resolve a namespace constant to its human-readable label.
	 */
	private function resolveNsLabel( string $nsConstant ): string {
		$nsId = $this->pageResolver->resolveNamespace( $nsConstant );
		if ( $nsId !== null ) {
			return $this->pageResolver->getNamespaceLabel( $nsId );
		}
		return $nsConstant;
	}

	/**
	 * Return an empty preview structure.
	 *
	 * @param string[] $warnings
	 */
	private function emptyPreview( array $warnings ): array {
		return [
			'changes' => [],
			'warnings' => $warnings,
			'newCount' => 0,
			'changedCount' => 0,
			'deletedCount' => 0,
			'unchangedCount' => 0,
			'userModifiedCount' => 0,
			'adminDecisionCount' => 0,
		];
	}
}
