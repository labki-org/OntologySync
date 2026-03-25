<?php

namespace MediaWiki\Extension\OntologySync\Service;

use MediaWiki\Extension\OntologySync\Model\RepoStatus;
use MediaWiki\Shell\Shell;

/**
 * Manages the local git clone of the labki-ontology repository.
 *
 * Uses MediaWiki's Shell::command() for safe subprocess execution.
 */
class GitService {

	/**
	 * Clone the repository to the given path.
	 */
	public function cloneRepo( string $url, string $path ): bool {
		if ( Shell::isDisabled() ) {
			return false;
		}

		$parentDir = dirname( $path );
		if ( !is_dir( $parentDir ) ) {
			mkdir( $parentDir, 0755, true );
		}

		$result = Shell::command( 'git', 'clone', '--depth', '1', $url, $path )
			->limits( [ 'time' => 120, 'memory' => 0 ] )
			->disableNetwork( false )
			->disableSandbox()
			->execute();

		return $result->getExitCode() === 0;
	}

	/**
	 * Fetch updates from remote without merging.
	 */
	public function fetchRemote( string $repoPath ): bool {
		if ( Shell::isDisabled() || !$this->isCloned( $repoPath ) ) {
			return false;
		}

		$result = Shell::command( 'git', '-C', $repoPath, 'fetch', 'origin' )
			->limits( [ 'time' => 60, 'memory' => 0 ] )
			->disableNetwork( false )
			->disableSandbox()
			->execute();

		return $result->getExitCode() === 0;
	}

	/**
	 * Pull latest changes from remote.
	 */
	public function pullLatest( string $repoPath ): bool {
		if ( Shell::isDisabled() || !$this->isCloned( $repoPath ) ) {
			return false;
		}

		$result = Shell::command( 'git', '-C', $repoPath, 'pull', 'origin', 'main' )
			->limits( [ 'time' => 60, 'memory' => 0 ] )
			->disableNetwork( false )
			->disableSandbox()
			->execute();

		return $result->getExitCode() === 0;
	}

	/**
	 * Get the local HEAD commit SHA.
	 */
	public function getLocalHead( string $repoPath ): ?string {
		if ( Shell::isDisabled() || !$this->isCloned( $repoPath ) ) {
			return null;
		}

		$result = Shell::command( 'git', '-C', $repoPath, 'rev-parse', 'HEAD' )
			->execute();

		if ( $result->getExitCode() !== 0 ) {
			return null;
		}

		return trim( $result->getStdout() );
	}

	/**
	 * Get the remote HEAD commit SHA (after fetch).
	 */
	public function getRemoteHead( string $repoPath ): ?string {
		if ( Shell::isDisabled() || !$this->isCloned( $repoPath ) ) {
			return null;
		}

		$result = Shell::command( 'git', '-C', $repoPath, 'rev-parse', 'origin/main' )
			->execute();

		if ( $result->getExitCode() !== 0 ) {
			return null;
		}

		return trim( $result->getStdout() );
	}

	/**
	 * Check if the repo path contains a git clone.
	 */
	public function isCloned( string $repoPath ): bool {
		return is_dir( $repoPath . '/.git' );
	}

	/**
	 * Get full repository status.
	 */
	public function getRepoStatus( string $repoPath ): RepoStatus {
		if ( !$this->isCloned( $repoPath ) ) {
			return RepoStatus::notCloned();
		}

		$localHead = $this->getLocalHead( $repoPath );
		$remoteHead = $this->getRemoteHead( $repoPath );
		$hasUpdates = ( $localHead !== null && $remoteHead !== null && $localHead !== $remoteHead );

		return new RepoStatus( true, $localHead, $remoteHead, $hasUpdates );
	}
}
