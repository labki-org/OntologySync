<?php

namespace MediaWiki\Extension\OntologySync\Hooks;

use DatabaseUpdater;

/**
 * Hook handler for database schema updates.
 *
 * Registers OntologySync database tables during MediaWiki installation/updates.
 */
class SchemaHooks {

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
		$dir = dirname( __DIR__, 2 ) . '/sql';

		$dbType = $updater->getDB()->getType();

		$dbDir = match ( $dbType ) {
			'mysql', 'mariadb' => 'mysql',
			'sqlite' => 'sqlite',
			'postgres' => 'postgres',
			default => 'mysql',
		};

		$tablesFile = $dir . '/' . $dbDir . '/tables-generated.sql';

		if ( file_exists( $tablesFile ) ) {
			$updater->addExtensionTable( 'ontologysync_bundles', $tablesFile );
			$updater->addExtensionTable( 'ontologysync_modules', $tablesFile );
			$updater->addExtensionTable( 'ontologysync_pages', $tablesFile );
		}
	}
}
