<?php

namespace MediaWiki\Extension\OntologySync;

use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Extension\OntologySync\Store\ModuleStore;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\MediaWikiServices;

/**
 * Typed service accessors for OntologySync.
 */
class OntologySyncServices {

	public static function getBundleStore( ?MediaWikiServices $services = null ): BundleStore {
		$services ??= MediaWikiServices::getInstance();
		return $services->get( 'OntologySync.BundleStore' );
	}

	public static function getModuleStore( ?MediaWikiServices $services = null ): ModuleStore {
		$services ??= MediaWikiServices::getInstance();
		return $services->get( 'OntologySync.ModuleStore' );
	}

	public static function getPageStore( ?MediaWikiServices $services = null ): PageStore {
		$services ??= MediaWikiServices::getInstance();
		return $services->get( 'OntologySync.PageStore' );
	}
}
