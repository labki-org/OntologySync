<?php

/**
 * Phan configuration for OntologySync
 */

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'src/',
		'../../extensions/SemanticMediaWiki',
		'../../extensions/SemanticSchemas',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/SemanticMediaWiki',
		'../../extensions/SemanticSchemas',
		'vendor/',
	]
);

$cfg['analyzed_file_extensions'] = [ 'php' ];

return $cfg;
