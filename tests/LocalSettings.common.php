<?php
// Shared test settings for OntologySync (used by both CI and local dev).

wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( 'localhost' );
wfLoadExtension( 'PageForms' );
wfLoadExtension( 'ParserFunctions' );
wfLoadExtension( 'SemanticSchemas' );

$smwgChangePropagationProtection = false;
$smwgEnabledDeferredUpdate = false;
$smwgAutoSetupStore = false;
$smwgQMaxInlineLimit = 500;

$wgPageFormsAllowCreateInRestrictedNamespaces = true;
$wgPageFormsLinkAllRedLinksToForms = true;
$wgPageFormsFormCacheType = CACHE_NONE;
$wgNamespacesWithSemanticLinks[NS_CATEGORY] = true;

$wgShowExceptionDetails = true;
