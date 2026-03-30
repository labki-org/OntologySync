<?php

namespace MediaWiki\Extension\OntologySync\Model;

/**
 * Value object representing the classification of a single entity change
 * during a bundle install or update.
 */
class ChangeClassification {

	private string $page;
	private string $namespace;
	private string $entityType;
	private string $sourceFile;
	private string $moduleId;
	private string $changeType;
	private string $impactLevel;
	private bool $userModified;
	private bool $requiresAdminDecision;
	private string $details;

	/**
	 * @param string $page Page name
	 * @param string $namespace Namespace constant string
	 * @param string $entityType Entity type (categories, properties, etc.)
	 * @param string $sourceFile Relative path to wikitext file
	 * @param string $moduleId Module that owns this entity
	 * @param string $changeType 'new', 'changed', 'deleted', 'none'
	 * @param string $impactLevel 'none', 'patch', 'minor', 'major'
	 * @param bool $userModified Whether the wiki page has user modifications
	 * @param bool $requiresAdminDecision Whether admin must choose overwrite vs skip
	 * @param string $details Human-readable details about the change
	 */
	public function __construct(
		string $page,
		string $namespace,
		string $entityType,
		string $sourceFile,
		string $moduleId,
		string $changeType,
		string $impactLevel,
		bool $userModified,
		bool $requiresAdminDecision,
		string $details
	) {
		$this->page = $page;
		$this->namespace = $namespace;
		$this->entityType = $entityType;
		$this->sourceFile = $sourceFile;
		$this->moduleId = $moduleId;
		$this->changeType = $changeType;
		$this->impactLevel = $impactLevel;
		$this->userModified = $userModified;
		$this->requiresAdminDecision = $requiresAdminDecision;
		$this->details = $details;
	}

	/**
	 * Create a classification for a new entity (not previously installed).
	 */
	public static function newEntity(
		string $page,
		string $namespace,
		string $entityType,
		string $sourceFile,
		string $moduleId
	): self {
		return new self(
			$page, $namespace, $entityType, $sourceFile, $moduleId,
			'new', 'none', false, false, 'New page will be created'
		);
	}

	/**
	 * Create a classification for a deleted entity (was installed, no longer in bundle).
	 */
	public static function deletedEntity(
		string $page,
		string $namespace,
		string $entityType,
		string $sourceFile,
		string $moduleId
	): self {
		return new self(
			$page, $namespace, $entityType, $sourceFile, $moduleId,
			'deleted', 'major', false, true, 'Page will be removed'
		);
	}

	/**
	 * Create a classification for an unchanged entity.
	 */
	public static function unchanged(
		string $page,
		string $namespace,
		string $entityType,
		string $sourceFile,
		string $moduleId,
		bool $userModified
	): self {
		return new self(
			$page, $namespace, $entityType, $sourceFile, $moduleId,
			'none', 'none', $userModified, false,
			$userModified ? 'No repo changes; page has user modifications' : 'No changes'
		);
	}

	public function getPage(): string {
		return $this->page;
	}

	public function getNamespace(): string {
		return $this->namespace;
	}

	public function getEntityType(): string {
		return $this->entityType;
	}

	public function getSourceFile(): string {
		return $this->sourceFile;
	}

	public function getModuleId(): string {
		return $this->moduleId;
	}

	public function getChangeType(): string {
		return $this->changeType;
	}

	public function getImpactLevel(): string {
		return $this->impactLevel;
	}

	public function isUserModified(): bool {
		return $this->userModified;
	}

	public function requiresAdminDecision(): bool {
		return $this->requiresAdminDecision;
	}

	public function getDetails(): string {
		return $this->details;
	}
}
