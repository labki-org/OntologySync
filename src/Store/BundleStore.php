<?php

namespace MediaWiki\Extension\OntologySync\Store;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * CRUD operations for the ontologysync_bundles table.
 */
class BundleStore {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	public function getInstalledBundle( string $bundleId ): ?array {
		$row = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_bundles' )
			->where( [ 'osb_bundle_id' => $bundleId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}

	/**
	 * @return array[]
	 */
	public function getAllInstalledBundles(): array {
		$res = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_bundles' )
			->where( [ 'osb_status' => 'installed' ] )
			->orderBy( 'osb_bundle_id' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$bundles = [];
		foreach ( $res as $row ) {
			$bundles[] = (array)$row;
		}
		return $bundles;
	}

	public function insertBundle( array $data ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ontologysync_bundles' )
			->row( $data )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function updateBundle( string $bundleId, array $data ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( 'ontologysync_bundles' )
			->set( $data )
			->where( [ 'osb_bundle_id' => $bundleId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	public function deleteBundle( string $bundleId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ontologysync_bundles' )
			->where( [ 'osb_bundle_id' => $bundleId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	/**
	 * @return array[]
	 */
	public function getStagedBundles(): array {
		$res = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_bundles' )
			->where( [ 'osb_status' => 'staged' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$bundles = [];
		foreach ( $res as $row ) {
			$bundles[] = (array)$row;
		}
		return $bundles;
	}

	public function getBundleById( int $osbId ): ?array {
		$row = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_bundles' )
			->where( [ 'osb_id' => $osbId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}
}
