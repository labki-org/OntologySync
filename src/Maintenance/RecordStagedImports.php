<?php

namespace MediaWiki\Extension\OntologySync\Maintenance;

use MediaWiki\Extension\OntologySync\Service\ImportService;
use MediaWiki\Extension\OntologySync\Service\StagingService;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

/**
 * All-in-one maintenance script for staged OntologySync bundles.
 *
 * Runs update.php to trigger SMW page imports, then records page hashes
 * and module info, flips bundle status to installed, and cleans staging.
 *
 * Usage after staging on Special:OntologySync:
 *   php maintenance/run.php "MediaWiki\Extension\OntologySync\Maintenance\RecordStagedImports"
 */
class RecordStagedImports extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Runs update.php to import staged OntologySync bundles, then ' .
			'records page hashes and module info in the database.'
		);
		$this->addOption(
			'skip-update',
			'Skip running update.php (use if you already ran it separately)',
			false,
			false
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

		$stagingPath = $stagingService->getStagingPath(
			$config->get( 'OntologySyncStagingPath' ),
			$config->get( 'CacheDirectory' )
		);

		$bundleNames = array_map( static fn ( $b ) => $b['osb_bundle_id'], $staged );
		$this->output( 'OntologySync: Staged bundles: ' . implode( ', ', $bundleNames ) . "\n" );

		// Step 1: Run update.php to trigger SMW page imports
		if ( !$this->hasOption( 'skip-update' ) ) {
			$this->output( "\n=== Running update.php ===\n\n" );
			$update = $this->runChild( \MediaWiki\Maintenance\Update::class );
			$update->setOption( 'quick', true );
			$update->execute();
			$this->output( "\n=== update.php complete ===\n\n" );
		}

		// Step 2: Record each staged bundle
		foreach ( $staged as $bundle ) {
			$bundleId = $bundle['osb_bundle_id'];
			$version = $bundle['osb_version'];
			$commit = $bundle['osb_repo_commit'] ?? '';
			$userId = (int)( $bundle['osb_installed_by'] ?? 0 );

			$this->output( "OntologySync: Recording $bundleId v$version...\n" );

			$importService->recordInstall(
				$repoPath, $bundleId, $version, $commit, $userId, $stagingPath
			);

			$this->output( "OntologySync: $bundleId v$version recorded.\n" );
		}

		// Step 3: Clean up staging
		$stagingService->clearStaging( $stagingPath );
		$this->output( "OntologySync: Staging cleaned up. Done.\n" );
	}
}
