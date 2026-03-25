<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing the git repository status.
 */
class RepoStatus {

	private bool $isCloned;
	private ?string $localHead;
	private ?string $remoteHead;
	private bool $hasUpdates;

	public function __construct(
		bool $isCloned,
		?string $localHead,
		?string $remoteHead,
		bool $hasUpdates
	) {
		$this->isCloned = $isCloned;
		$this->localHead = $localHead;
		$this->remoteHead = $remoteHead;
		$this->hasUpdates = $hasUpdates;
	}

	public static function notCloned(): self {
		return new self( false, null, null, false );
	}

	public function isCloned(): bool {
		return $this->isCloned;
	}

	public function getLocalHead(): ?string {
		return $this->localHead;
	}

	public function getRemoteHead(): ?string {
		return $this->remoteHead;
	}

	public function hasUpdates(): bool {
		return $this->hasUpdates;
	}
}
