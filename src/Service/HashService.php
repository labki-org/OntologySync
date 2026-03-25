<?php

namespace MediaWiki\Extension\OntologySync\Service;

/**
 * Computes SHA256 content hashes for edit detection.
 *
 * Only hashes content between OntologySync markers to avoid false positives
 * from user edits outside the managed section.
 */
class HashService {

	private const MARKER_START = '<!-- OntologySync Start -->';
	private const MARKER_END = '<!-- OntologySync End -->';

	/**
	 * Compute hash of managed content within a wikitext string.
	 */
	public function hashPageContent( string $wikitext ): ?string {
		$content = $this->extractMarkerContent( $wikitext );
		if ( $content === null ) {
			return null;
		}
		return 'sha256:' . hash( 'sha256', $content );
	}

	/**
	 * Compute hash of managed content within a .wikitext file on disk.
	 */
	public function hashWikitextFile( string $filePath ): ?string {
		if ( !is_readable( $filePath ) ) {
			return null;
		}
		$content = file_get_contents( $filePath );
		if ( $content === false ) {
			return null;
		}
		return $this->hashPageContent( $content );
	}

	/**
	 * Extract text between OntologySync markers.
	 *
	 * @return string|null Content between markers, or null if markers not found
	 */
	public function extractMarkerContent( string $wikitext ): ?string {
		$startPos = strpos( $wikitext, self::MARKER_START );
		$endPos = strpos( $wikitext, self::MARKER_END );

		if ( $startPos === false || $endPos === false || $endPos <= $startPos ) {
			return null;
		}

		$contentStart = $startPos + strlen( self::MARKER_START );
		return trim( substr( $wikitext, $contentStart, $endPos - $contentStart ) );
	}
}
