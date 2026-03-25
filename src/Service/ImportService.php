<?php

namespace MediaWiki\Extension\OntologySync\Service;

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

	public function __construct(
		BundleStore $bundleStore,
		ModuleStore $moduleStore,
		PageStore $pageStore,
		RepoInspector $repoInspector,
		StagingService $stagingService,
		HashService $hashService,
		PageResolver $pageResolver
	) {
		$this->bundleStore = $bundleStore;
		$this->moduleStore = $moduleStore;
		$this->pageStore = $pageStore;
		$this->repoInspector = $repoInspector;
		$this->stagingService = $stagingService;
		$this->hashService = $hashService;
		$this->pageResolver = $pageResolver;
	}

	/**
	 * Preview what will be imported for a bundle.
	 *
	 * @return array{pages: array, warnings: array, newCount: int, updateCount: int, modifiedCount: int}
	 */
	public function prepareInstall( string $repoPath, string $bundleId, string $version ): array {
		$artifactPath = $this->repoInspector->getBundleVersionArtifact( $repoPath, $bundleId, $version );
		if ( $artifactPath === null ) {
			return [ 'pages' => [], 'warnings' => [ 'Bundle artifact not found' ],
				'newCount' => 0, 'updateCount' => 0, 'modifiedCount' => 0 ];
		}

		$vocabJson = $this->repoInspector->findVocabJson( $artifactPath );
		if ( $vocabJson === null ) {
			return [ 'pages' => [], 'warnings' => [ 'No vocab.json found in artifact' ],
				'newCount' => 0, 'updateCount' => 0, 'modifiedCount' => 0 ];
		}

		$entries = $this->repoInspector->getImportEntries( $vocabJson );
		$pages = [];
		$warnings = [];
		$newCount = 0;
		$updateCount = 0;
		$modifiedCount = 0;

		foreach ( $entries as $entry ) {
			$nsId = $this->pageResolver->resolveNamespace( $entry->getNamespace() );
			$nsLabel = $nsId !== null ? $this->pageResolver->getNamespaceLabel( $nsId ) : $entry->getNamespace();
			$existing = null;
			$isModified = false;

			if ( $nsId !== null ) {
				$existing = $this->pageStore->getPageByTitle( $nsId, $entry->getPage() );
			}

			if ( $existing !== null ) {
				$updateCount++;
				// Check if the page has been user-modified
				$isModified = $this->isPageModified( $existing, $nsId, $entry->getPage() );
				if ( $isModified ) {
					$modifiedCount++;
					$warnings[] = $nsLabel . ':' . $entry->getPage() . ' has been modified by users';
				}
			} else {
				$newCount++;
			}

			$pages[] = [
				'page' => $entry->getPage(),
				'namespace' => $nsLabel,
				'namespaceId' => $nsId,
				'importFrom' => $entry->getImportFrom(),
				'isNew' => $existing === null,
				'isModified' => $isModified,
			];
		}

		return [
			'pages' => $pages,
			'warnings' => $warnings,
			'newCount' => $newCount,
			'updateCount' => $updateCount,
			'modifiedCount' => $modifiedCount,
		];
	}

	/**
	 * Stage a bundle for import.
	 */
	public function stageBundle(
		string $repoPath,
		string $bundleId,
		string $version,
		string $stagingPath
	): bool {
		$artifactPath = $this->repoInspector->getBundleVersionArtifact( $repoPath, $bundleId, $version );
		if ( $artifactPath === null ) {
			return false;
		}

		return $this->stagingService->buildStaging( $artifactPath, $stagingPath );
	}

	/**
	 * Record the install in DB after update.php has run.
	 *
	 * @param string $repoPath Path to the labki-ontology clone
	 * @param string $bundleId Bundle identifier
	 * @param string $version Bundle version
	 * @param string $commit Git commit SHA
	 * @param int $userId Installing user's MW user ID
	 * @param string $stagingPath Path to staged artifact
	 * @return int The bundle's DB ID
	 */
	public function recordInstall(
		string $repoPath,
		string $bundleId,
		string $version,
		string $commit,
		int $userId,
		string $stagingPath
	): int {
		$now = wfTimestampNow();
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );

		// Upsert bundle record
		$existing = $this->bundleStore->getInstalledBundle( $bundleId );
		if ( $existing !== null ) {
			$this->bundleStore->updateBundle( $bundleId, [
				'osb_version' => $version,
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
				'osb_version' => $version,
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
			$this->recordPages( $vocabJson, $stagingPath, $bundleDbId, $moduleDbIds, $version, $now );
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
		$errors = [];

		// Delete wiki pages
		foreach ( $pages as $page ) {
			$title = Title::makeTitleSafe(
				(int)$page['osp_page_namespace'],
				$page['osp_page_name']
			);
			if ( $title !== null && $title->exists() ) {
				// Page deletion is handled by the caller (Special page or maintenance script)
				// since it requires a user context. We just track the count.
				$deletedPages++;
			}
		}

		// Clean up DB records (cascade: pages → modules → bundle)
		$this->pageStore->deletePagesForBundle( $bundleDbId );
		$this->moduleStore->deleteModulesForBundle( $bundleDbId );
		$this->bundleStore->deleteBundle( $bundleId );

		return [ 'deletedPages' => $deletedPages, 'errors' => $errors ];
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
				'osm_version' => $module ? $module->getVersion() : '',
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
		string $version,
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
				'osp_installed_version' => $version,
				'osp_installed_at' => $now,
				'osp_updated_at' => $now,
			] );
		}
	}
}
