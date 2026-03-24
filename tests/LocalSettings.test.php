<?php
// Server Config
$wgServer = 'http://localhost:8890';

// OntologySync
wfLoadExtension( 'OntologySync', '/mw-user-extensions/OntologySync/extension.json' );

// Debugging
$wgDebugLogGroups['ontologysync'] = '/var/log/mediawiki/ontologysync.log';
$wgDebugLogFile = '/var/log/mediawiki/debug.log';

// Cache
$wgCacheDirectory = "$IP/cache-ontologysync";

// Skin
wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector';
