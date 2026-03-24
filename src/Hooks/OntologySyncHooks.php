<?php

namespace MediaWiki\Extension\OntologySync\Hooks;

/**
 * Registers the ontology bundle directory with SMW's content importer.
 *
 * When $wgOntologySyncBundlePath is set to a bundle version directory
 * (e.g., /path/to/bundles/Default/versions/1.0.0/), this hook registers
 * it with $smwgImportFileDirs so that running `php maintenance/run.php update`
 * will import all entity pages defined in the bundle's vocab.json manifest.
 */
class OntologySyncHooks {

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SetupAfterCache
	 */
	public static function onSetupAfterCache(): void {
		global $smwgImportFileDirs, $wgOntologySyncBundlePath;

		if ( !defined( 'SMW_EXTENSION_LOADED' ) ) {
			return;
		}

		if ( $wgOntologySyncBundlePath === null ) {
			return;
		}

		if ( !is_dir( $wgOntologySyncBundlePath ) ) {
			wfLogWarning(
				"OntologySync: Bundle path does not exist: $wgOntologySyncBundlePath"
			);
			return;
		}

		$smwgImportFileDirs['ontologysync'] = $wgOntologySyncBundlePath;
	}
}
