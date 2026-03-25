<?php

namespace MediaWiki\Extension\OntologySync\Maintenance;

use MediaWiki\Extension\OntologySync\Service\ImportService;
use MediaWiki\Extension\OntologySync\Service\StagingService;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

/**
 * Post-update maintenance task that finalizes staged bundle imports.
 *
 * Automatically run after update.php via addPostDatabaseUpdateMaintenance().
 * Detects bundles with status=staged, records page hashes and module info,
 * flips status to installed, and cleans up the staging directory.
 *
 * This eliminates the need for users to manually "confirm" imports after
 * running update.php — everything happens in one maintenance pipeline.
 */
class RecordStagedImports extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Records page hashes and module info for staged OntologySync bundles. ' .
			'Automatically run after update.php.'
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
			$this->output( "OntologySync: No staged bundles to record.\n" );
			return;
		}

		$repoPath = $config->get( 'OntologySyncRepoPath' );
		if ( $repoPath === null ) {
			$this->output( "OntologySync: \$wgOntologySyncRepoPath not configured, skipping.\n" );
			return;
		}

		$stagingPath = $stagingService->getStagingPath(
			$config->get( 'OntologySyncStagingPath' ),
			$config->get( 'CacheDirectory' )
		);

		foreach ( $staged as $bundle ) {
			$bundleId = $bundle['osb_bundle_id'];
			$version = $bundle['osb_version'];
			$commit = $bundle['osb_repo_commit'] ?? '';
			$userId = (int)( $bundle['osb_installed_by'] ?? 0 );

			$this->output( "OntologySync: Recording install for $bundleId v$version...\n" );

			$importService->recordInstall(
				$repoPath, $bundleId, $version, $commit, $userId, $stagingPath
			);

			$this->output( "OntologySync: $bundleId v$version recorded successfully.\n" );
		}

		// Clean up staging directory
		$stagingService->clearStaging( $stagingPath );
		$this->output( "OntologySync: Staging directory cleaned up.\n" );
	}
}
