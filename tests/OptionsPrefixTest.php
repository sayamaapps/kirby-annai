<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Options;

/**
 * OPT-1: the options are read as sayamaapps.<plugin>.<key>. A value under the
 * 2.x name <plugin>.<key> still counts, and Kirby's debug mode reports it as
 * deprecated. A null under the new name falls through to the old one.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
	/**
	 * Runs $fn, collects the E_USER_DEPRECATED messages in $reported and returns the result of $fn.
	 *
	 * @param list<string> $reported
	 */
	private function captureDeprecations(array &$reported, callable $fn): mixed
	{
		set_error_handler(static function (int $number, string $message) use (&$reported): bool {
			if ($number !== E_USER_DEPRECATED) {
				return false;
			}
			$reported[] = $message;
			return true;
		}, E_USER_DEPRECATED);
		try {
			return $fn();
		} finally {
			restore_error_handler();
		}
	}

	public function testANullUnderTheNewNameFallsThrough(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.type' => null, 'annai.type' => 'Winery']);
			expect('Winery', Options::get($kirby, 'type'), 'null under the new name: the old value is used');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheDefaultWhenNeitherNameIsSet(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, []);
			expect('LocalBusiness', Options::get($kirby, 'type', 'LocalBusiness'), 'neither name set: the default at the call site');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheGlobalFunctionsStillWorkAndAreReported(): void
	{
		$tmp = annai_site();
		try {
			annai_kirby($tmp, ['debug' => true, 'sayamaapps.annai.active' => true]);
			$reported = [];
			expect(true, $this->captureDeprecations($reported, fn () => annai_active()), 'annai_active(): reads the new name');
			expect('Winery', $this->captureDeprecations($reported, fn () => annai_option('type', 'Winery')), 'annai_option(): the default is used when unset');
			expect(['annai_active() is deprecated, use Sayamaapps\Annai\Options::active()', 'annai_option() is deprecated, use Sayamaapps\Annai\Options::get()'], $reported, 'the two functions name their replacements');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheNewNameWins(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.type' => 'Bakery', 'annai.type' => 'Winery']);
			expect('Bakery', Options::get($kirby, 'type'), 'both names set: the new one is read');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheNorenOptionsFallBackToo(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['debug' => true, 'noren.active' => true]);
			$reported = [];
			expect(true, $this->captureDeprecations($reported, fn () => Options::read($kirby, 'noren', 'active') === true), 'Noren 1.x name: its value is read');
			expect(['noren.active is deprecated, use sayamaapps.noren.active'], $reported, 'Noren 1.x name: reported');
		} finally {
			annai_remove_tree($tmp);
		}
	}

	public function testTheOldNameStillWorksAndIsReported(): void
	{
		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, ['debug' => true, 'annai.type' => 'Winery']);
			$reported = [];
			expect('Winery', $this->captureDeprecations($reported, fn () => Options::get($kirby, 'type')), 'only the old name: its value is read');
			expect(['annai.type is deprecated, use sayamaapps.annai.type'], $reported, 'only the old name: one deprecation names the new key');
		} finally {
			annai_remove_tree($tmp);
		}
	}
}
