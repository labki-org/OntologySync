<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Service;

use MediaWiki\Extension\OntologySync\Service\RepoInspector;
use MediaWiki\Extension\OntologySync\Service\WikitextParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\OntologySync\Service\WikitextParser
 */
class WikitextParserTest extends TestCase {

	private WikitextParser $parser;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$repoInspector = new RepoInspector();
		$this->parser = new WikitextParser( $repoInspector );

		// Create a temporary directory structure for test wikitext files
		$this->tmpDir = sys_get_temp_dir() . '/ontologysync_test_' . uniqid();
		mkdir( $this->tmpDir . '/categories', 0777, true );
		mkdir( $this->tmpDir . '/properties', 0777, true );
		mkdir( $this->tmpDir . '/subobjects', 0777, true );
		mkdir( $this->tmpDir . '/resources/SOP', 0777, true );
	}

	protected function tearDown(): void {
		// Clean up temp files
		$this->removeDir( $this->tmpDir );
		parent::tearDown();
	}

	private function removeDir( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->removeDir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	private function writeWikitext( string $entityType, string $entityKey, string $content ): void {
		$path = $this->tmpDir . '/' . $entityType . '/' . $entityKey . '.wikitext';
		$dir = dirname( $path );
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $path, $content );
	}

	// ─── parseCategory tests ────────────────────────────────────────────────

	public function testParseCategoryFull(): void {
		$this->writeWikitext( 'categories', 'Person', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{Category
|has_description=A human person
|has_parent_category=Agent
|has_required_property=Has first name, Has last name
|has_optional_property=Has birthday, Has middle name
|has_optional_subobject=Has training record
}}
<!-- OntologySync End -->
[[Category:OntologySync-managed]]
WIKITEXT
		);

		$result = $this->parser->parseCategory( $this->tmpDir, 'Person' );

		$this->assertNotNull( $result );
		$this->assertSame( [ 'Agent' ], $result['parents'] );
		$this->assertSame( [ 'Has_first_name', 'Has_last_name' ], $result['required_properties'] );
		$this->assertSame( [ 'Has_birthday', 'Has_middle_name' ], $result['optional_properties'] );
		$this->assertSame( [], $result['required_subobjects'] );
		$this->assertSame( [ 'Has_training_record' ], $result['optional_subobjects'] );
	}

	public function testParseCategoryMinimal(): void {
		$this->writeWikitext( 'categories', 'Agent', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{Category
|has_description=Base agent
}}
<!-- OntologySync End -->
[[Category:OntologySync-managed]]
WIKITEXT
		);

		$result = $this->parser->parseCategory( $this->tmpDir, 'Agent' );

		$this->assertNotNull( $result );
		$this->assertSame( [], $result['parents'] );
		$this->assertSame( [], $result['required_properties'] );
		$this->assertSame( [], $result['optional_properties'] );
	}

	public function testParseCategoryReturnsNullForMissingFile(): void {
		$result = $this->parser->parseCategory( $this->tmpDir, 'NonExistent' );
		$this->assertNull( $result );
	}

	public function testParseCategoryCachesResult(): void {
		$this->writeWikitext( 'categories', 'Cached', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{Category
|has_description=Cached category
|has_required_property=Has name
}}
<!-- OntologySync End -->
[[Category:OntologySync-managed]]
WIKITEXT
		);

		$result1 = $this->parser->parseCategory( $this->tmpDir, 'Cached' );
		// Delete the file to prove caching works
		unlink( $this->tmpDir . '/categories/Cached.wikitext' );
		$result2 = $this->parser->parseCategory( $this->tmpDir, 'Cached' );

		$this->assertSame( $result1, $result2 );
	}

	// ─── parseSubobject tests ───────────────────────────────────────────────

	public function testParseSubobject(): void {
		$this->writeWikitext( 'subobjects', 'Has_training_record', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{Subobject
|has_description=A training record
|display_label=Training Record
|has_required_property=Has training, Has completion date
|has_optional_property=Has expiration date, Has training status
}}
<!-- OntologySync End -->
[[Category:OntologySync-managed-subobject]]
WIKITEXT
		);

		$result = $this->parser->parseSubobject( $this->tmpDir, 'Has_training_record' );

		$this->assertNotNull( $result );
		$this->assertSame( [ 'Has_training', 'Has_completion_date' ], $result['required_properties'] );
		$this->assertSame( [ 'Has_expiration_date', 'Has_training_status' ], $result['optional_properties'] );
	}

	// ─── parseProperty tests ────────────────────────────────────────────────

	public function testParsePropertyWithTemplate(): void {
		$this->writeWikitext( 'properties', 'Has_link', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{Property
|has_description=A link
|has_type=Page
|has_template=Property/Page
}}
<!-- OntologySync End -->
[[Category:OntologySync-managed-property]]
WIKITEXT
		);

		$result = $this->parser->parseProperty( $this->tmpDir, 'Has_link' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Property/Page', $result['has_display_template'] );
	}

	public function testParsePropertyWithoutTemplate(): void {
		$this->writeWikitext( 'properties', 'Has_name', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{Property
|has_description=The name
|has_type=Text
|display_label=Name
}}
<!-- OntologySync End -->
[[Category:OntologySync-managed-property]]
WIKITEXT
		);

		$result = $this->parser->parseProperty( $this->tmpDir, 'Has_name' );

		$this->assertNotNull( $result );
		$this->assertNull( $result['has_display_template'] );
	}

	// ─── discoverResources tests ────────────────────────────────────────────

	public function testDiscoverResourcesMatchesCategory(): void {
		$this->writeWikitext( 'resources/SOP', 'Test_sop', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{SOP
|has_description=A test SOP
|has_document_type=SOP
}}
<!-- OntologySync End -->
[[Category:SOP]]
[[Category:OntologySync-managed-resource]]
WIKITEXT
		);

		$result = $this->parser->discoverResources( $this->tmpDir, [ 'SOP' ] );

		$this->assertSame( [ 'SOP/Test_sop' ], $result );
	}

	public function testDiscoverResourcesIgnoresNonMatchingCategory(): void {
		$this->writeWikitext( 'resources/SOP', 'Other', <<<'WIKITEXT'
<!-- OntologySync Start -->
{{SOP
|has_description=Other SOP
}}
<!-- OntologySync End -->
[[Category:SOP]]
[[Category:OntologySync-managed-resource]]
WIKITEXT
		);

		$result = $this->parser->discoverResources( $this->tmpDir, [ 'Equipment' ] );

		$this->assertSame( [], $result );
	}
}
