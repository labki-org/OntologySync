# OntologySync

MediaWiki extension that connects wikis to the [Labki ontology](https://github.com/labki-org/labki-ontology) via SMW's built-in content importer.

## Requirements

- MediaWiki >= 1.43
- [Semantic MediaWiki](https://www.semantic-mediawiki.org/)
- [SemanticSchemas](https://github.com/labki-org/SemanticSchemas)

## Installation

1. Clone into your extensions directory:
   ```bash
   cd extensions
   git clone https://github.com/labki-org/OntologySync.git
   ```

2. Add to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'OntologySync' );
   $wgOntologySyncRepoPath = '/var/lib/ontologysync/labki-ontology';
   ```

3. Run the MediaWiki update script to create the extension's database tables:
   ```bash
   php maintenance/run.php update
   ```

4. Visit **Special:OntologySync** to clone the ontology repo and install bundles.

## Configuration

All settings are optional except `$wgOntologySyncRepoPath`, which must be set for the extension to function.

Add these to `LocalSettings.php` after `wfLoadExtension( 'OntologySync' );`:

| Variable | Type | Default | Description |
|---|---|---|---|
| `$wgOntologySyncRepoPath` | `string` | `null` | **Required.** Local filesystem path where the labki-ontology repository will be cloned. The web server user must have write access to this path. |
| `$wgOntologySyncRepoUrl` | `string` | `https://github.com/labki-org/labki-ontology` | Git URL of the ontology repository to clone. Change this to use a fork or private mirror. |
| `$wgOntologySyncStagingPath` | `string\|null` | `null` | Path where import artifacts (vocab.json + .wikitext files) are staged before import. When `null`, defaults to `$wgCacheDirectory/ontologysync-staging`. |
| `$wgOntologySyncBundlePath` | `string\|null` | `null` | Absolute path to a bundle version directory for legacy/manual import (e.g., `bundles/Default/versions/1.0.0/`). The directory must contain a `*.vocab.json` manifest and `.wikitext` entity files. When set, it is registered with SMW's `$smwgImportFileDirs` so that `update.php` imports the bundle. |
| `$wgOntologySyncAutoRegisterStaging` | `bool` | `true` | When `true`, the staging directory is automatically registered with SMW's `$smwgImportFileDirs` if it contains a `vocab.json`. Set to `false` to manage SMW import directories manually. |

### Minimal example

```php
wfLoadExtension( 'OntologySync' );
$wgOntologySyncRepoPath = '/var/lib/ontologysync/labki-ontology';
```

### Full example

```php
wfLoadExtension( 'OntologySync' );
$wgOntologySyncRepoPath = '/var/lib/ontologysync/labki-ontology';
$wgOntologySyncRepoUrl = 'https://github.com/my-org/my-ontology-fork';
$wgOntologySyncStagingPath = '/var/cache/ontologysync-staging';
$wgOntologySyncAutoRegisterStaging = true;
```

## How it works

OntologySync registers the bundle directory with SMW's `$smwgImportFileDirs`.
The bundle directory contains a `*.vocab.json` manifest (the format SMW's
content importer understands) and `.wikitext` files for each entity page.

When `update.php` runs, SMW's importer reads the vocab.json and creates/updates
all listed wiki pages in their correct namespaces.

## Custom namespaces

OntologySync registers two custom namespaces:

| Namespace | ID | Purpose |
|---|---|---|
| `OntologyDashboard` | 3400 | Dashboard pages with SMW queries |
| `OntologyResource` | 3402 | Pre-filled content pages |

These can be locked down using MediaWiki's [Lockdown extension](https://www.mediawiki.org/wiki/Extension:Lockdown) for access control.

## Management markers

Imported pages are tagged with management categories:
- `OntologySync-managed` (categories)
- `OntologySync-managed-property` (properties)
- `OntologySync-managed-subobject` (subobjects)
- `OntologySync-managed-dashboard` (dashboards)
- `OntologySync-managed-resource` (resources)

Pages with these categories display a footer warning that manual edits may be
overwritten on the next ontology update.
