<?php

namespace MediaWiki\Extension\OntologySync\Hooks;

use Article;

/**
 * Adds a management footer to pages imported by OntologySync.
 *
 * Pages with OntologySync-managed-* categories display a footer indicating
 * they are managed by OntologySync and should not be manually edited.
 */
class PageDisplayHooks {

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
	 * @param Article $article
	 * @param bool $patrolFooterShown
	 */
	public static function onArticleViewFooter( Article $article, bool $patrolFooterShown ): void {
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
		$out->addHTML(
			'<div class="ontologysync-managed-footer" style="' .
			'margin-top: 2em; padding: 0.5em 1em; ' .
			'border-top: 1px solid #a2a9b1; color: #54595d; font-size: 0.85em;">' .
			wfMessage( 'ontologysync-managed-footer' )->parse() .
			'</div>'
		);
	}
}
