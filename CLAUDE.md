# OntologySync – Development Guide

## Quick Commands

```bash
composer test          # parallel-lint + minus-x check + phpcs
composer run fix       # minus-x fix + phpcbf
composer run phan      # Phan static analysis
php vendor/bin/phpunit # Run unit tests
php vendor/bin/phpunit tests/phpunit/unit/YourTest.php  # Specific test
```

## Test Environment

```bash
bash tests/scripts/reinstall_test_env.sh                         # Default setup
bash tests/scripts/reinstall_test_env.sh --run-jobs --no-jobrunner # Full setup
bash tests/scripts/reinstall_test_env.sh --skip-install --no-jobrunner # Bare wiki
docker compose logs -f wiki                                      # View logs
./tests/scripts/run-docker-tests.sh                              # Unit tests in Docker
./tests/scripts/run-docker-tests.sh integration                  # Integration tests in Docker
```

Local dev wiki runs at http://localhost:8890 (Admin / DockerPass123!).

## Architecture

OntologySync bridges two external systems into MediaWiki:

- **labki-ontology** repo: The ontology definitions (bundles with vocab.json + .wikitext files)
- **ontology-hub**: The management/API layer for ontology lifecycle

The extension registers bundle directories with SMW's `$smwgImportFileDirs` so that
`php maintenance/run.php update` imports all entity pages defined in the bundle manifest.

### Core Directories

- `src/Hooks/` – MediaWiki hook handlers
  - `OntologySyncHooks.php` – Registers bundle path with SMW's content importer (SetupAfterCache)
  - `PageDisplayHooks.php` – Adds management footer to imported pages (ArticleViewFooter)

### Custom Namespaces

| Namespace | ID | Purpose |
|---|---|---|
| OntologyDashboard | 3400 | Dashboard pages with SMW queries |
| OntologyResource | 3402 | Pre-filled content pages |

### Configuration

- `$wgOntologySyncBundlePath` – Absolute path to a bundle version directory

### Management Categories

Imported pages are tagged with categories to mark them as OntologySync-managed:
- `OntologySync-managed`, `OntologySync-managed-property`, `OntologySync-managed-subobject`,
  `OntologySync-managed-dashboard`, `OntologySync-managed-resource`

## Dependencies

- MediaWiki >= 1.43
- Semantic MediaWiki
- SemanticSchemas (+ PageForms, ParserFunctions)

## Code Style

- MediaWiki coding conventions enforced via `mediawiki-codesniffer`
- PSR-4 autoloading: `MediaWiki\Extension\OntologySync\` → `src/`
- Static analysis via Phan with `mediawiki-phan-config`
