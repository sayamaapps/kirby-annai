<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Options;

/**
 * The options are read as sayamaapps.annai.<key>, falling back to the 2.x name
 * annai.<key>, flat or nested under annai. The 2.x name is read without a
 * warning; if both are set, the new one wins.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
	private function expectWarningNamed(\Kirby\Cms\App $kirby, string $message): void
	{
		try {
			Options::timezone($kirby);
			holds(false, 'a broken option throws with debug on: ' . $message);
		} catch (\InvalidArgumentException $error) {
			expect('Annai: ' . $message . ' — using Europe/Berlin.', $error->getMessage(), 'the message names the spelling the site used');
		}
	}

	public function testANullFallsBackToTheDefault(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['annai.type' => null]);
			expect('LocalBusiness', Options::get($kirby, 'type', 'LocalBusiness'), 'null: the default at the call site');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testAWarningNamesTheSpellingTheSiteUsed(): void
	{
		$tmp = annai_site();
		try {
			$old = annai_kirby($tmp, ['debug' => true, 'annai.timezone' => 'Mars/Olympus']);
			$this->expectWarningNamed($old, "unknown annai.timezone 'Mars/Olympus'");
			$new = annai_kirby($tmp, ['debug' => true, 'sayamaapps.annai.timezone' => 'Mars/Olympus']);
			$this->expectWarningNamed($new, "unknown sayamaapps.annai.timezone 'Mars/Olympus'");
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testSetKeyNamesTheSpellingTheSiteUsed(): void
	{
		$tmp = annai_site();
		try {
			$old = annai_kirby($tmp, ['annai.timezone' => 'Mars/Olympus']);
			expect('annai.timezone', Options::setKey($old, 'timezone'), 'set as annai.*: the message names annai.timezone');
			$new = annai_kirby($tmp, ['sayamaapps.annai.timezone' => 'Mars/Olympus']);
			expect('sayamaapps.annai.timezone', Options::setKey($new, 'timezone'), 'set as sayamaapps.annai.*: the new name');
			$none = annai_kirby($tmp, []);
			expect('sayamaapps.annai.timezone', Options::setKey($none, 'timezone'), 'not set: the new name');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheDefaultWhenTheOptionIsNotSet(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, []);
			expect('LocalBusiness', Options::get($kirby, 'type', 'LocalBusiness'), 'not set: the default at the call site');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheGlobalFunctionsReadTheNewName(): void
	{
		$tmp = annai_site();
		try {
			annai_kirby($tmp, ['sayamaapps.annai.active' => true, 'sayamaapps.annai.type' => 'Winery']);
			expect(true, annai_active(), 'annai_active(): reads sayamaapps.annai.active');
			expect('Winery', annai_option('type', 'LocalBusiness'), 'annai_option(): reads sayamaapps.annai.type');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheGlobalFunctionsReadTheSameOptions(): void
	{
		$tmp = annai_site();
		try {
			annai_kirby($tmp, ['annai.active' => true, 'annai.type' => 'Winery']);
			expect(true, annai_active(), 'annai_active(): reads annai.active');
			expect('Winery', annai_option('type', 'LocalBusiness'), 'annai_option(): reads annai.type');
			expect('LocalBusiness', annai_option('timezone', 'LocalBusiness'), 'annai_option(): the default when unset');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheNestedSpellingIsRead(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['annai' => ['type' => 'Bakery', 'robots' => ['ai' => 'block']]]);
			expect('Bakery', Options::get($kirby, 'type'), 'nested under annai: the value is read');
			expect('block', Options::get($kirby, 'robots.ai'), 'nested under annai with a dotted key: the value is read');
			expect('LocalBusiness', Options::get($kirby, 'locationTemplate', 'LocalBusiness'), 'nested: an unset key gives the default');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheNewNameIsRead(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.type' => 'Bakery']);
			expect('Bakery', Options::get($kirby, 'type', 'LocalBusiness'), 'sayamaapps.annai.type: the value is read');
			expect('LocalBusiness', Options::get($kirby, 'timezone', 'LocalBusiness'), 'sayamaapps.annai: an unset key gives the default');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheNewNameWinsWhenBothAreSet(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.type' => 'Bakery', 'annai.type' => 'Winery']);
			expect('Bakery', Options::get($kirby, 'type'), 'both names set: sayamaapps.annai.type wins');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheOldNameIsReadWithoutADeprecationReport(): void
	{
		$tmp = annai_site();
		$reports = [];
		try {
			$kirby = annai_kirby($tmp, ['debug' => true, 'annai.type' => 'Winery']);
			set_error_handler(static function (int $level, string $message) use (&$reports): bool {
				$reports[] = $message;
				return true;
			}, E_USER_DEPRECATED);
			try {
				expect('Winery', Options::get($kirby, 'type'), 'annai.type: read as the fallback');
			} finally {
				restore_error_handler();
			}
			expect([], $reports, 'annai.type: no E_USER_DEPRECATED with debug on');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheOptionIsReadUnderTheAnnaiName(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['annai.type' => 'Winery']);
			expect('Winery', Options::get($kirby, 'type'), 'annai.type: the value is read');
			expect('Winery', Options::get($kirby, 'type', 'LocalBusiness'), 'annai.type: the value wins over the default');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testThePrefixConstants(): void
	{
		expect('sayamaapps.annai.', Options::OPTION_PREFIX, 'OPTION_PREFIX is the plugin name');
		expect('annai.', Options::PREFIX, 'PREFIX keeps its 2.x value');
	}
}
