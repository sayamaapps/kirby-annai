<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\I18n;
use Sayamaapps\Annai\JsonLd;
use Sayamaapps\Annai\LlmsTxt;
use Sayamaapps\Annai\Options;
use Sayamaapps\Annai\Routes;

/**
 * Gasthaus Muster in a throwaway site: Annai on, the given options, and
 * Kirby hooks (`hooks` of the App, as a plugin registers them).
 */
function gastroSite(array $options, callable $fn, array $hooks = []): void
{
	$tmp = annai_site();
	try {
		annai_page($tmp, '', 'site', ['title' => 'Gasthaus Muster', 'marke' => 'Gasthaus Muster']);
		annai_page($tmp, '1_kontakt', 'betrieb', ['title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt', 'telefon' => '030 / 34567000']);
		Kirby\Template\Snippet::$cache = [];
		$kirby = new App([
			'roots' => [
				'base'    => $tmp,
				'index'   => $tmp . '/public',
				'content' => $tmp . '/content',
				'site'    => $tmp . '/site',
				'storage' => $tmp . '/storage',
			],
			'options' => $options + ['sayamaapps.annai.active' => true, 'url' => 'https://example.com', 'debug' => false, 'cache' => false],
			'hooks'   => $hooks,
		]);
		$fn($kirby, $tmp);
	} finally {
		annai_remove_tree($tmp);
	}
}

/** The business node of the home page. */
function gastroNode(App $kirby): array
{
	$document = Business::jsonLd($kirby, $kirby->site()->homePage());
	return $document ?? [];
}

/**
 * Restaurant properties (README, Gastronomy): each option on its own, only
 * set values in the node, `extra` never replacing Annai's keys, the hook
 * `sayamaapps.annai.business` for other plugins, and the llms.txt lines.
 */
