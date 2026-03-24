<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing a single import entry from a vocab.json manifest.
 */
class ImportEntry {

	private string $page;
	private string $namespace;
	private string $importFrom;
	private bool $replaceable;

	public function __construct(
		string $page,
		string $namespace,
		string $importFrom,
		bool $replaceable
	) {
		$this->page = $page;
		$this->namespace = $namespace;
		$this->importFrom = $importFrom;
		$this->replaceable = $replaceable;
	}

	public static function fromJson( array $data ): self {
		return new self(
			$data['page'] ?? '',
			$data['namespace'] ?? '',
			$data['contents']['importFrom'] ?? '',
			$data['options']['replaceable'] ?? true
		);
	}

	public function getPage(): string {
		return $this->page;
	}

	public function getNamespace(): string {
		return $this->namespace;
	}

	public function getImportFrom(): string {
		return $this->importFrom;
	}

	public function isReplaceable(): bool {
		return $this->replaceable;
	}
}
