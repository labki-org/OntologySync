<?php

/**
 * PHPUnit bootstrap file for OntologySync tests.
 *
 * This file sets up the autoloader for running unit tests outside of MediaWiki.
 * These tests are designed to be self-contained and not require a full MW installation.
 */

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Define MediaWiki constants that tests might need
if ( !defined( 'NS_CATEGORY' ) ) {
	define( 'NS_CATEGORY', 14 );
}
if ( !defined( 'NS_ONTOLOGY_DASHBOARD' ) ) {
	define( 'NS_ONTOLOGY_DASHBOARD', 3400 );
}
if ( !defined( 'NS_ONTOLOGY_RESOURCE' ) ) {
	define( 'NS_ONTOLOGY_RESOURCE', 3402 );
}

// Mock wfLogWarning if not defined (used by some classes)
if ( !function_exists( 'wfLogWarning' ) ) {
	function wfLogWarning( $msg ) {
		// Silent in tests
	}
}

// Mock wfMessage if not defined
if ( !function_exists( 'wfMessage' ) ) {
	function wfMessage( $key, ...$params ) {
		return new class( $key ) {
			private string $key;

			public function __construct( string $key ) {
				$this->key = $key;
			}

			public function parse(): string {
				return "[$this->key]";
			}

			public function text(): string {
				return "[$this->key]";
			}
		};
	}
}

// Stub Title class for mock creation in unit tests.
if ( !class_exists( 'MediaWiki\\Title\\Title', false ) ) {
	require_once __DIR__ . '/stubs/Title.php';
}