#[CoversClass(Options::class)]
#[CoversClass(JsonLd::class)]
#[CoversClass(Business::class)]
#[CoversClass(LlmsTxt::class)]
final class GastroTest extends TestCase
{
	public function testAcceptsReservations(): void
	{
		gastroSite(['sayamaapps.annai.acceptsReservations' => true], function (App $kirby): void {
			expect(true, gastroNode($kirby)['acceptsReservations'] ?? null, 'acceptsReservations: true');
		});
		gastroSite(['sayamaapps.annai.acceptsReservations' => false], function (App $kirby): void {
			expect(false, gastroNode($kirby)['acceptsReservations'] ?? null, 'acceptsReservations: false is a fact and stays');
		});
		gastroSite(['sayamaapps.annai.acceptsReservations' => 'https://example.com/tisch'], function (App $kirby): void {
			expect('https://example.com/tisch', gastroNode($kirby)['acceptsReservations'] ?? null, 'acceptsReservations: a booking URL');
		});
		gastroSite(['sayamaapps.annai.acceptsReservations' => 'ja'], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				holds(!array_key_exists('acceptsReservations', gastroNode($kirby)), 'acceptsReservations: text is left out');
			});
			holds(str_contains($log, 'acceptsReservations must be'), 'acceptsReservations: text is logged');
		});
	}

	public function testExtraNeverReplacesAnnaisKeys(): void
	{
		$options = [
			'sayamaapps.annai.priceRange' => '€€',
			'sayamaapps.annai.extra'      => [
				'paymentAccepted' => 'Cash, Credit Card',
				'name'            => 'Anderer Name',
				'@id'             => 'https://example.com/#anders',
				'@type'           => 'Thing',
				'@context'        => 'https://example.org',
				'priceRange'      => '€€€€',
				'empty'           => '',
			],
		];
		gastroSite($options, function (App $kirby): void {
			$node = gastroNode($kirby);
			expect('Cash, Credit Card', $node['paymentAccepted'] ?? null, 'extra: a new property is added');
			expect('Gasthaus Muster', $node['name'] ?? null, 'extra: name stays');
			expect('https://example.com/#business', $node['@id'] ?? null, 'extra: @id stays');
			expect('LocalBusiness', $node['@type'] ?? null, 'extra: @type stays');
			expect('https://schema.org', $node['@context'] ?? null, 'extra: @context stays');
			expect('€€', $node['priceRange'] ?? null, 'extra: a gastronomy option wins');
			holds(!array_key_exists('empty', $node), 'extra: empty values are pruned');
		});
		gastroSite(['sayamaapps.annai.extra' => fn () => ['servesCuisine' => 'Pizza']], function (App $kirby): void {
			expect('Pizza', gastroNode($kirby)['servesCuisine'] ?? null, 'extra: a Closure, and a property no option sets');
		});
		gastroSite(['sayamaapps.annai.extra' => ['a', 'b']], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				expect([], Business::facts($kirby)['extra'], 'extra: a list is ignored');
			});
			holds(str_contains($log, 'extra must be a map'), 'extra: a list is logged');
		});
	}

	public function testHasMap(): void
	{
		gastroSite(['sayamaapps.annai.hasMap' => 'https://maps.example.com/?q=Gasthaus+Muster'], function (App $kirby): void {
			expect('https://maps.example.com/?q=Gasthaus+Muster', gastroNode($kirby)['hasMap'] ?? null, 'hasMap: the URL');
		});
		gastroSite(['sayamaapps.annai.hasMap' => 'maps.example.com'], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				holds(!array_key_exists('hasMap', gastroNode($kirby)), 'hasMap: no scheme is left out');
			});
			holds(str_contains($log, 'hasMap must be an http(s) URL'), 'hasMap: no scheme is logged');
		});
	}

	public function testHasMenu(): void
	{
		gastroSite(['sayamaapps.annai.hasMenu' => 'https://example.com/speisekarte'], function (App $kirby): void {
			expect('https://example.com/speisekarte', gastroNode($kirby)['hasMenu'] ?? null, 'hasMenu: a URL');
		});
		$menu = ['@type' => 'Menu', 'name' => 'Speisekarte', 'hasMenuSection' => []];
		gastroSite(['sayamaapps.annai.hasMenu' => $menu], function (App $kirby): void {
			expect(['@type' => 'Menu', 'name' => 'Speisekarte'], gastroNode($kirby)['hasMenu'] ?? null, 'hasMenu: an object, pruned');
		});
		gastroSite(['sayamaapps.annai.hasMenu' => fn () => 'https://example.com/karte'], function (App $kirby): void {
			expect('https://example.com/karte', gastroNode($kirby)['hasMenu'] ?? null, 'hasMenu: a Closure');
		});
		gastroSite(['sayamaapps.annai.hasMenu' => fn () => null], function (App $kirby): void {
			holds(!array_key_exists('hasMenu', gastroNode($kirby)), 'hasMenu: a Closure returning null adds nothing');
		});
		gastroSite(['sayamaapps.annai.hasMenu' => ['a', 'b']], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				holds(!array_key_exists('hasMenu', gastroNode($kirby)), 'hasMenu: a list is left out');
			});
			holds(str_contains($log, 'hasMenu must be'), 'hasMenu: a list is logged');
		});
	}

	public function testHookContributes(): void
	{
		$hooks = [
			Business::HOOK => [
				fn (array $fields, array $facts): array => $fields + ['hasMenu' => ['@type' => 'Menu', 'name' => 'Tageskarte ' . $facts['name']]],
				fn (array $fields): array => $fields + ['paymentAccepted' => 'Cash', 'name' => 'Überschrieben'],
			],
		];
		gastroSite([], function (App $kirby): void {
			$node = gastroNode($kirby);
			expect(['@type' => 'Menu', 'name' => 'Tageskarte Gasthaus Muster'], $node['hasMenu'] ?? null, 'hook: hasMenu contributed, with the facts');
			expect('Cash', $node['paymentAccepted'] ?? null, 'hook: a second handler adds to the first');
			expect('Gasthaus Muster', $node['name'] ?? null, 'hook: never replaces Annai\'s keys');
		}, $hooks);
		gastroSite(['sayamaapps.annai.hasMenu' => 'https://example.com/speisekarte', 'sayamaapps.annai.extra' => ['paymentAccepted' => 'Card']], function (App $kirby): void {
			$node = gastroNode($kirby);
			expect('https://example.com/speisekarte', $node['hasMenu'] ?? null, 'hook: the hasMenu option wins');
			expect('Card', $node['paymentAccepted'] ?? null, 'hook: the extra option wins');
		}, $hooks);
		gastroSite([], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				expect('Gasthaus Muster', gastroNode($kirby)['name'] ?? null, 'hook: a throwing handler leaves the node');
			});
			holds(str_contains($log, 'sayamaapps.annai.business hook threw'), 'hook: a throwing handler is logged');
		}, [Business::HOOK => function (): array {
			throw new RuntimeException('kaputt');
		}]);
		gastroSite([], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				expect([], Business::facts($kirby)['extra'], 'hook: a string result is ignored');
			});
			holds(str_contains($log, 'must return a map'), 'hook: a string result is logged');
		}, [Business::HOOK => fn (): string => 'x']);
	}

	public function testLlmsLines(): void
	{
		$options = ['sayamaapps.annai.language' => 'de', 'sayamaapps.annai.servesCuisine' => ['Italienisch', 'Pizza'], 'sayamaapps.annai.priceRange' => '€€'];
		gastroSite($options, function (App $kirby): void {
			$text = Routes::llmsText($kirby);
			holds(str_contains($text, "- Küche: Italienisch, Pizza\n- Preisklasse: €€"), 'llms.txt: cuisine and price range lines');
		});
		gastroSite(['sayamaapps.annai.language' => 'de'], function (App $kirby): void {
			$text = Routes::llmsText($kirby);
			holds(!str_contains($text, 'Küche') && !str_contains($text, 'Preisklasse'), 'llms.txt: no lines when unset');
		});
		I18n::setLanguage('en');
		I18n::setLabels([]);
		$text = LlmsTxt::render(['title' => 'Gasthaus Muster', 'contact' => ['cuisine' => 'Italian', 'priceRange' => '€€']]);
		holds(str_contains($text, "- Cuisine: Italian\n- Price range: €€"), 'llms.txt: English labels');
	}

	public function testNothingSetAddsNothing(): void
	{
		gastroSite([], function (App $kirby): void {
			$node = gastroNode($kirby);
			expect([], Business::facts($kirby)['gastro'], 'facts: gastro [] without options');
			expect([], Business::facts($kirby)['extra'], 'facts: extra [] without options and hooks');
			foreach (['servesCuisine', 'priceRange', 'acceptsReservations', 'hasMap', 'hasMenu'] as $key) {
				holds(!array_key_exists($key, $node), 'node: no ' . $key . ' when unset');
			}
		});
	}

	public function testPriceRange(): void
	{
		gastroSite(['sayamaapps.annai.priceRange' => '€€'], function (App $kirby): void {
			expect('€€', gastroNode($kirby)['priceRange'] ?? null, 'priceRange: as set');
		});
		gastroSite(['sayamaapps.annai.priceRange' => '  '], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				holds(!array_key_exists('priceRange', gastroNode($kirby)), 'priceRange: blank is left out');
			});
			holds(str_contains($log, 'priceRange must be'), 'priceRange: blank is logged');
		});
	}

	public function testServesCuisine(): void
	{
		gastroSite(['sayamaapps.annai.servesCuisine' => ' Italienisch '], function (App $kirby): void {
			expect('Italienisch', gastroNode($kirby)['servesCuisine'] ?? null, 'servesCuisine: a string, trimmed');
		});
		gastroSite(['sayamaapps.annai.servesCuisine' => ['Italienisch', 'Pizza', '', 'Pizza']], function (App $kirby): void {
			expect(['Italienisch', 'Pizza'], gastroNode($kirby)['servesCuisine'] ?? null, 'servesCuisine: a list, empty and duplicate entries dropped');
		});
		gastroSite(['sayamaapps.annai.servesCuisine' => ['Italienisch']], function (App $kirby): void {
			expect('Italienisch', gastroNode($kirby)['servesCuisine'] ?? null, 'servesCuisine: a list of one becomes a string');
		});
		gastroSite(['sayamaapps.annai.servesCuisine' => 42], function (App $kirby, string $tmp): void {
			$log = annai_with_error_log($tmp, function () use ($kirby): void {
				holds(!array_key_exists('servesCuisine', gastroNode($kirby)), 'servesCuisine: a number is left out');
			});
			holds(str_contains($log, 'servesCuisine must be a string'), 'servesCuisine: a number is logged');
		});
	}
}
