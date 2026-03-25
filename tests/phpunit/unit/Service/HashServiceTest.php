<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Service;

use MediaWiki\Extension\OntologySync\Service\HashService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\OntologySync\Service\HashService
 */
class HashServiceTest extends TestCase {

	private HashService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new HashService();
	}

	public function testExtractMarkerContentReturnsContentBetweenMarkers(): void {
		$wikitext = "before\n<!-- OntologySync Start -->\n[[Has type::Text]]\n<!-- OntologySync End -->\nafter";
		$result = $this->service->extractMarkerContent( $wikitext );
		$this->assertSame( '[[Has type::Text]]', $result );
	}

	public function testExtractMarkerContentReturnsNullWithoutMarkers(): void {
		$wikitext = "no markers here";
		$this->assertNull( $this->service->extractMarkerContent( $wikitext ) );
	}

	public function testExtractMarkerContentReturnsNullWithOnlyStartMarker(): void {
		$wikitext = "<!-- OntologySync Start -->\ncontent";
		$this->assertNull( $this->service->extractMarkerContent( $wikitext ) );
	}

	public function testHashPageContentReturnsSha256(): void {
		$wikitext = "<!-- OntologySync Start -->\n[[Has type::Text]]\n<!-- OntologySync End -->";
		$hash = $this->service->hashPageContent( $wikitext );
		$this->assertNotNull( $hash );
		$this->assertStringStartsWith( 'sha256:', $hash );
		$this->assertSame( 71, strlen( $hash ) );
	}

	public function testHashPageContentIsDeterministic(): void {
		$wikitext = "<!-- OntologySync Start -->\n[[Has type::Text]]\n<!-- OntologySync End -->";
		$hash1 = $this->service->hashPageContent( $wikitext );
		$hash2 = $this->service->hashPageContent( $wikitext );
		$this->assertSame( $hash1, $hash2 );
	}

	public function testHashPageContentDiffersForDifferentContent(): void {
		$wikitext1 = "<!-- OntologySync Start -->\n[[Has type::Text]]\n<!-- OntologySync End -->";
		$wikitext2 = "<!-- OntologySync Start -->\n[[Has type::Page]]\n<!-- OntologySync End -->";
		$this->assertNotSame(
			$this->service->hashPageContent( $wikitext1 ),
			$this->service->hashPageContent( $wikitext2 )
		);
	}

	public function testHashIgnoresContentOutsideMarkers(): void {
		$wikitext1 = "<!-- OntologySync Start -->\n[[Has type::Text]]\n<!-- OntologySync End -->";
		$wikitext2 = "user edit\n<!-- OntologySync Start -->\n" .
			"[[Has type::Text]]\n<!-- OntologySync End -->\nmore edits";
		$this->assertSame(
			$this->service->hashPageContent( $wikitext1 ),
			$this->service->hashPageContent( $wikitext2 )
		);
	}

	public function testHashPageContentReturnsNullWithoutMarkers(): void {
		$this->assertNull( $this->service->hashPageContent( 'no markers' ) );
	}
}
