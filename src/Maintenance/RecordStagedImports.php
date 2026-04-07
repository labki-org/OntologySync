<?php

namespace MediaWiki\Extension\OntologySync\Maintenance;

use MediaWiki\Extension\OntologySync\Service\ImportService;
use MediaWiki\Extension\OntologySync\Service\MediaUploadService;
use MediaWiki\Extension\OntologySync\Service\RepoInspector;
use MediaWiki\Extension\OntologySync\Service\StagingService;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWiki\Title\Title;

/**
 * Imports staged OntologySync bundles in a single step.
 *
 * Runs update.php to trigger SMW's content importer, records page metadata,
 * and rebuilds SMW semantic data for all imported pages.
 *
 * Usage:
 *   php maintenance/run.php "MediaWiki\Extension\OntologySync\Maintenance\RecordStagedImports"
 *
 * Options:
 *   --skip-import   Skip running update.php (if already run manually)
 *   --skip-rebuild  Skip SMW semantic data rebuild
 */
class RecordStagedImports extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Imports staged OntologySync bundles: runs update.php, records metadata, ' .
			'and rebuilds SMW semantic data.'
		);
		$this->addOption( 'skip-import', 'Skip running update.php (if already run manually)' );
		$this->addOption( 'skip-rebuild', 'Skip SMW semantic data rebuild' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		/** @var BundleStore $bundleStore */
		$bundleStore = $services->get( 'OntologySync.BundleStore' );
		/** @var ImportService $importService */
		$importService = $services->get( 'OntologySync.ImportService' );
		/** @var StagingService $stagingService */
		$stagingService = $services->get( 'OntologySync.StagingService' );
		/** @var PageStore $pageStore */
		$pageStore = $services->get( 'OntologySync.PageStore' );

		$staged = $bundleStore->getStagedBundles();

		if ( $staged === [] ) {
			$this->output( "OntologySync: No staged bundles found.\n" );
			return;
		}

		$repoPath = $config->get( 'OntologySyncRepoPath' );
		if ( $repoPath === null ) {
			$this->fatalError( 'OntologySync: $wgOntologySyncRepoPath not configured.' );
		}

		$stagingRoot = $stagingService->getStagingPath(
			$config->get( 'OntologySyncStagingPath' ),
			$config->get( 'CacheDirectory' )
		);

		// Step 0: Upload media files from repo
		/** @var RepoInspector $repoInspector */
		$repoInspector = $services->get( 'OntologySync.RepoInspector' );
		/** @var MediaUploadService $mediaUploader */
		$mediaUploader = $services->get( 'OntologySync.MediaUploadService' );

		$mediaFiles = $repoInspector->listMediaFiles( $repoPath );
		if ( $mediaFiles !== [] ) {
			$hashFilePath = $stagingRoot . '/media-hashes.json';
			$existingHashes = $mediaUploader->loadHashes( $hashFilePath );

			$this->output( "Uploading " . count( $mediaFiles ) . " media file(s)...\n" );
			$uploadResult = $mediaUploader->uploadMediaFiles( $mediaFiles, $existingHashes );
			$this->output( "  Uploaded: " . count( $uploadResult['uploaded'] ) . "\n" );
			$this->output( "  Skipped (unchanged): " . count( $uploadResult['skipped'] ) . "\n" );
			foreach ( $uploadResult['errors'] as $err ) {
				$this->error( "  Media upload error: $err\n" );
			}

			// Save updated hashes
			$mediaUploader->saveHashes( $hashFilePath, $uploadResult['hashes'] );
		}

		// Step 1: Run update --quick to trigger SMW content import
		if ( !$this->hasOption( 'skip-import' ) ) {
			$this->runSmwImport();
		}

		// Step 2: Record metadata for each staged bundle
		$allPageTitles = [];

		foreach ( $staged as $bundle ) {
			$bundleId = $bundle['osb_bundle_id'];
			$commit = $bundle['osb_repo_commit'] ?? '';
			$userId = (int)( $bundle['osb_installed_by'] ?? 0 );

			$this->output( "Recording $bundleId (commit $commit)...\n" );

			$bundleDbId = $importService->recordInstall(
				$repoPath, $bundleId, $commit, $userId, $stagingRoot
			);

			// Collect page titles for SMW rebuild
			$pages = $pageStore->getPagesForBundle( $bundleDbId );
			foreach ( $pages as $page ) {
				$title = Title::makeTitleSafe(
					(int)$page['osp_page_namespace'],
					$page['osp_page_name']
				);
				if ( $title !== null && $title->exists() ) {
					$allPageTitles[] = $title->getPrefixedText();
				}
			}

			$stagingService->clearBundleStaging( $stagingRoot, $bundleId );
			$this->output( "$bundleId recorded.\n" );
		}

		// Step 3: Rebuild SMW semantic data for all imported pages
		if ( !$this->hasOption( 'skip-rebuild' ) && $allPageTitles !== [] ) {
			$this->rebuildSmwData( $allPageTitles );
		}

		$this->output( "Done.\n" );
	}

	private function runSmwImport(): void {
		$this->output( "==> Running database update to import staged pages...\n" );

		$result = Shell::command(
			PHP_BINARY,
			MW_INSTALL_PATH . '/maintenance/run.php',
			'update',
			'--quick'
		)
			->limits( [ 'memory' => 0, 'time' => 0 ] )
			->restrict( Shell::RESTRICT_NONE )
			->execute();

		$this->output( $result->getStdout() );

		if ( $result->getExitCode() !== 0 ) {
			$this->fatalError(
				"update.php failed (exit code {$result->getExitCode()}):\n" .
				$result->getStderr()
			);
		}
	}

	/**
	 * Rebuild SMW semantic data for imported pages using SMW's DataRebuilder.
	 *
	 * @param string[] $pageTitles Prefixed page titles (e.g. "Subobject:Has_access_record")
	 */
	private function rebuildSmwData( array $pageTitles ): void {
		if ( !defined( 'SMW_EXTENSION_LOADED' ) ) {
			$this->output( "SMW not loaded, skipping semantic data rebuild.\n" );
			return;
		}

		$count = count( $pageTitles );
		$this->output( "==> Rebuilding SMW semantic data for $count pages...\n" );

		$store = \SMW\StoreFactory::getStore();
		$store->setOption( \SMW\Store::OPT_CREATE_UPDATE_JOB, false );

		$maintenanceFactory = \SMW\Services\ServicesFactory::getInstance()
			->newMaintenanceFactory();

		$dataRebuilder = $maintenanceFactory->newDataRebuilder(
			$store,
			[ $this, 'reportMessage' ]
		);

		$dataRebuilder->setOptions( new \SMW\Options( [
			'page' => implode( '|', $pageTitles ),
			'force-update' => true,
			'v' => true,
		] ) );

		$dataRebuilder->rebuild();
	}

	/**
	 * Callback for SMW's DataRebuilder message reporting.
	 */
	public function reportMessage( string $message ): void {
		$this->output( $message );
	}
}
