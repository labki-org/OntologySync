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
   ```

3. Download a bundle version artifact from the labki-ontology repository
   (e.g., `bundles/Default/versions/1.0.0/`) to a local path on your server.

4. Configure the bundle path in `LocalSettings.php`:
   ```php
   $wgOntologySyncBundlePath = '/path/to/bundles/Default/versions/1.0.0';
   ```

5. Run the MediaWiki update script to import all ontology entities:
   ```bash
   php maintenance/run.php update
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
