<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\Check;
use Sayamaapps\Annai\Head;
use Sayamaapps\Annai\I18n;
use Sayamaapps\Annai\JsonLd;
use Sayamaapps\Annai\LlmsTxt;
use Sayamaapps\Annai\Options;
use Sayamaapps\Annai\Pages;
use Sayamaapps\Annai\Robots;
use Sayamaapps\Annai\Routes;
use Sayamaapps\Annai\Sitemap;
use Sayamaapps\Annai\Text;

/**
 * The class map in autoload.php matches src/: one entry per file, the file
 * name is the class name, and every class loads through the map.
 *
 * The test loads every class of the package through the map, so it covers each
 * of them.
 */
#[CoversClass(Business::class)]
#[CoversClass(Check::class)]
#[CoversClass(Head::class)]
#[CoversClass(I18n::class)]
#[CoversClass(JsonLd::class)]
#[CoversClass(LlmsTxt::class)]
#[CoversClass(Options::class)]
#[CoversClass(Pages::class)]
#[CoversClass(Robots::class)]
#[CoversClass(Routes::class)]
#[CoversClass(Sitemap::class)]
#[CoversClass(Text::class)]
final class AutoloadTest extends TestCase
{
	public function testEveryEntryLoadsItsClass(): void
	{
		$root = dirname(__DIR__);
		foreach (require $root . '/autoload.php' as $class => $path) {
			$this->assertFileExists($root . '/' . $path);
			$this->assertTrue(class_exists($class), 'class ' . $class . ' loads through the map');
		}
	}

	public function testLegacyNamesAreAliasesOfTheNewClasses(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		foreach (require dirname(__DIR__) . '/autoload.php' as $class => $path) {
			$short = substr($class, strlen('sayamaapps\\annai\\'));
			$this->assertSame(
				strtolower('Sayamaapps\\Annai\\' . $short),
				strtolower((new ReflectionClass('Annai\\' . $short))->getName()),
				'Annai\\' . $short . ' is an alias of the new class'
			);
		}
	}

	public function testMapListsEveryClassFileAndNothingElse(): void
	{
		$root = dirname(__DIR__);
		$map = require $root . '/autoload.php';

		$files = glob($root . '/src/*.php');
		$this->assertIsArray($files);

		$expected = [];
		foreach ($files as $file) {
			$expected[strtolower('sayamaapps\\annai\\' . basename($file, '.php'))] = 'src/' . basename($file);
		}

		$this->assertSame($expected, $map);
	}
}
