<?php

namespace MediaWiki\Extension\OntologySync\Hooks;

use Article;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Adds a management footer to pages imported by OntologySync.
 *
 * Pages with OntologySync-managed-* categories display a footer showing
 * provenance (bundle name, version, module) and a link to Special:OntologySync.
 */
class PageDisplayHooks {

	private PageStore $pageStore;
	private BundleStore $bundleStore;

	public function __construct( PageStore $pageStore, BundleStore $bundleStore ) {
		$this->pageStore = $pageStore;
		$this->bundleStore = $bundleStore;
	}

	/** @var string[] Categories that indicate OntologySync management */
	private const MANAGED_CATEGORIES = [
		'OntologySync-managed',
		'OntologySync-managed-property',
		'OntologySync-managed-subobject',
		'OntologySync-managed-dashboard',
		'OntologySync-managed-resource',
	];

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleViewFooter
	 */
	public function onArticleViewFooter( Article $article, bool $patrolFooterShown ): void {
		$title = $article->getTitle();
		$categories = $title->getParentCategories();

		$isManaged = false;
		foreach ( self::MANAGED_CATEGORIES as $managedCat ) {
			$catTitle = \Title::makeTitleSafe( NS_CATEGORY, $managedCat );
			if ( $catTitle && isset( $categories[$catTitle->getPrefixedDBkey()] ) ) {
				$isManaged = true;
				break;
			}
		}

		if ( !$isManaged ) {
			return;
		}

		$out = $article->getContext()->getOutput();
		$out->addModuleStyles( 'ext.ontologysync.styles' );

		// Try to get provenance from DB
		$pageRecord = $this->pageStore->getPageByTitle(
			$title->getNamespace(), $title->getDBkey()
		);

		$footerContent = wfMessage( 'ontologysync-managed-footer' )->parse();

		if ( $pageRecord !== null ) {
			$bundle = $this->bundleStore->getBundleById( (int)$pageRecord['osp_bundle_id'] );
			if ( $bundle !== null ) {
				$bundleLabel = htmlspecialchars( $bundle['osb_bundle_id'] );
				$version = htmlspecialchars( $bundle['osb_version'] );
				$footerContent .= ' ' . wfMessage( 'ontologysync-footer-provenance' )
					->params( $bundleLabel, $version )->parse();
			}
		}

		$manageUrl = SpecialPage::getTitleFor( 'OntologySync' )->getLocalURL();
		$manageLink = Html::element( 'a', [ 'href' => $manageUrl ],
			wfMessage( 'ontologysync-footer-manage' )->text() );

		$out->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-managed-footer' ],
				$footerContent . ' ' . $manageLink
			)
		);
	}
}
