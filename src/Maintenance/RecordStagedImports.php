<?php

namespace MediaWiki\Extension\OntologySync\Maintenance;

use MediaWiki\Extension\OntologySync\Service\ImportService;
use MediaWiki\Extension\OntologySync\Service\StagingService;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

/**
 * Records page hashes and module info for staged OntologySync bundles.
 *
 * Run after update.php has imported the staged pages:
 *   php maintenance/run.php update --quick
 *   php maintenance/run.php OntologySync:import
 *
 * Or with the full class name:
 *   php maintenance/run.php "MediaWiki\Extension\OntologySync\Maintenance\RecordStagedImports"
 */
class RecordStagedImports extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Records page hashes and module info for staged OntologySync bundles. ' .
			'Run after update.php has imported the pages.'
		);
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

		foreach ( $staged as $bundle ) {
			$bundleId = $bundle['osb_bundle_id'];
			$version = $bundle['osb_version'];
			$commit = $bundle['osb_repo_commit'] ?? '';
			$userId = (int)( $bundle['osb_installed_by'] ?? 0 );

			$this->output( "Recording $bundleId v$version...\n" );

			$importService->recordInstall(
				$repoPath, $bundleId, $version, $commit, $userId, $stagingRoot
			);

			$stagingService->clearBundleStaging( $stagingRoot, $bundleId );
			$this->output( "$bundleId v$version recorded.\n" );
		}

		$this->output( "Done.\n" );
	}
}
