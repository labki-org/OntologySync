-- Remove version columns that are no longer used.
-- The no-versioning architecture uses commit SHAs instead.
--
-- SQLite does not support DROP COLUMN before 3.35.0. For older versions,
-- tables must be recreated. MediaWiki's DatabaseUpdater handles this
-- via the addExtensionUpdate mechanism when necessary.

-- Recreate ontologysync_bundles without osb_version
CREATE TABLE /*_*/ontologysync_bundles_tmp (
  osb_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  osb_bundle_id VARCHAR(100) NOT NULL,
  osb_label VARCHAR(255) DEFAULT NULL,
  osb_description TEXT DEFAULT NULL,
  osb_repo_commit VARCHAR(40) DEFAULT NULL,
  osb_installed_by INTEGER UNSIGNED DEFAULT NULL,
  osb_installed_at BLOB NOT NULL,
  osb_updated_at BLOB NOT NULL,
  osb_status VARCHAR(20) DEFAULT 'installed' NOT NULL
);
INSERT INTO /*_*/ontologysync_bundles_tmp
  (osb_id, osb_bundle_id, osb_label, osb_description, osb_repo_commit,
   osb_installed_by, osb_installed_at, osb_updated_at, osb_status)
  SELECT osb_id, osb_bundle_id, osb_label, osb_description, osb_repo_commit,
         osb_installed_by, osb_installed_at, osb_updated_at, osb_status
  FROM /*_*/ontologysync_bundles;
DROP TABLE /*_*/ontologysync_bundles;
ALTER TABLE /*_*/ontologysync_bundles_tmp RENAME TO /*_*/ontologysync_bundles;
CREATE UNIQUE INDEX osb_bundle_id_unique ON /*_*/ontologysync_bundles (osb_bundle_id);
CREATE INDEX idx_osb_status ON /*_*/ontologysync_bundles (osb_status);

-- Recreate ontologysync_modules without osm_version
CREATE TABLE /*_*/ontologysync_modules_tmp (
  osm_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  osm_module_id VARCHAR(100) NOT NULL,
  osm_bundle_id INTEGER UNSIGNED NOT NULL,
  osm_label VARCHAR(255) DEFAULT NULL,
  osm_installed_at BLOB NOT NULL
);
INSERT INTO /*_*/ontologysync_modules_tmp
  (osm_id, osm_module_id, osm_bundle_id, osm_label, osm_installed_at)
  SELECT osm_id, osm_module_id, osm_bundle_id, osm_label, osm_installed_at
  FROM /*_*/ontologysync_modules;
DROP TABLE /*_*/ontologysync_modules;
ALTER TABLE /*_*/ontologysync_modules_tmp RENAME TO /*_*/ontologysync_modules;
CREATE UNIQUE INDEX osm_bundle_module_unique ON /*_*/ontologysync_modules (osm_bundle_id, osm_module_id);
CREATE INDEX idx_osm_bundle_id ON /*_*/ontologysync_modules (osm_bundle_id);
CREATE INDEX idx_osm_module_id ON /*_*/ontologysync_modules (osm_module_id);

-- Recreate ontologysync_pages without osp_installed_version
CREATE TABLE /*_*/ontologysync_pages_tmp (
  osp_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  osp_bundle_id INTEGER UNSIGNED NOT NULL,
  osp_module_id INTEGER UNSIGNED NOT NULL,
  osp_page_name VARCHAR(255) NOT NULL,
  osp_page_namespace INTEGER NOT NULL,
  osp_wiki_page_id INTEGER UNSIGNED DEFAULT NULL,
  osp_content_hash VARCHAR(71) DEFAULT NULL,
  osp_source_file VARCHAR(255) DEFAULT NULL,
  osp_installed_at BLOB NOT NULL,
  osp_updated_at BLOB NOT NULL
);
INSERT INTO /*_*/ontologysync_pages_tmp
  (osp_id, osp_bundle_id, osp_module_id, osp_page_name, osp_page_namespace,
   osp_wiki_page_id, osp_content_hash, osp_source_file, osp_installed_at, osp_updated_at)
  SELECT osp_id, osp_bundle_id, osp_module_id, osp_page_name, osp_page_namespace,
         osp_wiki_page_id, osp_content_hash, osp_source_file, osp_installed_at, osp_updated_at
  FROM /*_*/ontologysync_pages;
DROP TABLE /*_*/ontologysync_pages;
ALTER TABLE /*_*/ontologysync_pages_tmp RENAME TO /*_*/ontologysync_pages;
CREATE UNIQUE INDEX osp_page_unique ON /*_*/ontologysync_pages (osp_page_namespace, osp_page_name);
CREATE INDEX idx_osp_bundle_id ON /*_*/ontologysync_pages (osp_bundle_id);
CREATE INDEX idx_osp_module_id ON /*_*/ontologysync_pages (osp_module_id);
CREATE INDEX idx_osp_wiki_page_id ON /*_*/ontologysync_pages (osp_wiki_page_id);
