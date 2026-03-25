<?php

namespace MediaWiki\Extension\OntologySync\Store;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * CRUD operations for the ontologysync_pages table.
 */
class PageStore {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @return array[]
	 */
	public function getPagesForBundle( int $bundleDbId ): array {
		$res = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_pages' )
			->where( [ 'osp_bundle_id' => $bundleDbId ] )
			->orderBy( [ 'osp_page_namespace', 'osp_page_name' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pages = [];
		foreach ( $res as $row ) {
			$pages[] = (array)$row;
		}
		return $pages;
	}

	/**
	 * @return array[]
	 */
	public function getPagesForModule( int $moduleDbId ): array {
		$res = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_pages' )
			->where( [ 'osp_module_id' => $moduleDbId ] )
			->orderBy( [ 'osp_page_namespace', 'osp_page_name' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pages = [];
		foreach ( $res as $row ) {
			$pages[] = (array)$row;
		}
		return $pages;
	}

	public function getPageByTitle( int $namespace, string $pageName ): ?array {
		$row = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_pages' )
			->where( [
				'osp_page_namespace' => $namespace,
				'osp_page_name' => $pageName,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}

	public function insertPage( array $data ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ontologysync_pages' )
			->row( $data )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function updatePageHash( int $ospId, string $hash ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newUpdateQueryBuilder()
			->update( 'ontologysync_pages' )
			->set( [ 'osp_content_hash' => $hash ] )
			->where( [ 'osp_id' => $ospId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	public function deletePagesForBundle( int $bundleDbId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'ontologysync_pages' )
			->where( [ 'osp_bundle_id' => $bundleDbId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	/**
	 * @return array[]
	 */
	public function getAllManagedPages(): array {
		$res = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ontologysync_pages' )
			->orderBy( [ 'osp_page_namespace', 'osp_page_name' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pages = [];
		foreach ( $res as $row ) {
			$pages[] = (array)$row;
		}
		return $pages;
	}
}
