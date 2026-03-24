<?php

namespace MediaWiki\Extension\OntologySync\Tests\Unit\Hooks;

use MediaWiki\Extension\OntologySync\Hooks\OntologySyncHooks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\OntologySync\Hooks\OntologySyncHooks
 */
class OntologySyncHooksTest extends TestCase {

	public function testOnSetupAfterCacheSkipsWhenSmwNotLoaded(): void {
		// SMW_EXTENSION_LOADED is not defined in the unit test environment,
		// so the hook should return early without error.
		OntologySyncHooks::onSetupAfterCache();
		$this->assertTrue( true );
	}
}
