-- Remove version columns that are no longer used.
-- The no-versioning architecture uses commit SHAs instead.
ALTER TABLE /*_*/ontologysync_bundles DROP COLUMN osb_version;
ALTER TABLE /*_*/ontologysync_modules DROP COLUMN osm_version;
ALTER TABLE /*_*/ontologysync_pages DROP COLUMN osp_installed_version;
