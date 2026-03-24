<?php

use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Extension\OntologySync\Store\ModuleStore;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [

	'OntologySync.BundleStore' => static function (
		MediaWikiServices $services
	): BundleStore {
		return new BundleStore(
			$services->getConnectionProvider()
		);
	},

	'OntologySync.ModuleStore' => static function (
		MediaWikiServices $services
	): ModuleStore {
		return new ModuleStore(
			$services->getConnectionProvider()
		);
	},

	'OntologySync.PageStore' => static function (
		MediaWikiServices $services
	): PageStore {
		return new PageStore(
			$services->getConnectionProvider()
		);
	},

];
