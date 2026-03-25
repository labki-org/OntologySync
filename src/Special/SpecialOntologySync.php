<?php

namespace MediaWiki\Extension\OntologySync\Special;

use MediaWiki\Extension\OntologySync\Service\GitService;
use MediaWiki\Extension\OntologySync\Service\HashService;
use MediaWiki\Extension\OntologySync\Service\ImportService;
use MediaWiki\Extension\OntologySync\Service\PageResolver;
use MediaWiki\Extension\OntologySync\Service\RepoInspector;
use MediaWiki\Extension\OntologySync\Service\StagingService;
use MediaWiki\Extension\OntologySync\Store\BundleStore;
use MediaWiki\Extension\OntologySync\Store\ModuleStore;
use MediaWiki\Extension\OntologySync\Store\PageStore;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Special:OntologySync — Central management UI for ontology bundles.
 *
 * Tabs:
 * - Overview: Repository status, installed bundles
 * - Browse: Available bundles/modules from local clone
 * - Install: Stage bundles for import via update.php
 * - Pages: View managed pages, detect user edits
 */
class SpecialOntologySync extends SpecialPage {

	private GitService $gitService;
	private RepoInspector $repoInspector;
	private StagingService $stagingService;
	private ImportService $importService;
	private HashService $hashService;
	private PageResolver $pageResolver;
	private BundleStore $bundleStore;
	private ModuleStore $moduleStore;
	private PageStore $pageStore;

