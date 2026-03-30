<?php

use MediaWiki\Extension\OntologySync\Service\ChangeClassificationService;
use MediaWiki\Extension\OntologySync\Service\GitService;
use MediaWiki\Extension\OntologySync\Service\HashService;
use MediaWiki\Extension\OntologySync\Service\ImportService;
use MediaWiki\Extension\OntologySync\Service\PageResolver;
use MediaWiki\Extension\OntologySync\Service\RepoInspector;
use MediaWiki\Extension\OntologySync\Service\StagingService;
use MediaWiki\Extension\OntologySync\Service\VocabBuilder;
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

	'OntologySync.ChangeClassificationService' => static function (
		MediaWikiServices $services
	): ChangeClassificationService {
		return new ChangeClassificationService(
			$services->get( 'OntologySync.PageStore' ),
			$services->get( 'OntologySync.HashService' ),
			$services->get( 'OntologySync.RepoInspector' ),
			$services->get( 'OntologySync.PageResolver' )
		);
	},

	'OntologySync.GitService' => static function (
		MediaWikiServices $services
	): GitService {
		return new GitService();
	},

	'OntologySync.HashService' => static function (
		MediaWikiServices $services
	): HashService {
		return new HashService();
	},

	'OntologySync.ImportService' => static function (
		MediaWikiServices $services
	): ImportService {
		return new ImportService(
			$services->get( 'OntologySync.BundleStore' ),
			$services->get( 'OntologySync.ModuleStore' ),
			$services->get( 'OntologySync.PageStore' ),
			$services->get( 'OntologySync.RepoInspector' ),
			$services->get( 'OntologySync.StagingService' ),
			$services->get( 'OntologySync.HashService' ),
			$services->get( 'OntologySync.PageResolver' ),
			$services->get( 'OntologySync.VocabBuilder' ),
			$services->get( 'OntologySync.ChangeClassificationService' )
		);
	},

	'OntologySync.ModuleStore' => static function (
		MediaWikiServices $services
	): ModuleStore {
		return new ModuleStore(
			$services->getConnectionProvider()
		);
	},

	'OntologySync.PageResolver' => static function (
		MediaWikiServices $services
	): PageResolver {
		return new PageResolver();
	},

	'OntologySync.PageStore' => static function (
		MediaWikiServices $services
	): PageStore {
		return new PageStore(
			$services->getConnectionProvider()
		);
	},

	'OntologySync.RepoInspector' => static function (
		MediaWikiServices $services
	): RepoInspector {
		return new RepoInspector();
	},

	'OntologySync.StagingService' => static function (
		MediaWikiServices $services
	): StagingService {
		return new StagingService(
			$services->get( 'OntologySync.RepoInspector' ),
			$services->get( 'OntologySync.HashService' ),
			$services->get( 'OntologySync.VocabBuilder' )
		);
	},

	'OntologySync.VocabBuilder' => static function (
		MediaWikiServices $services
	): VocabBuilder {
		return new VocabBuilder(
			$services->get( 'OntologySync.RepoInspector' )
		);
	},

];
