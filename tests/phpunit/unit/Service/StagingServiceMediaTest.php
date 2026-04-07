<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Service;

use MediaWiki\Extension\OntologySync\Service\StagingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \MediaWiki\Extension\OntologySync\Service\StagingService::rewriteMediaReferences
 */
class StagingServiceMediaTest extends TestCase {

	private ReflectionMethod $method;
	private StagingService $service;

	protected function setUp(): void {
		parent::setUp();

		// StagingService requires constructor dependencies; use a mock
		$this->service = $this->getMockBuilder( StagingService::class )
			->disableOriginalConstructor()
			->onlyMethods( [] )
			->getMock();

		// Access the private method via reflection
		$this->method = new ReflectionMethod( StagingService::class, 'rewriteMediaReferences' );
		$this->method->setAccessible( true );
	}

	private function rewrite( string $content ): string {
		return $this->method->invoke( $this->service, $content );
	}

	public function testRewritesSimpleFileReference(): void {
		$input = '[[File:photo.png]]';
		$expected = '[[File:OntologySync-photo.png]]';
		$this->assertSame( $expected, $this->rewrite( $input ) );
	}

	public function testRewritesFileReferenceWithParams(): void {
		$input = '[[File:photo.png|thumb|caption]]';
		$expected = '[[File:OntologySync-photo.png|thumb|caption]]';
		$this->assertSame( $expected, $this->rewrite( $input ) );
	}

	public function testDoesNotDoublePrefixAlreadyPrefixed(): void {
		$input = '[[File:OntologySync-photo.png]]';
		$this->assertSame( $input, $this->rewrite( $input ) );
	}

	public function testDoesNotDoublePrefixWithParams(): void {
		$input = '[[File:OntologySync-photo.png|thumb|A caption]]';
		$this->assertSame( $input, $this->rewrite( $input ) );
	}

	public function testLeavesNonFileLinksUntouched(): void {
		$input = '[[Category:SOP]]';
		$this->assertSame( $input, $this->rewrite( $input ) );
	}

	public function testLeavesRegularWikilinksUntouched(): void {
		$input = '[[Some Page]]';
		$this->assertSame( $input, $this->rewrite( $input ) );
	}

	public function testRewritesMultipleFileReferences(): void {
		$input = "See [[File:diagram.svg]] and [[File:photo.jpg|thumb]]";
		$expected = "See [[File:OntologySync-diagram.svg]] and [[File:OntologySync-photo.jpg|thumb]]";
		$this->assertSame( $expected, $this->rewrite( $input ) );
	}

	public function testHandlesMixedPrefixedAndUnprefixed(): void {
		$input = "[[File:new.png]] and [[File:OntologySync-existing.png]]";
		$expected = "[[File:OntologySync-new.png]] and [[File:OntologySync-existing.png]]";
		$this->assertSame( $expected, $this->rewrite( $input ) );
	}

	public function testRewritesWithinLargerWikitext(): void {
		$input = <<<'WIKITEXT'
<!-- OntologySync Start -->
{{SOP
|has_description=A test SOP
|has_image=[[File:sop_diagram.png|thumb|SOP workflow]]
}}
See also [[File:reference.jpg]].
<!-- OntologySync End -->
[[Category:SOP]]
WIKITEXT;

		$result = $this->rewrite( $input );
		$this->assertStringContainsString( '[[File:OntologySync-sop_diagram.png|thumb|SOP workflow]]', $result );
		$this->assertStringContainsString( '[[File:OntologySync-reference.jpg]]', $result );
		$this->assertStringContainsString( '[[Category:SOP]]', $result );
	}
}