	public function __construct(
		GitService $gitService,
		RepoInspector $repoInspector,
		StagingService $stagingService,
		ImportService $importService,
		HashService $hashService,
		PageResolver $pageResolver,
		BundleStore $bundleStore,
		ModuleStore $moduleStore,
		PageStore $pageStore
	) {
		parent::__construct( 'OntologySync', 'ontologysync-manage' );
		$this->gitService = $gitService;
		$this->repoInspector = $repoInspector;
		$this->stagingService = $stagingService;
		$this->importService = $importService;
		$this->hashService = $hashService;
		$this->pageResolver = $pageResolver;
		$this->bundleStore = $bundleStore;
		$this->moduleStore = $moduleStore;
		$this->pageStore = $pageStore;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();

		$output = $this->getOutput();
		$request = $this->getRequest();

		$output->addModuleStyles( 'ext.ontologysync.styles' );

		$repoPath = $this->getConfig()->get( 'OntologySyncRepoPath' );

		// Handle form submissions
		if ( $request->wasPosted() && $request->getVal( 'wpEditToken' ) ) {
			if ( $this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$this->handlePostAction( $request->getVal( 'action' ), $repoPath );
			}
		}

		// Config check
		if ( $repoPath === null ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'ontologysync-error-no-repopath' )->parse()
			) );
			return;
		}

		// Tab navigation
		$action = $subPage ?: 'overview';
		$this->showNavigation( $action );

		// Show pending staged bundles banner (visible on all tabs)
		$this->showStagedBanner();

		switch ( $action ) {
			case 'browse':
				$this->showBrowse( $repoPath );
				break;
			case 'install':
				$this->showInstall( $repoPath );
				break;
			case 'pages':
				$this->showPages();
				break;
			case 'overview':
			default:
				$this->showOverview( $repoPath );
				break;
		}
	}

	private function showNavigation( string $currentAction ): void {
		$tabs = [
			'overview' => $this->msg( 'ontologysync-tab-overview' )->text(),
			'browse' => $this->msg( 'ontologysync-tab-browse' )->text(),
			'install' => $this->msg( 'ontologysync-tab-install' )->text(),
			'pages' => $this->msg( 'ontologysync-tab-pages' )->text(),
		];

		$links = '';
		foreach ( $tabs as $action => $label ) {
			$url = $this->getPageTitle( $action )->getLocalURL();
			$isActive = ( $action === $currentAction );
			$links .= Html::element(
				'a',
				[
					'href' => $url,
					'class' => 'ontologysync-tab' . ( $isActive ? ' is-active' : '' ),
				],
				$label
			);
		}

		$this->getOutput()->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-shell' ],
				Html::rawElement( 'nav', [ 'class' => 'ontologysync-tabs' ], $links )
			)
		);
	}

	/**
	 * Show a banner for any bundles in "staged" state, prompting the user
	 * to run update.php. After update.php the maintenance task records
	 * everything automatically — no manual confirm needed.
	 */
	private function showStagedBanner(): void {
		$staged = $this->bundleStore->getStagedBundles();
		if ( $staged === [] ) {
			return;
		}

		$output = $this->getOutput();
		foreach ( $staged as $b ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-staged-banner' )
					->params( $b['osb_bundle_id'], $b['osb_version'] )->parse()
			) );

			$output->addHTML( $this->renderActionButton(
				'cancel-stage',
				$this->msg( 'ontologysync-action-cancel-stage' )->text(),
				[ 'bundle' => $b['osb_bundle_id'] ]
			) );
		}
	}

	// ────────────────────────────────────────────
	// Overview tab
	// ────────────────────────────────────────────

	private function showOverview( string $repoPath ): void {
		$output = $this->getOutput();

		// Repository status
		$output->addHTML( Html::rawElement( 'h3', [],
			$this->msg( 'ontologysync-repo-status' )->text() ) );
		$status = $this->gitService->getRepoStatus( $repoPath );

		if ( !$status->isCloned() ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse()
			) );
			$output->addHTML( $this->renderActionButton(
				'clone', $this->msg( 'ontologysync-action-clone' )->text()
			) );
			return;
		}

		$repoUrl = $this->getConfig()->get( 'OntologySyncRepoUrl' );
		$output->addHTML( $this->renderInfoTable( [
			$this->msg( 'ontologysync-repo-url' )->text() => htmlspecialchars( $repoUrl ),
			$this->msg( 'ontologysync-repo-local-commit' )->text() =>
				'<code>' . htmlspecialchars( substr( $status->getLocalHead() ?? '', 0, 12 ) ) . '</code>',
			$this->msg( 'ontologysync-repo-updates' )->text() =>
				$status->hasUpdates()
					? Html::element( 'strong', [],
						$this->msg( 'ontologysync-repo-updates-available' )->text() )
					: $this->msg( 'ontologysync-repo-up-to-date' )->text(),
		] ) );

		$output->addHTML(
			$this->renderActionButton( 'fetch',
				$this->msg( 'ontologysync-action-fetch' )->text() ) .
			( $status->hasUpdates()
				? ' ' . $this->renderActionButton( 'pull',
					$this->msg( 'ontologysync-action-pull' )->text() )
				: '' )
		);

		// Installed bundles
		$output->addHTML( Html::rawElement( 'h3', [ 'style' => 'margin-top: 1.5em;' ],
			$this->msg( 'ontologysync-installed-bundles' )->text() ) );

		$bundles = $this->bundleStore->getAllInstalledBundles();
		if ( $bundles === [] ) {
			$output->addHTML( Html::element( 'p', [ 'class' => 'ontologysync-muted' ],
				$this->msg( 'ontologysync-no-bundles-installed' )->text() ) );
			return;
		}

		$rows = '';
		foreach ( $bundles as $b ) {
			$moduleCount = count( $this->moduleStore->getModulesForBundle( (int)$b['osb_id'] ) );
			$pageCount = count( $this->pageStore->getPagesForBundle( (int)$b['osb_id'] ) );

			$repoBundle = $this->repoInspector->getBundle( $repoPath, $b['osb_bundle_id'] );
			$newerAvailable = $repoBundle &&
				version_compare( $repoBundle->getVersion(), $b['osb_version'], '>' );

			$versionCell = htmlspecialchars( $b['osb_version'] );
			if ( $newerAvailable ) {
				$versionCell .= ' ' . Html::element( 'span',
					[ 'class' => 'ontologysync-badge-update' ],
					$repoBundle->getVersion() . ' ' .
					$this->msg( 'ontologysync-available' )->text() );
			}

			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $b['osb_bundle_id'] ) .
				Html::rawElement( 'td', [], $versionCell ) .
				Html::element( 'td', [], (string)$moduleCount ) .
				Html::element( 'td', [], (string)$pageCount ) .
				Html::element( 'td', [], substr( $b['osb_repo_commit'] ?? '', 0, 8 ) )
			);
		}

		$output->addHTML(
			Html::rawElement( 'table', [ 'class' => 'wikitable ontologysync-table' ],
				Html::rawElement( 'thead', [],
					Html::rawElement( 'tr', [],
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-bundle' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-version' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-modules' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-pages' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-commit' )->text() )
					)
				) .
				Html::rawElement( 'tbody', [], $rows )
			)
		);
	}

	// ────────────────────────────────────────────
	// Browse tab
	// ────────────────────────────────────────────

	private function showBrowse( string $repoPath ): void {
		$output = $this->getOutput();

		if ( !$this->gitService->isCloned( $repoPath ) ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse() ) );
			return;
		}

		$bundles = $this->repoInspector->listBundles( $repoPath );
		if ( $bundles === [] ) {
			$output->addHTML( Html::element( 'p', [],
				$this->msg( 'ontologysync-no-bundles-in-repo' )->text() ) );
			return;
		}

		foreach ( $bundles as $bundle ) {
			$installed = $this->bundleStore->getInstalledBundle( $bundle->getId() );
			$isInstalled = $installed !== null;
			$isOutdated = $isInstalled &&
				version_compare( $bundle->getVersion(), $installed['osb_version'], '>' );

			$statusBadge = '';
			if ( $isOutdated ) {
				$statusBadge = Html::element( 'span',
					[ 'class' => 'ontologysync-badge-update' ],
					$this->msg( 'ontologysync-update-available' )->text() );
			} elseif ( $isInstalled ) {
				$statusBadge = Html::element( 'span',
					[ 'class' => 'ontologysync-badge-installed' ],
					$this->msg( 'ontologysync-installed' )->text() );
			}

			$output->addHTML(
				Html::rawElement( 'div', [ 'class' => 'ontologysync-card' ],
					Html::rawElement( 'div', [ 'class' => 'ontologysync-card-header' ],
						Html::element( 'strong', [],
							$bundle->getLabel() ?: $bundle->getId() ) .
						' ' . Html::element( 'code', [],
							'v' . $bundle->getVersion() ) .
						' ' . $statusBadge
					) .
					Html::element( 'p', [ 'class' => 'ontologysync-card-desc' ],
						$bundle->getDescription() ) .
					$this->renderModuleList( $repoPath, $bundle->getModules() )
				)
			);
		}
	}

	/**
	 * @param string $repoPath
	 * @param string[] $moduleIds
	 */
	private function renderModuleList( string $repoPath, array $moduleIds ): string {
		if ( $moduleIds === [] ) {
			return '';
		}

		$items = '';
		foreach ( $moduleIds as $moduleId ) {
			$module = $this->repoInspector->getModule( $repoPath, $moduleId );
			if ( $module === null ) {
				$items .= Html::element( 'li', [], $moduleId . ' (not found)' );
				continue;
			}
			$items .= Html::rawElement( 'li', [],
				Html::element( 'strong', [],
					$module->getLabel() ?: $module->getId() ) .
				' v' . $module->getVersion() .
				' — ' . $module->getEntityCount() . ' entities'
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'ontologysync-card-modules' ],
			Html::element( 'strong', [],
				$this->msg( 'ontologysync-modules-label' )->text() ) .
			Html::rawElement( 'ul', [], $items )
		);
	}

	// ────────────────────────────────────────────
	// Install tab
	// ────────────────────────────────────────────

	private function showInstall( string $repoPath ): void {
		$output = $this->getOutput();
		$request = $this->getRequest();

		if ( !$this->gitService->isCloned( $repoPath ) ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse() ) );
			return;
		}

		// Check if a specific bundle was requested for preview
		$bundleId = $request->getVal( 'bundle' );
		if ( $bundleId !== null ) {
			$this->showInstallPreview( $repoPath, $bundleId );
			return;
		}

		// List installable/updatable bundles
		$bundles = $this->repoInspector->listBundles( $repoPath );
		$output->addHTML( Html::element( 'h3', [],
			$this->msg( 'ontologysync-install-heading' )->text() ) );

		$rows = '';
		foreach ( $bundles as $bundle ) {
			$installed = $this->bundleStore->getInstalledBundle( $bundle->getId() );
			$action = '';

			if ( $installed === null ) {
				$action = Html::element( 'a', [
					'href' => $this->getPageTitle( 'install' )->getLocalURL( [
						'bundle' => $bundle->getId(),
					] ),
					'class' => 'ontologysync-btn',
				], $this->msg( 'ontologysync-action-install' )->text() );
			} elseif ( version_compare(
				$bundle->getVersion(), $installed['osb_version'], '>'
			) ) {
				$action = Html::element( 'a', [
					'href' => $this->getPageTitle( 'install' )->getLocalURL( [
						'bundle' => $bundle->getId(),
					] ),
					'class' => 'ontologysync-btn',
				], $this->msg( 'ontologysync-action-update' )->text() );
			} else {
				$action = Html::element( 'span', [ 'class' => 'ontologysync-muted' ],
					$this->msg( 'ontologysync-up-to-date' )->text() );
			}

			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [],
					$bundle->getLabel() ?: $bundle->getId() ) .
				Html::element( 'td', [], $bundle->getVersion() ) .
				Html::element( 'td', [],
					$installed ? $installed['osb_version'] : '—' ) .
				Html::rawElement( 'td', [], $action )
			);
		}

		$output->addHTML(
			Html::rawElement( 'table', [ 'class' => 'wikitable ontologysync-table' ],
				Html::rawElement( 'thead', [],
					Html::rawElement( 'tr', [],
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-bundle' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-available' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-installed' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-action' )->text() )
					)
				) .
				Html::rawElement( 'tbody', [], $rows )
			)
		);

		// Installed bundles with remove option
		$installedBundles = $this->bundleStore->getAllInstalledBundles();
		if ( $installedBundles !== [] ) {
			$output->addHTML( Html::element( 'h3', [ 'style' => 'margin-top: 1.5em;' ],
				$this->msg( 'ontologysync-remove-heading' )->text() ) );

			foreach ( $installedBundles as $b ) {
				$output->addHTML(
					Html::rawElement( 'div', [ 'style' => 'margin-bottom: 0.5em;' ],
						Html::element( 'strong', [], $b['osb_bundle_id'] ) .
						' v' . $b['osb_version'] . ' — ' .
						$this->renderActionButton(
							'remove',
							$this->msg( 'ontologysync-action-remove' )->text(),
							[ 'bundle' => $b['osb_bundle_id'] ]
						)
					)
				);
			}
		}
	}

	private function showInstallPreview( string $repoPath, string $bundleId ): void {
		$output = $this->getOutput();
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );

		if ( $bundle === null ) {
			$output->addHTML( Html::errorBox(
				'Bundle not found: ' . htmlspecialchars( $bundleId ) ) );
			return;
		}

		$preview = $this->importService->prepareInstall(
			$repoPath, $bundleId, $bundle->getVersion() );

		$output->addHTML( Html::element( 'h3', [],
			$this->msg( 'ontologysync-install-preview-heading' )
				->params( $bundle->getLabel() )->text() ) );

		if ( $preview['warnings'] !== [] ) {
			foreach ( $preview['warnings'] as $warning ) {
				$output->addHTML( Html::warningBox( htmlspecialchars( $warning ) ) );
			}
		}

		$output->addHTML( Html::rawElement( 'p', [],
			$this->msg( 'ontologysync-install-preview-summary' )
				->params(
					$preview['newCount'],
					$preview['updateCount'],
					$preview['modifiedCount']
				)->parse()
		) );

		if ( $preview['pages'] !== [] ) {
			$rows = '';
			foreach ( $preview['pages'] as $page ) {
				$statusClass = $page['isModified']
					? 'ontologysync-status-modified' : '';
				$status = $page['isNew']
					? $this->msg( 'ontologysync-status-new' )->text()
					: ( $page['isModified']
						? $this->msg( 'ontologysync-status-modified' )->text()
						: $this->msg( 'ontologysync-status-update' )->text() );

				$rows .= Html::rawElement( 'tr', [ 'class' => $statusClass ],
					Html::element( 'td', [], $page['namespace'] ) .
					Html::element( 'td', [], $page['page'] ) .
					Html::element( 'td', [], $status )
				);
			}

			$output->addHTML(
				Html::rawElement( 'table',
					[ 'class' => 'wikitable ontologysync-table' ],
					Html::rawElement( 'thead', [],
						Html::rawElement( 'tr', [],
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-namespace' )->text() ) .
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-page' )->text() ) .
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-status' )->text() )
						)
					) .
					Html::rawElement( 'tbody', [], $rows )
				)
			);
		}

		$output->addHTML( $this->renderActionButton(
			'stage',
			$this->msg( 'ontologysync-action-stage' )->text(),
			[ 'bundle' => $bundleId, 'version' => $bundle->getVersion() ]
		) );
	}

	// ────────────────────────────────────────────
	// Pages tab
	// ────────────────────────────────────────────

	private function showPages(): void {
		$output = $this->getOutput();

		$pages = $this->pageStore->getAllManagedPages();
		if ( $pages === [] ) {
			$output->addHTML( Html::element( 'p', [ 'class' => 'ontologysync-muted' ],
				$this->msg( 'ontologysync-no-managed-pages' )->text() ) );
			return;
		}

		$rows = '';
		foreach ( $pages as $page ) {
			$nsId = (int)$page['osp_page_namespace'];
			$nsLabel = $this->pageResolver->getNamespaceLabel( $nsId );

			$bundle = $this->bundleStore->getBundleById(
				(int)$page['osp_bundle_id'] );
			$bundleLabel = $bundle ? $bundle['osb_bundle_id'] : '?';

			$title = Title::makeTitleSafe( $nsId, $page['osp_page_name'] );
			$pageLink = $title
				? Html::element( 'a',
					[ 'href' => $title->getLocalURL() ],
					$page['osp_page_name'] )
				: htmlspecialchars( $page['osp_page_name'] );

			$statusText = '—';
			if ( $title !== null && $title->exists()
				&& $page['osp_content_hash'] !== null
			) {
				$wikiPage = MediaWikiServices::getInstance()
					->getWikiPageFactory()->newFromTitle( $title );
				$content = $wikiPage->getContent();
				if ( $content !== null ) {
					$currentHash = $this->hashService->hashPageContent(
						$content->serialize() );
					if ( $currentHash === $page['osp_content_hash'] ) {
						$statusText = Html::element( 'span',
							[ 'class' => 'ontologysync-status-ok' ],
							$this->msg( 'ontologysync-page-status-ok' )->text() );
					} else {
						$statusText = Html::element( 'span',
							[ 'class' => 'ontologysync-status-modified' ],
							$this->msg( 'ontologysync-page-status-modified' )->text() );
					}
				}
			}

			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $nsLabel ) .
				Html::rawElement( 'td', [], $pageLink ) .
				Html::element( 'td', [], $bundleLabel ) .
				Html::element( 'td', [],
					$page['osp_installed_version'] ?? '' ) .
				Html::rawElement( 'td', [], $statusText )
			);
		}

		$output->addHTML(
			Html::rawElement( 'table',
				[ 'class' => 'wikitable ontologysync-table sortable' ],
				Html::rawElement( 'thead', [],
					Html::rawElement( 'tr', [],
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-namespace' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-page' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-bundle' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-version' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-status' )->text() )
					)
				) .
				Html::rawElement( 'tbody', [], $rows )
			)
		);
	}

	// ────────────────────────────────────────────
	// Form action handling
	// ────────────────────────────────────────────

	private function handlePostAction( ?string $action, ?string $repoPath ): void {
		if ( $action === null || $repoPath === null ) {
			return;
		}

		$request = $this->getRequest();
		$output = $this->getOutput();

		switch ( $action ) {
			case 'clone':
				$url = $this->getConfig()->get( 'OntologySyncRepoUrl' );
				if ( $this->gitService->cloneRepo( $url, $repoPath ) ) {
					$output->addHTML( Html::successBox(
						$this->msg( 'ontologysync-clone-success' )->text() ) );
				} else {
					$output->addHTML( Html::errorBox(
						$this->msg( 'ontologysync-clone-failed' )->text() ) );
				}
				break;

			case 'fetch':
				$this->gitService->fetchRemote( $repoPath );
				break;

			case 'pull':
				if ( $this->gitService->pullLatest( $repoPath ) ) {
					$output->addHTML( Html::successBox(
						$this->msg( 'ontologysync-pull-success' )->text() ) );
				} else {
					$output->addHTML( Html::errorBox(
						$this->msg( 'ontologysync-pull-failed' )->text() ) );
				}
				break;

			case 'stage':
				$bundleId = $request->getVal( 'bundle' );
				$version = $request->getVal( 'version' );
				if ( $bundleId && $version ) {
					$this->handleStageAction( $repoPath, $bundleId, $version );
				}
				break;

			case 'cancel-stage':
				$bundleId = $request->getVal( 'bundle' );
				if ( $bundleId ) {
					$stagingPath = $this->resolveStagingPath();
					$this->stagingService->clearStaging( $stagingPath );
					$existing = $this->bundleStore->getInstalledBundle( $bundleId );
					if ( $existing !== null && $existing['osb_status'] === 'staged' ) {
						$this->bundleStore->deleteBundle( $bundleId );
					}
					$output->addHTML( Html::successBox(
						$this->msg( 'ontologysync-stage-cancelled' )->text() ) );
				}
				break;

			case 'remove':
				$bundleId = $request->getVal( 'bundle' );
				if ( $bundleId ) {
					$result = $this->importService->removeBundle( $bundleId );
					if ( $result['errors'] === [] ) {
						$output->addHTML( Html::successBox(
							$this->msg( 'ontologysync-remove-success' )
								->params( $bundleId )->text() ) );
					} else {
						$output->addHTML( Html::errorBox(
							implode( ', ', $result['errors'] ) ) );
					}
				}
				break;
		}
	}

	private function handleStageAction(
		string $repoPath, string $bundleId, string $version
	): void {
		$output = $this->getOutput();
		$stagingPath = $this->resolveStagingPath();

		if ( !$this->importService->stageBundle(
			$repoPath, $bundleId, $version, $stagingPath
		) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'ontologysync-stage-failed' )->text() ) );
			return;
		}

		// Record staged status in DB so the banner and maintenance script work
		$now = wfTimestampNow();
		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );
		$existing = $this->bundleStore->getInstalledBundle( $bundleId );

		if ( $existing !== null ) {
			$this->bundleStore->updateBundle( $bundleId, [
				'osb_version' => $version,
				'osb_status' => 'staged',
				'osb_updated_at' => $now,
			] );
		} else {
			$this->bundleStore->insertBundle( [
				'osb_bundle_id' => $bundleId,
				'osb_version' => $version,
				'osb_label' => $bundle ? $bundle->getLabel() : $bundleId,
				'osb_description' => $bundle ? $bundle->getDescription() : '',
				'osb_repo_commit' => $this->gitService->getLocalHead( $repoPath ) ?? '',
				'osb_installed_by' => $this->getUser()->getId(),
				'osb_installed_at' => $now,
				'osb_updated_at' => $now,
				'osb_status' => 'staged',
			] );
		}

		$output->addHTML( Html::successBox(
			$this->msg( 'ontologysync-stage-success' )->parse() ) );
	}

	// ────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────

	private function renderActionButton(
		string $action, string $label, array $extraFields = []
	): string {
		$hiddenFields = Html::hidden(
			'wpEditToken', $this->getUser()->getEditToken()
		) . Html::hidden( 'action', $action );

		foreach ( $extraFields as $name => $value ) {
			$hiddenFields .= Html::hidden( $name, $value );
		}

		return Html::rawElement( 'form', [
			'method' => 'POST',
			'style' => 'display: inline-block; margin: 0.5em 0;',
		], $hiddenFields .
			Html::submitButton( $label, [ 'class' => 'ontologysync-btn' ] ) );
	}

	/**
	 * @param array<string,string> $rows
	 */
	private function renderInfoTable( array $rows ): string {
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= Html::rawElement( 'tr', [],
				Html::element( 'th', [
					'style' => 'text-align: left; padding: 4px 12px 4px 0;',
				], $label ) .
				Html::rawElement( 'td', [
					'style' => 'padding: 4px 0;',
				], $value )
			);
		}
		return Html::rawElement( 'table',
			[ 'class' => 'ontologysync-info-table' ], $html );
	}

	private function resolveStagingPath(): string {
		$config = $this->getConfig();
		return $this->stagingService->getStagingPath(
			$config->get( 'OntologySyncStagingPath' ),
			$config->get( 'CacheDirectory' )
		);
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}
}
