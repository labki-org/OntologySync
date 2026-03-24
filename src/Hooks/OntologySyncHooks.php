<?php

namespace MediaWiki\Extension\OntologySync\Hooks;

/**
 * Registers bundle and staging directories with SMW's content importer.
 *
 * Handles two import sources:
 * - $wgOntologySyncBundlePath: legacy/manual bundle path
 * - Staging directory: prepared by the Special page or CLI before update.php
 */
class OntologySyncHooks {

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SetupAfterCache
	 */
	public static function onSetupAfterCache(): void {
		global $smwgImportFileDirs, $smwgNamespacesWithSemanticLinks,
			$wgOntologySyncBundlePath, $wgOntologySyncStagingPath,
			$wgOntologySyncAutoRegisterStaging, $wgCacheDirectory;

		if ( !defined( 'SMW_EXTENSION_LOADED' ) ) {
			return;
		}

		// Enable semantic links in custom namespaces
		if ( defined( 'NS_ONTOLOGY_DASHBOARD' ) ) {
			$smwgNamespacesWithSemanticLinks[NS_ONTOLOGY_DASHBOARD] = true;
		}
		if ( defined( 'NS_ONTOLOGY_RESOURCE' ) ) {
			$smwgNamespacesWithSemanticLinks[NS_ONTOLOGY_RESOURCE] = true;
		}

		// Register legacy/manual bundle path
		if ( $wgOntologySyncBundlePath !== null ) {
			if ( is_dir( $wgOntologySyncBundlePath ) ) {
				$smwgImportFileDirs['ontologysync'] = $wgOntologySyncBundlePath;
			} else {
				wfLogWarning(
					"OntologySync: Bundle path does not exist: $wgOntologySyncBundlePath"
				);
			}
		}

		// Register staging directory if auto-registration is enabled
		if ( $wgOntologySyncAutoRegisterStaging ?? true ) {
			$stagingPath = $wgOntologySyncStagingPath
				?? ( $wgCacheDirectory ? $wgCacheDirectory . '/ontologysync-staging' : null );

			if ( $stagingPath !== null && is_dir( $stagingPath ) ) {
				// Only register if it contains a vocab.json
				$vocabFiles = glob( $stagingPath . '/*.vocab.json' );
				if ( $vocabFiles !== false && $vocabFiles !== [] ) {
					$smwgImportFileDirs['ontologysync-staging'] = $stagingPath;
				}
			}
		}
	}
}
