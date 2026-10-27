<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Options;

/**
 * The options are read as annai.<key>, flat or nested under annai. A name the
 * 2.x line never read (sayamaapps.annai.<key>) is not read either: that rename
 * is planned for 2.0 (README, "Planned for 2.0").
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
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

	public function testThePrefixConstantKeepsTheTwoXValue(): void
	{
		expect('annai.', Options::PREFIX, 'PREFIX keeps its 2.x value');
	}

	public function testTheSayamaappsNameIsNotRead(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.type' => 'Bakery', 'annai.type' => 'Winery']);
			expect('Winery', Options::get($kirby, 'type'), 'both names set: only annai.type is read in the 2.x line');
		} finally {
			annai_remove_tree($tmp);
		}
	}
}
