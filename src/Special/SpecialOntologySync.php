<?php

namespace MediaWiki\Extension\OntologySync\Special;

use MediaWiki\Extension\OntologySync\Model\ChangeClassification;
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
 * - Overview: Repository status, installed ontology card with action buttons
 * - Browse: Available bundles/modules from local clone
 * - Pages: View managed pages, detect user edits
 *
 * Sub-pages:
 * - review-update: Review changes for updating all installed bundles
 * - add-bundle: Pick an uninstalled bundle to install
 * - review-install/{bundleId}: Review changes for installing a specific bundle
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

		// Handle form submissions (flash messages render before the shell)
		if ( $request->wasPosted() && $request->getVal( 'wpEditToken' ) ) {
			if ( $this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$this->handlePostAction( $request->getVal( 'action' ), $repoPath );
			}
		}

		// Config check — render outside the shell
		if ( $repoPath === null ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'ontologysync-error-no-repopath' )->parse()
			) );
			return;
		}

		$action = $subPage ?: 'overview';

		// Open shell wrapper
		$output->addHTML( Html::openElement( 'div', [ 'class' => 'ontologysync-shell' ] ) );

		$this->showNavigation( $action );

		// Open content area
		$output->addHTML( Html::openElement( 'div', [ 'class' => 'ontologysync-content' ] ) );

		$this->showStagedBanner();

		// Route sub-pages
		if ( str_starts_with( $action, 'review-install/' ) ) {
			$bundleId = substr( $action, strlen( 'review-install/' ) );
			$this->showReviewInstall( $repoPath, $bundleId );
		} else {
			switch ( $action ) {
				case 'browse':
					$this->showBrowse( $repoPath );
					break;
				case 'pages':
					$this->showPages();
					break;
				case 'review-update':
					$this->showReviewUpdate( $repoPath );
					break;
				case 'add-bundle':
					$this->showAddBundle( $repoPath );
					break;
				case 'overview':
				default:
					$this->showOverview( $repoPath );
					break;
			}
		}

		// Close content + shell
		$output->addHTML( Html::closeElement( 'div' ) );
		$output->addHTML( Html::closeElement( 'div' ) );
	}

	private function showNavigation( string $currentAction ): void {
		$tabs = [
			'overview' => $this->msg( 'ontologysync-tab-overview' )->text(),
			'browse' => $this->msg( 'ontologysync-tab-browse' )->text(),
			'pages' => $this->msg( 'ontologysync-tab-pages' )->text(),
		];

		// Determine which tab is active — sub-pages like review-update
		// and add-bundle belong to the overview tab
		$activeTab = $currentAction;
		if ( in_array( $activeTab, [ 'review-update', 'add-bundle' ], true )
			|| str_starts_with( $activeTab, 'review-install/' )
		) {
			$activeTab = 'overview';
		}

		$links = '';
		foreach ( $tabs as $action => $label ) {
			$url = $this->getPageTitle( $action )->getLocalURL();
			$isActive = ( $action === $activeTab );
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
			Html::rawElement( 'nav', [ 'class' => 'ontologysync-tabs' ], $links )
		);
	}

	/**
	 * Show a banner for any bundles in "staged" state, prompting the user
	 * to run update.php.
	 */
	private function showStagedBanner(): void {
		$staged = $this->bundleStore->getStagedBundles();
		if ( $staged === [] ) {
			return;
		}

		$output = $this->getOutput();
		foreach ( $staged as $b ) {
			$commit = substr( $b['osb_repo_commit'] ?? '', 0, 8 );
			$output->addHTML(
				Html::rawElement( 'div', [ 'class' => 'ontologysync-staged-banner' ],
					Html::rawElement( 'div', [ 'class' => 'ontologysync-staged-banner-text' ],
						$this->msg( 'ontologysync-staged-banner' )
							->params( $b['osb_bundle_id'], $commit )->parse()
					) .
					$this->renderActionButton(
						'cancel-stage',
						$this->msg( 'ontologysync-action-cancel-stage' )->text(),
						[ 'bundle' => $b['osb_bundle_id'] ]
					)
				)
			);
		}
	}

	// ----
	// Overview tab
	// ----

	private function showOverview( string $repoPath ): void {
		$output = $this->getOutput();
		$status = $this->gitService->getRepoStatus( $repoPath );

		if ( !$status->isCloned() ) {
			$this->showNotClonedCard();
			return;
		}

		// Repo Status card
		$repoUrl = $this->getConfig()->get( 'OntologySyncRepoUrl' );
		$hasUpdates = $status->hasUpdates();
		$remoteKnown = $status->getRemoteHead() !== null;

		if ( $hasUpdates ) {
			$chipClass = 'is-warning';
			$chipText = $this->msg( 'ontologysync-repo-updates-available' )->text();
		} elseif ( $remoteKnown ) {
			$chipClass = 'is-ok';
			$chipText = $this->msg( 'ontologysync-repo-up-to-date' )->text();
		} else {
			$chipClass = 'is-unknown';
			$chipText = $this->msg( 'ontologysync-repo-status-unknown' )->text();
		}

		$buttons = $this->renderActionButton(
			'fetch', $this->msg( 'ontologysync-action-fetch' )->text()
		);
		if ( $hasUpdates ) {
			$buttons .= $this->renderActionButton(
				'pull', $this->msg( 'ontologysync-action-pull' )->text(), [], 'primary'
			);
		}

		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card' ],
				Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card-content' ],
					Html::rawElement( 'h3', [ 'class' => 'ontologysync-status-card-title' ],
						$this->msg( 'ontologysync-repo-status' )->text() ) .
					Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card-details' ],
						$this->renderDetail(
							$this->msg( 'ontologysync-repo-url' )->text(),
							Html::element( 'code', [], $repoUrl )
						) .
						$this->renderDetail(
							$this->msg( 'ontologysync-repo-local-commit' )->text(),
							Html::element( 'code', [],
								substr( $status->getLocalHead() ?? '', 0, 12 ) )
						)
					)
				) .
				Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card-actions' ],
					Html::element( 'span',
						[ 'class' => 'ontologysync-status-chip ' . $chipClass ],
						$chipText ) .
					Html::rawElement( 'div', [ 'class' => 'ontologysync-hero-buttons' ],
						$buttons )
				)
			)
		);

		// Installed Ontology card
		$bundles = $this->bundleStore->getAllInstalledBundles();
		$repoHead = $status->getLocalHead();
		$totalModules = 0;
		$totalPages = 0;
		$bundleCounts = [];
		$hasUpdateAvailable = false;

		foreach ( $bundles as $b ) {
			$mc = count(
				$this->moduleStore->getModulesForBundle( (int)$b['osb_id'] ) );
			$pc = count(
				$this->pageStore->getPagesForBundle( (int)$b['osb_id'] ) );
			$bundleCounts[(int)$b['osb_id']] = [ 'modules' => $mc, 'pages' => $pc ];
			$totalModules += $mc;
			$totalPages += $pc;

			$installedCommit = $b['osb_repo_commit'] ?? '';
			if ( $repoHead !== null && $installedCommit !== ''
				&& $installedCommit !== $repoHead
			) {
				$hasUpdateAvailable = true;
			}
		}

		$output->addHTML( Html::openElement( 'div', [ 'class' => 'ontologysync-section' ] ) );
		$output->addHTML(
			Html::element( 'h3', [ 'class' => 'ontologysync-section-title' ],
				$this->msg( 'ontologysync-installed-ontology' )->text() )
		);

		if ( $bundles === [] ) {
			$output->addHTML( $this->renderEmptyState(
				$this->msg( 'ontologysync-no-bundles-installed' )->text() ) );

			// Still show the Add Bundle button even when nothing is installed
			$addBundleUrl = $this->getPageTitle( 'add-bundle' )->getLocalURL();
			$output->addHTML(
				Html::rawElement( 'div', [ 'class' => 'ontologysync-action-buttons' ],
					Html::element( 'a', [
						'href' => $addBundleUrl,
						'class' => 'ontologysync-btn ontologysync-btn-primary',
					], $this->msg( 'ontologysync-add-bundle' )->text() )
				)
			);

			$output->addHTML( Html::closeElement( 'div' ) );
			return;
		}

		// Summary line
		$output->addHTML(
			Html::element( 'p', [ 'class' => 'ontologysync-section-intro' ],
				count( $bundles ) . ' '
				. $this->msg( 'ontologysync-stat-bundles' )->text()
				. ', ' . $totalPages . ' '
				. $this->msg( 'ontologysync-stat-pages' )->text() )
		);

		// Installed bundles table
		$rows = '';
		foreach ( $bundles as $b ) {
			$counts = $bundleCounts[(int)$b['osb_id']];
			$moduleCount = $counts['modules'];
			$pageCount = $counts['pages'];

			$commitCell = substr( $b['osb_repo_commit'] ?? '', 0, 8 );
			$installedCommit = $b['osb_repo_commit'] ?? '';
			if ( $repoHead !== null && $installedCommit !== ''
				&& $installedCommit !== $repoHead
			) {
				$commitCell .= ' ' . Html::element( 'span',
					[ 'class' => 'ontologysync-badge-update' ],
					$this->msg( 'ontologysync-update-available' )->text() );
			}

			$rows .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $b['osb_bundle_id'] ) .
				Html::element( 'td', [], (string)$moduleCount ) .
				Html::element( 'td', [], (string)$pageCount ) .
				Html::rawElement( 'td', [], $commitCell )
			);
		}

		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-card' ],
				Html::rawElement( 'table',
					[ 'class' => 'wikitable ontologysync-table' ],
					Html::rawElement( 'thead', [],
						Html::rawElement( 'tr', [],
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-bundle' )->text() ) .
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
			)
		);

		// Action buttons
		$actionButtons = '';

		if ( $hasUpdateAvailable ) {
			$updateUrl = $this->getPageTitle( 'review-update' )->getLocalURL();
			$actionButtons .= Html::element( 'a', [
				'href' => $updateUrl,
				'class' => 'ontologysync-btn ontologysync-btn-primary',
			], $this->msg( 'ontologysync-update-ontology' )->text() );
		}

		$addBundleUrl = $this->getPageTitle( 'add-bundle' )->getLocalURL();
		$actionButtons .= Html::element( 'a', [
			'href' => $addBundleUrl,
			'class' => 'ontologysync-btn',
		], $this->msg( 'ontologysync-add-bundle' )->text() );

		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-action-buttons' ],
				$actionButtons )
		);

		$output->addHTML( Html::closeElement( 'div' ) );
	}

	private function showNotClonedCard(): void {
		$output = $this->getOutput();
		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card' ],
				Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card-content' ],
					Html::rawElement( 'h3', [ 'class' => 'ontologysync-status-card-title' ],
						$this->msg( 'ontologysync-repo-status' )->text() ) .
					Html::rawElement( 'p', [],
						$this->msg( 'ontologysync-repo-not-cloned' )->parse() )
				) .
				Html::rawElement( 'div', [ 'class' => 'ontologysync-status-card-actions' ],
					Html::element( 'span',
						[ 'class' => 'ontologysync-status-chip is-muted' ],
						$this->msg( 'ontologysync-status-not-cloned' )->text() ) .
					$this->renderActionButton(
						'clone',
						$this->msg( 'ontologysync-action-clone' )->text(),
						[],
						'primary'
					)
				)
			)
		);
	}

	// ----
	// Browse tab
	// ----

	private function showBrowse( string $repoPath ): void {
		$output = $this->getOutput();

		if ( !$this->gitService->isCloned( $repoPath ) ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse() ) );
			return;
		}

		$output->addHTML(
			Html::element( 'p', [ 'class' => 'ontologysync-section-intro' ],
				$this->msg( 'ontologysync-browse-intro' )->text() )
		);

		$bundles = $this->repoInspector->listBundles( $repoPath );
		if ( $bundles === [] ) {
			$output->addHTML( $this->renderEmptyState(
				$this->msg( 'ontologysync-no-bundles-in-repo' )->text() ) );
			return;
		}

		foreach ( $bundles as $bundle ) {
			$installed = $this->bundleStore->getInstalledBundle( $bundle->getId() );
			$isInstalled = $installed !== null;

			$statusBadge = '';
			if ( $isInstalled ) {
				$statusBadge = Html::element( 'span',
					[ 'class' => 'ontologysync-badge-installed' ],
					$this->msg( 'ontologysync-installed' )->text() );
			}

			$output->addHTML(
				Html::rawElement( 'div', [ 'class' => 'ontologysync-card' ],
					Html::rawElement( 'div', [ 'class' => 'ontologysync-card-header' ],
						Html::rawElement( 'div',
							[ 'class' => 'ontologysync-card-title-row' ],
							Html::element( 'h4',
								[ 'class' => 'ontologysync-card-title' ],
								$bundle->getLabel() ?: $bundle->getId() ) .
							$statusBadge
						) .
						Html::rawElement( 'div', [ 'class' => 'ontologysync-card-meta' ],
							Html::element( 'span',
								[ 'class' => 'ontologysync-muted' ],
								$bundle->getId() )
						)
					) .
					( $bundle->getDescription()
						? Html::element( 'p', [ 'class' => 'ontologysync-card-desc' ],
							$bundle->getDescription() )
						: '' ) .
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

			$categories = $module->getCategories();
			$catText = $categories !== []
				? Html::element( 'span', [ 'class' => 'ontologysync-muted' ],
					implode( ', ', $categories ) )
				: '';

			$catCount = count( $categories );
			$items .= Html::rawElement( 'li', [],
				Html::element( 'strong', [],
					$module->getLabel() ?: $module->getId() ) .
				' — ' . $catCount .
				' ' . $this->msg( 'ontologysync-categories' )->text() .
				( $catText !== '' ? Html::rawElement( 'br' ) . $catText : '' )
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'ontologysync-card-modules' ],
			Html::element( 'span', [ 'class' => 'ontologysync-card-modules-label' ],
				$this->msg( 'ontologysync-modules-label' )->text() ) .
			Html::rawElement( 'ul', [], $items )
		);
	}

	// ----
	// Review Update sub-page
	// ----

	private function showReviewUpdate( string $repoPath ): void {
		$output = $this->getOutput();

		if ( !$this->gitService->isCloned( $repoPath ) ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse() ) );
			return;
		}

		$installed = $this->bundleStore->getAllInstalledBundles();
		$repoHead = $this->gitService->getLocalHead( $repoPath );

		// Heading with commit range
		$output->addHTML(
			Html::rawElement( 'h3', [ 'class' => 'ontologysync-section-title' ],
				$this->msg( 'ontologysync-review-update-heading' )->text() )
		);

		if ( $installed === [] ) {
			$output->addHTML( $this->renderEmptyState(
				$this->msg( 'ontologysync-no-bundles-installed' )->text() ) );
			return;
		}

		// Determine earliest installed commit for the "from" label
		$fromCommit = null;
		foreach ( $installed as $b ) {
			$c = $b['osb_repo_commit'] ?? '';
			if ( $c !== '' && ( $fromCommit === null || $c < $fromCommit ) ) {
				$fromCommit = $c;
			}
		}

		if ( $fromCommit !== null && $repoHead !== null ) {
			$output->addHTML(
				Html::rawElement( 'p', [ 'class' => 'ontologysync-section-intro' ],
					$this->msg( 'ontologysync-update-from-to' )
						->params(
							Html::element( 'code', [], substr( $fromCommit, 0, 8 ) ),
							Html::element( 'code', [], substr( $repoHead, 0, 8 ) )
						)->text()
				)
			);
		}

		// Gather changes across all installed bundles
		$allChanges = [];
		$seenPages = [];

		foreach ( $installed as $b ) {
			$preview = $this->importService->prepareInstall( $repoPath, $b['osb_bundle_id'] );
			foreach ( $preview['changes'] as $change ) {
				$pageKey = $change->getNamespace() . ':' . $change->getPage();
				if ( !isset( $seenPages[$pageKey] ) ) {
					$seenPages[$pageKey] = true;
					$allChanges[] = $change;
				}
			}
		}

		// Check if any bundles actually need updating
		$anyOutdated = false;
		foreach ( $installed as $b ) {
			$installedCommit = $b['osb_repo_commit'] ?? '';
			if ( $repoHead !== null && $installedCommit !== ''
				&& $installedCommit !== $repoHead
			) {
				$anyOutdated = true;
				break;
			}
		}

		if ( !$anyOutdated ) {
			$output->addHTML( $this->renderEmptyState(
				$this->msg( 'ontologysync-no-updates' )->text() ) );
			return;
		}

		// Render the change review
		$output->addHTML( $this->renderChangeReview( $allChanges ) );

		// Guidance callout
		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-callout' ],
				$this->msg( 'ontologysync-preview-guidance' )->parse()
			)
		);

		// Confirm & Stage button
		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-action-buttons' ],
				$this->renderActionButton(
					'stage-update',
					$this->msg( 'ontologysync-confirm-stage' )->text(),
					[],
					'primary'
				)
			)
		);
	}

	// ----
	// Add Bundle sub-page
	// ----

	private function showAddBundle( string $repoPath ): void {
		$output = $this->getOutput();

		if ( !$this->gitService->isCloned( $repoPath ) ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse() ) );
			return;
		}

		$output->addHTML(
			Html::element( 'h3', [ 'class' => 'ontologysync-section-title' ],
				$this->msg( 'ontologysync-add-bundle' )->text() )
		);

		$repoBundles = $this->repoInspector->listBundles( $repoPath );
		$uninstalled = [];
		foreach ( $repoBundles as $bundle ) {
			$installed = $this->bundleStore->getInstalledBundle( $bundle->getId() );
			if ( $installed === null ) {
				$uninstalled[] = $bundle;
			}
		}

		if ( $uninstalled === [] ) {
			$output->addHTML( $this->renderEmptyState(
				$this->msg( 'ontologysync-all-bundles-installed' )->text() ) );
			return;
		}

		foreach ( $uninstalled as $bundle ) {
			$selectUrl = $this->getPageTitle(
				'review-install/' . $bundle->getId()
			)->getLocalURL();

			$output->addHTML(
				Html::rawElement( 'div', [ 'class' => 'ontologysync-card' ],
					Html::rawElement( 'div', [ 'class' => 'ontologysync-card-header' ],
						Html::rawElement( 'div',
							[ 'class' => 'ontologysync-card-title-row' ],
							Html::element( 'h4',
								[ 'class' => 'ontologysync-card-title' ],
								$bundle->getLabel() ?: $bundle->getId() ) .
							Html::element( 'a', [
								'href' => $selectUrl,
								'class' => 'ontologysync-btn ontologysync-btn-primary',
							], $this->msg( 'ontologysync-select-bundle' )->text() )
						) .
						Html::rawElement( 'div', [ 'class' => 'ontologysync-card-meta' ],
							Html::element( 'span',
								[ 'class' => 'ontologysync-muted' ],
								$bundle->getId() )
						)
					) .
					( $bundle->getDescription()
						? Html::element( 'p', [ 'class' => 'ontologysync-card-desc' ],
							$bundle->getDescription() )
						: '' ) .
					$this->renderModuleList( $repoPath, $bundle->getModules() )
				)
			);
		}
	}

	// ----
	// Review Install sub-page
	// ----

	private function showReviewInstall( string $repoPath, string $bundleId ): void {
		$output = $this->getOutput();

		if ( !$this->gitService->isCloned( $repoPath ) ) {
			$output->addHTML( Html::warningBox(
				$this->msg( 'ontologysync-repo-not-cloned' )->parse() ) );
			return;
		}

		$bundle = $this->repoInspector->getBundle( $repoPath, $bundleId );
		if ( $bundle === null ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'ontologysync-error-bundle-not-found' )
					->params( $bundleId )->parse() ) );
			return;
		}

		$output->addHTML(
			Html::rawElement( 'h3', [ 'class' => 'ontologysync-section-title' ],
				$this->msg( 'ontologysync-review-install-heading' )
					->params( $bundle->getLabel() ?: $bundle->getId() )->text() )
		);

		$preview = $this->importService->prepareInstall( $repoPath, $bundleId );

		if ( $preview['warnings'] !== [] ) {
			foreach ( $preview['warnings'] as $warning ) {
				$output->addHTML( Html::warningBox( htmlspecialchars( $warning ) ) );
			}
		}

		// Render the change review
		$output->addHTML( $this->renderChangeReview( $preview['changes'] ) );

		// Guidance callout
		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-callout' ],
				$this->msg( 'ontologysync-preview-guidance' )->parse()
			)
		);

		// Confirm & Stage button
		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-action-buttons' ],
				$this->renderActionButton(
					'stage-install',
					$this->msg( 'ontologysync-confirm-stage' )->text(),
					[ 'bundle' => $bundleId ],
					'primary'
				)
			)
		);
	}

	// ----
	// Change Review helper
	// ----

	/**
	 * Render a change review with collapsible sections grouped by change type/impact.
	 *
	 * @param ChangeClassification[] $changes
	 */
	private function renderChangeReview( array $changes ): string {
		if ( $changes === [] ) {
			return '';
		}

		// Group changes into sections
		$groups = [
			'new' => [],
			'major' => [],
			'removed' => [],
			'minor' => [],
			'patch' => [],
			'unchanged' => [],
		];

		foreach ( $changes as $change ) {
			$changeType = $change->getChangeType();
			$impactLevel = $change->getImpactLevel();

			if ( $changeType === 'new' ) {
				$groups['new'][] = $change;
			} elseif ( $changeType === 'deleted' ) {
				$groups['removed'][] = $change;
			} elseif ( $changeType === 'changed' && $impactLevel === 'major' ) {
				$groups['major'][] = $change;
			} elseif ( $changeType === 'changed' && $impactLevel === 'minor' ) {
				$groups['minor'][] = $change;
			} elseif ( $changeType === 'changed' ) {
				// patch or any other impact level for changed items
				$groups['patch'][] = $change;
			} elseif ( $changeType === 'none' ) {
				$groups['unchanged'][] = $change;
			}
		}

		// Count totals
		$newCount = count( $groups['new'] );
		$changedCount = count( $groups['major'] ) + count( $groups['minor'] )
			+ count( $groups['patch'] );
		$removedCount = count( $groups['removed'] );
		$unchangedCount = count( $groups['unchanged'] );

		// Stats bar
		$html = Html::rawElement( 'div', [ 'class' => 'ontologysync-review-stats' ],
			$this->msg( 'ontologysync-review-stats' )
				->params(
					(string)$newCount,
					(string)$changedCount,
					(string)$removedCount,
					(string)$unchangedCount
				)->text()
		);

		// Section definitions: [key, msgKey, cssModifier, expandedByDefault]
		$sections = [
			[ 'new', 'ontologysync-section-new', 'is-new', true ],
			[ 'major', 'ontologysync-section-major', 'is-major', true ],
			[ 'removed', 'ontologysync-section-removed', 'is-removed', true ],
			[ 'minor', 'ontologysync-section-minor', 'is-minor', false ],
			[ 'patch', 'ontologysync-section-patch', 'is-patch', false ],
			[ 'unchanged', 'ontologysync-section-unchanged', 'is-unchanged', false ],
		];

		foreach ( $sections as [ $key, $msgKey, $cssModifier, $expanded ] ) {
			$items = $groups[$key];
			if ( $items === [] ) {
				continue;
			}

			$sectionLabel = $this->msg( $msgKey )
				->params( (string)count( $items ) )->text();

			$rows = '';
			foreach ( $items as $change ) {
				$nsId = $this->pageResolver->resolveNamespace( $change->getNamespace() );
				$nsLabel = $nsId !== null
					? $this->pageResolver->getNamespaceLabel( $nsId )
					: $change->getNamespace();

				$pageCell = htmlspecialchars( $change->getPage() );
				if ( $change->isUserModified() ) {
					$pageCell .= ' ' . Html::rawElement( 'span',
						[ 'class' => 'ontologysync-user-modified-indicator' ],
						'&#x26A0;'
					);
				}

				$impactBadge = '';
				if ( $change->getChangeType() === 'changed' ) {
					$impactBadge = Html::element( 'span',
						[ 'class' => 'ontologysync-badge-impact-'
							. $change->getImpactLevel() ],
						$change->getImpactLevel() );
				} elseif ( $change->getChangeType() === 'new' ) {
					$impactBadge = Html::element( 'span',
						[ 'class' => 'ontologysync-badge-impact-none' ],
						$this->msg( 'ontologysync-status-new' )->text() );
				} elseif ( $change->getChangeType() === 'deleted' ) {
					$impactBadge = Html::element( 'span',
						[ 'class' => 'ontologysync-badge-impact-major' ],
						$this->msg( 'ontologysync-impact-deleted' )->text() );
				} else {
					$impactBadge = Html::element( 'span',
						[ 'class' => 'ontologysync-badge-impact-none' ],
						$this->msg( 'ontologysync-impact-unchanged' )->text() );
				}

				$rows .= Html::rawElement( 'tr', [],
					Html::element( 'td', [], $nsLabel ) .
					Html::rawElement( 'td', [], $pageCell ) .
					Html::element( 'td', [], $change->getModuleId() ) .
					Html::rawElement( 'td', [], $impactBadge )
				);
			}

			$table = Html::rawElement( 'table',
				[ 'class' => 'wikitable ontologysync-table ontologysync-table-compact' ],
				Html::rawElement( 'thead', [],
					Html::rawElement( 'tr', [],
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-namespace' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-page' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-module' )->text() ) .
						Html::element( 'th', [],
							$this->msg( 'ontologysync-col-status' )->text() )
					)
				) .
				Html::rawElement( 'tbody', [], $rows )
			);

			$detailsAttrs = [
				'class' => 'ontologysync-change-section ' . $cssModifier,
			];

			$detailsContent = Html::rawElement( 'summary', [], $sectionLabel ) . $table;

			if ( $expanded ) {
				$html .= Html::rawElement( 'details',
					$detailsAttrs + [ 'open' => '' ],
					$detailsContent );
			} else {
				$html .= Html::rawElement( 'details',
					$detailsAttrs,
					$detailsContent );
			}
		}

		return Html::rawElement( 'div', [ 'class' => 'ontologysync-change-review' ], $html );
	}

	// ----
	// Pages tab
	// ----

	private function showPages(): void {
		$output = $this->getOutput();

		$output->addHTML(
			Html::element( 'p', [ 'class' => 'ontologysync-section-intro' ],
				$this->msg( 'ontologysync-pages-intro' )->text() )
		);

		$pages = $this->pageStore->getAllManagedPages();
		if ( $pages === [] ) {
			$output->addHTML( $this->renderEmptyState(
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
				Html::rawElement( 'td', [], $statusText )
			);
		}

		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'ontologysync-card' ],
				Html::rawElement( 'table',
					[ 'class' => 'wikitable ontologysync-table ontologysync-table-compact sortable' ],
					Html::rawElement( 'thead', [],
						Html::rawElement( 'tr', [],
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-namespace' )->text() ) .
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-page' )->text() ) .
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-bundle' )->text() ) .
							Html::element( 'th', [],
								$this->msg( 'ontologysync-col-status' )->text() )
						)
					) .
					Html::rawElement( 'tbody', [], $rows )
				)
			)
		);
	}

	// ----
	// Form action handling
	// ----

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

			case 'stage-update':
				$installed = $this->bundleStore->getAllInstalledBundles();
				$stagingPath = $this->resolveStagingPath();
				$allSuccess = true;
				foreach ( $installed as $b ) {
					if ( !$this->importService->stageBundle(
						$repoPath, $b['osb_bundle_id'], $stagingPath
					) ) {
						$allSuccess = false;
						continue;
					}
					$this->bundleStore->updateBundle( $b['osb_bundle_id'], [
						'osb_status' => 'staged',
						'osb_repo_commit' => $this->gitService->getLocalHead( $repoPath ) ?? '',
						'osb_updated_at' => wfTimestampNow(),
					] );
				}
				if ( $allSuccess ) {
					$output->addHTML( Html::successBox(
						$this->msg( 'ontologysync-stage-success' )->parse() ) );
				} else {
					$output->addHTML( Html::errorBox(
						$this->msg( 'ontologysync-stage-failed' )->text() ) );
				}
				break;

			case 'stage-install':
				$bundleId = $request->getVal( 'bundle' );
				if ( $bundleId ) {
					$this->handleStageAction( $repoPath, $bundleId );
				}
				break;

			case 'cancel-stage':
				$bundleId = $request->getVal( 'bundle' );
				if ( $bundleId ) {
					$stagingRoot = $this->resolveStagingPath();
					$this->stagingService->clearBundleStaging( $stagingRoot, $bundleId );
					$existing = $this->bundleStore->getInstalledBundle( $bundleId );
					if ( $existing !== null && $existing['osb_status'] === 'staged' ) {
						$this->bundleStore->deleteBundle( $bundleId );
					}
					$output->addHTML( Html::successBox(
						$this->msg( 'ontologysync-stage-cancelled' )->text() ) );
				}
				break;

		}
	}

	private function handleStageAction( string $repoPath, string $bundleId ): void {
		$output = $this->getOutput();
		$stagingPath = $this->resolveStagingPath();

		if ( !$this->importService->stageBundle(
			$repoPath, $bundleId, $stagingPath
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
				'osb_status' => 'staged',
				'osb_updated_at' => $now,
			] );
		} else {
			$this->bundleStore->insertBundle( [
				'osb_bundle_id' => $bundleId,
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

	// ----
	// Helpers
	// ----

	/**
	 * @param string $action
	 * @param string $label
	 * @param array $extraFields
	 * @param string $variant '' | 'primary' | 'danger'
	 */
	private function renderActionButton(
		string $action, string $label, array $extraFields = [],
		string $variant = ''
	): string {
		$hiddenFields = Html::hidden(
			'wpEditToken', $this->getUser()->getEditToken()
		) . Html::hidden( 'action', $action );

		foreach ( $extraFields as $name => $value ) {
			$hiddenFields .= Html::hidden( $name, $value );
		}

		$btnClass = 'ontologysync-btn';
		if ( $variant === 'primary' ) {
			$btnClass .= ' ontologysync-btn-primary';
		} elseif ( $variant === 'danger' ) {
			$btnClass .= ' ontologysync-btn-danger';
		}

		return Html::rawElement( 'form', [
			'method' => 'POST',
			'class' => 'ontologysync-inline-form',
		], $hiddenFields .
			Html::submitButton( $label, [ 'class' => $btnClass ] ) );
	}

	private function renderDetail( string $label, string $valueHtml ): string {
		return Html::rawElement( 'div', [ 'class' => 'ontologysync-detail-item' ],
			Html::element( 'span', [ 'class' => 'ontologysync-detail-label' ],
				$label ) .
			Html::rawElement( 'span', [ 'class' => 'ontologysync-detail-value' ],
				$valueHtml )
		);
	}

	private function renderStat(
		string $value, string $label, string $classPrefix = 'ontologysync-stat',
		string $extraClass = ''
	): string {
		$class = $classPrefix . $extraClass;
		return Html::rawElement( 'div', [ 'class' => $class ],
			Html::element( 'span',
				[ 'class' => $classPrefix . '-value' ], $value ) .
			Html::element( 'span',
				[ 'class' => $classPrefix . '-label' ], $label )
		);
	}

	private function renderPreviewStat(
		string $value, string $label, string $extraClass = ''
	): string {
		return $this->renderStat(
			$value, $label, 'ontologysync-preview-stat', $extraClass );
	}

	private function renderEmptyState( string $message ): string {
		return Html::rawElement( 'div', [ 'class' => 'ontologysync-empty-state' ],
			Html::element( 'p', [], $message )
		);
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
		return 'labki';
	}
}
