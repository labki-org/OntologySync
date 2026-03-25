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

OntologySync bridges the labki-ontology GitHub repo into MediaWiki:

- **labki-ontology** repo: The shared ontology (bundles containing modules of entities)
- **ontology-hub**: Future API layer for ontology lifecycle (not yet integrated)

### Core Flow

1. Admin configures `$wgOntologySyncRepoPath` in LocalSettings.php
2. Extension clones labki-ontology to that path (via Special page or CLI)
3. Admin browses available bundles/modules on Special:OntologySync
4. Selecting a bundle stages its artifact (vocab.json + .wikitext files)
5. `php maintenance/run.php update` triggers SMW's content importer
6. Extension records installed state in DB with content hashes

### Directory Layout

- `src/Hooks/` – MediaWiki hook handlers
  - `OntologySyncHooks.php` – Registers bundle/staging dirs with SMW's $smwgImportFileDirs
  - `PageDisplayHooks.php` – Enhanced footer with provenance (bundle/version) + manage link
  - `SchemaHooks.php` – DB table registration via LoadExtensionSchemaUpdates
- `src/Model/` – Value objects (BundleInfo, ModuleInfo, ImportEntry, RepoStatus)
- `src/Store/` – DB CRUD (BundleStore, ModuleStore, PageStore)
- `src/Service/` – Business logic
  - `GitService.php` – Clone/fetch/pull via Shell::command()
  - `RepoInspector.php` – Read bundle/module definitions from clone
  - `StagingService.php` – Build/clear staging dir
  - `ImportService.php` – Orchestrate install/update/remove lifecycle
  - `HashService.php` – SHA256 of content between OntologySync markers
  - `PageResolver.php` – Map namespace constants to integer IDs
- `src/Special/` – Special:OntologySync with tabbed UI
- `sql/` – Abstract schema (tables.json) + generated MySQL/SQLite DDL

### Database Tables

- `ontologysync_bundles` – Installed bundle metadata and status
- `ontologysync_modules` – Module versions within installed bundles
- `ontologysync_pages` – Per-page provenance and content hashes for edit detection

### Custom Namespaces

| Namespace | ID | Purpose |
|---|---|---|
| OntologyDashboard | 3400 | Dashboard pages with SMW queries |
| OntologyResource | 3402 | Pre-filled content pages |

### Configuration

| Variable | Purpose |
|---|---|
| `$wgOntologySyncRepoUrl` | Git clone URL (default: labki-ontology GitHub) |
| `$wgOntologySyncRepoPath` | Local filesystem path for the clone (required) |
| `$wgOntologySyncStagingPath` | Where import artifacts are staged |
| `$wgOntologySyncBundlePath` | Legacy manual bundle path |
| `$wgOntologySyncAutoRegisterStaging` | Auto-register staging dir with SMW |

### Edit Detection

Content hashes (SHA256) are computed from text between `<!-- OntologySync Start -->` and
`<!-- OntologySync End -->` markers only. User edits outside those markers don't trigger
false "modified" warnings. This also supports future "submit changes upstream" via Ontology Hub.

## Schema Changes

```bash
# Edit sql/tables.json, then regenerate:
./maintenance/regenerateSchema.sh
# Commit all three: tables.json + mysql/ + sqlite/
```

## Code Style

- MediaWiki coding conventions enforced via `mediawiki-codesniffer`
- PSR-4 autoloading: `MediaWiki\Extension\OntologySync\` → `src/`
- Static analysis via Phan with `mediawiki-phan-config`
- Services registered in `src/ServiceWiring.php` with `OntologySync.` prefix
