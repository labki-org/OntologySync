<?php

namespace MediaWiki\Extension\OntologySync\Store;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * CRUD operations for the ontologysync_modules table.
 */
class ModuleStore {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @return array[]
	 */
	public function getModulesForBundle( int $bundleDbId ): array {
		$res = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_modules' )
			->where( [ 'osm_bundle_id' => $bundleDbId ] )
			->orderBy( 'osm_module_id' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$modules = [];
		foreach ( $res as $row ) {
			$modules[] = (array)$row;
		}
		return $modules;
	}

	public function insertModule( array $data ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ontologysync_modules' )
			->row( $data )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function deleteModulesForBundle( int $bundleDbId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ontologysync_modules' )
			->where( [ 'osm_bundle_id' => $bundleDbId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	public function getModuleById( int $osmId ): ?array {
		$row = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_modules' )
			->where( [ 'osm_id' => $osmId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}
}
