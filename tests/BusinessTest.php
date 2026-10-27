<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\Text;

/** Default site.txt fields of the fixture. */
function bizSiteDefaults(): array
{
	return [
		'title'       => 'Testhof',
		'marke'       => 'Testhof',
		'zusatz'      => 'Bio-Hofladen',
		'beschreibung' => "Bio-Hofladen\nmit Café </script><b>x</b>",
		'instagram'   => 'https://www.instagram.com/testhof',
	];
}

/** Default fields of the betrieb page (folder 1_kontakt). */
function bizContactDefaults(): array
{
	return [
		'title'   => 'Kontakt',
		'strasse' => 'Musterweg 1',
		'plz'     => '12345',
		'ort'     => 'Musterstadt',
		'telefon' => '030 / 34567000',
		'email'   => 'info@testhof.example',
	];
}

/** Noren fields of the betrieb page: two areas, a Saturday note, a closure, a shortened day, a range. */
function bizNorenFields(): array
{
	$year = (int)date('Y') + 1;
	return [
		'bereiche' => [
			['schluessel' => 'laden', 'name' => 'Hofladen'],
			['schluessel' => 'cafe', 'name' => 'Hofcafé'],
		],
		'zeiten' => [
			['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00', 'von2' => '14:00', 'bis2' => '18:00'],
			['bereich' => 'laden', 'tag' => 'Samstag', 'von1' => '08:00', 'bis1' => '12:00', 'hinweis' => 'nur Verkauf'],
			['bereich' => 'cafe', 'tag' => 'Sonntag', 'von1' => '13:00', 'bis1' => '17:00'],
		],
		'schliessungen' => [
			['anlass' => 'Silvester', 'von' => $year . '-12-31', 'bis' => $year . '-12-31'],
			['anlass' => 'Heiligabend', 'von' => $year . '-12-24', 'bis' => $year . '-12-24', 'oeffnet' => '08:30', 'schliesst' => '12:00'],
			['von' => $year . '-08-02', 'bis' => $year . '-08-15'],
		],
	];
}

/**
 * Writes the fixture: site.txt, the laden page (einleitung), a draft betrieb page
 * (in _drafts/) and the betrieb page under $setup['contactDir'].
 *   site:       site.txt fields, merged over bizSiteDefaults()
 *   contact:    betrieb page fields, merged over bizContactDefaults(); false = no betrieb page
 *   contactDir: folder of the betrieb page, default 1_kontakt
 *   noren:      true = add the Noren fields to the betrieb page
 *   intro:      einleitung of the laden page, default 'Gemüse vom eigenen Feld.'
 */
function bizFixture(string $tmp, array $setup): void
{
	annai_page($tmp, '', 'site', array_merge(bizSiteDefaults(), $setup['site'] ?? []));
	annai_page($tmp, '2_laden', 'default', array_merge(['title' => 'Hofladen', 'einleitung' => $setup['intro'] ?? 'Gemüse vom eigenen Feld.'], $setup['page'] ?? []));
	annai_page($tmp, '_drafts/entwurf', 'betrieb', ['title' => 'Entwurf', 'strasse' => 'Entwurfsweg 9', 'plz' => '00000', 'ort' => 'Nirgendwo']);
	if (($setup['contact'] ?? []) === false) {
		return;
	}
	$contact = array_merge(bizContactDefaults(), $setup['contact'] ?? []);
	if (!empty($setup['noren'])) {
		$contact = array_merge($contact, bizNorenFields());
	}
	annai_page($tmp, $setup['contactDir'] ?? '1_kontakt', 'betrieb', $contact);
}

/**
 * Runs $fn with a Kirby app on a fresh fixture site, removes it afterwards.
 * $setup: see bizFixture(), plus `options` (Kirby options, flat keys) and
 * `noren` (also links kirby-noren when it is installed).
 */
function bizSite(array $setup, callable $fn): void
{
	$tmp = annai_site(!empty($setup['noren']) ? ['noren'] : []);
	try {
		bizFixture($tmp, $setup);
		$kirby = annai_kirby($tmp, $setup['options'] ?? []);
		$fn($kirby);
	} finally {
		annai_remove_tree($tmp);
	}
}

/** The options that switch Annai on. */
function bizOn(array $extra = []): array
{
	return ['annai.active' => true] + $extra;
}

/** First item of a list whose $key equals $value, or null. */
function bizFind(array $items, string $key, mixed $value): array|null
{
	foreach ($items as $item) {
		if (is_array($item) && ($item[$key] ?? null) === $value) {
			return $item;
		}
	}
	return null;
}

/**
 * Business facts, the JSON-LD document and the head input with real Kirby
 * (docs/SPEC.md §9.8), in throwaway site directories:
 *  1. off: facts/jsonLd/head/units return [] / null;
 *  2. location(): by template, not by slug; annai.location and Closure win; drafts never;
 *  3. field(): location page first, site fallback, '' when both are empty;
 *  4. facts(): name and its fallback, phone, sameAs, geo and action legacy keys, type, image;
 *  5. jsonLd(): home only, hooks (@graph, [] → null, type false);
 *  6. head(): title rules, description source and excerpt, closures, image forms, locale, twitter card;
 *  7. Noren hours (only when kirby-noren is found, else SKIP lines): units, primary, days, closures, schema;
 *  8. without Noren: units [] even with noren.active set.
 *
 * Kirby loads the plugin through the symlink annai_site() creates, so no src
 * file is required here.
 *
 *   ASDF_PHP_VERSION=8.5.3 vendor/bin/phpunit tests/BusinessTest.php
 */
#[CoversClass(Business::class)]
final class BusinessTest extends TestCase
{
	public function testSuite(): void
	{
		$year = (int)date('Y') + 1;
		$norenPath = annai_sibling('noren');
		/* ---------------------------------------------------------------------------
		 * 1. Off
		 * ------------------------------------------------------------------------- */
		bizSite([], function (App $kirby): void {
			$home = $kirby->site()->homePage();
			expect([], Business::facts($kirby), 'off: facts() is []');
			expect(null, Business::jsonLd($kirby, $home), 'off: jsonLd() is null');
			expect([], Business::head($kirby, $home), 'off: head() is []');
			expect([], Business::units($kirby), 'off: units() is []');
		});
		/* ---------------------------------------------------------------------------
		 * 2. location()
		 * ------------------------------------------------------------------------- */
		bizSite(['options' => bizOn()], function (App $kirby): void {
			$location = Business::location($kirby);
			expect('kontakt', $location?->id(), 'location(): the betrieb page by template');
		});
		bizSite(['options' => bizOn(), 'contactDir' => '1_anfahrt'], function (App $kirby): void {
			expect('anfahrt', Business::location($kirby)?->id(), 'location(): found by template, not by slug (folder 1_anfahrt)');
		});
		bizSite(['options' => bizOn(['annai.location' => 'laden'])], function (App $kirby): void {
			expect('laden', Business::location($kirby)?->id(), 'location(): annai.location id wins over the template');
		});
		bizSite(['options' => bizOn(['annai.location' => fn () => App::instance()->page('laden')])], function (App $kirby): void {
			expect('laden', Business::location($kirby)?->id(), 'location(): annai.location Closure wins');
		});
		bizSite(['options' => bizOn(['annai.location' => fn () => App::instance()->page('entwurf')])], function (App $kirby): void {
			expect(null, Business::location($kirby), 'location(): a draft returned by the Closure is never used');
		});
		bizSite(['options' => bizOn(['annai.location' => 'entwurf'])], function (App $kirby): void {
			expect(null, Business::location($kirby), 'location(): a draft named by id is never used');
		});
		bizSite(['options' => bizOn(), 'contact' => false], function (App $kirby): void {
			expect(null, Business::location($kirby), 'location(): the draft betrieb page is not found by template, no page → null');
		});
		// The template search reads the top two levels before the whole index: building
		// $site->index() reads every page folder, on every page view (seven to ten times
		// the time of a page view on a site with 2,000 posts). Kirby caches the index in
		// the protected `index` property of the top-level Pages collection; it stays
		// null when the index was never built.
		$indexBuilt = static fn (App $kirby): bool => (new ReflectionProperty(Kirby\Cms\Pages::class, 'index'))->getValue($kirby->site()->children()) !== null;
		/** 50 nested pages below 3_tief: tief/ebene-1/…/ebene-50. Returns the id of the deepest one. */
		$nest = static function (string $tmp, int $depth = 50): string {
			$dir = '3_tief';
			for ($level = 1; $level <= $depth; $level++) {
				$dir .= '/1_ebene-' . $level;
			}
			annai_page($tmp, $dir, 'default', ['title' => 'Ebene ' . $depth]);
			return 'tief/' . implode('/', array_map(static fn (int $level): string => 'ebene-' . $level, range(1, $depth)));
		}
		;
		$tmp = annai_site();
		try {
			bizFixture($tmp, []);
			$leafId = $nest($tmp);
			$kirby = annai_kirby($tmp, bizOn());
			$leaf = $kirby->page($leafId);
			holds($leaf instanceof Page, 'location(): the 50th nested page exists');
			$head = $leaf instanceof Page ? Business::head($kirby, $leaf) : [];
			expect('Ebene 50 · Testhof', $head['title'] ?? null, 'location(): head() of the 50th nested page');
			expect('kontakt', Business::location($kirby)?->id(), 'location(): the top-level betrieb page next to 50 nested pages');
			expect(false, $indexBuilt($kirby), 'location(): a top-level betrieb page is found without building $site->index()');
			expect(false, Business::searchesIndex($kirby), 'searchesIndex(): false when the top level holds the business page');
		} finally {
			annai_remove_tree($tmp);
		}
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['contactDir' => '3_ueber-uns/1_kontakt']);
			$nest($tmp);
			$kirby = annai_kirby($tmp, bizOn());
			expect('ueber-uns/kontakt', Business::location($kirby)?->id(), 'location(): a betrieb page on the second level');
			expect(false, $indexBuilt($kirby), 'location(): the second level is searched without building $site->index()');
		} finally {
			annai_remove_tree($tmp);
		}
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['contactDir' => '3_tief/1_ebene-1/1_kontakt']);
			$nest($tmp, 3);
			$kirby = annai_kirby($tmp, bizOn());
			expect('tief/ebene-1/kontakt', Business::location($kirby)?->id(), 'location(): a betrieb page on the third level is found through $site->index()');
			expect(true, Business::searchesIndex($kirby), 'searchesIndex(): true when the business page is below the second level');
			expect(false, Business::searchesIndex(annai_kirby($tmp, bizOn(['annai.location' => 'tief/ebene-1/kontakt']))), 'searchesIndex(): false with annai.location');
		} finally {
			annai_remove_tree($tmp);
		}
		// Top level first: a betrieb page on the top level wins over one on the second
		// level, even where the second-level page comes first in the index.
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['contactDir' => '1_a/1_kontakt']);
			annai_page($tmp, '4_hof', 'betrieb', ['title' => 'Hof', 'strasse' => 'Musterweg 2']);
			$kirby = annai_kirby($tmp, bizOn());
			expect('hof', Business::location($kirby)?->id(), 'location(): the top level is searched before the second level');
		} finally {
			annai_remove_tree($tmp);
		}
		// Once per request: without a business page the index is searched once, and
		// the next location() call does not search again.
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['contact' => false]);
			$kirby = annai_kirby($tmp, bizOn());
			expect(null, Business::location($kirby), 'location(): no betrieb page anywhere');
			expect(true, Business::searchesIndex($kirby), 'searchesIndex(): true without any business page');
			$cache = new ReflectionProperty(Kirby\Cms\Pages::class, 'index');
			$cache->setValue($kirby->site()->children(), null);
			Business::location($kirby);
			Business::facts($kirby);
			expect(false, $indexBuilt($kirby), 'location(): the template search runs once per request (memoised per site object)');
			$other = annai_kirby($tmp, bizOn());
			Business::location($other);
			expect(true, $indexBuilt($other), 'location(): a new Kirby instance searches again');
		} finally {
			annai_remove_tree($tmp);
		}
		// head() reads the facts once (they reach jsonLd() as an argument): the sameAs
		// Closure, called once per facts(), runs once.
		$calls = 0;
		bizSite(['options' => bizOn(['annai.sameAs' => function () use (&$calls): array {
			$calls++;
			return [];
		}])], function (App $kirby) use (&$calls): void {
			$calls = 0;
			Business::head($kirby, $kirby->site()->homePage());
			expect(1, $calls, 'head(): facts() once per call, passed on to jsonLd()');
		});
		/* ---------------------------------------------------------------------------
		 * 3. field()
		 * ------------------------------------------------------------------------- */
		bizSite(['options' => bizOn()], function (App $kirby): void {
			expect('Musterweg 1', Business::field($kirby, 'street'), 'field(street) from the location page');
			expect('Testhof', Business::field($kirby, 'brand'), 'field(brand) from the site');
		});
		bizSite(
			['options' => bizOn(), 'contact' => ['email' => ''], 'site' => ['email' => 'site@testhof.example']],
			function (App $kirby): void {
				expect('site@testhof.example', Business::field($kirby, 'email'), 'field(email) falls back to the site field');
			}
		);
		bizSite(
			['options' => bizOn(), 'contact' => ['strasse' => ''], 'site' => ['strasse' => 'Altweg 3']],
			function (App $kirby): void {
				expect('Altweg 3', Business::field($kirby, 'street'), 'field(street) falls back to the site (a site keeps its address in site.txt)');
			}
		);
		bizSite(['options' => bizOn()], function (App $kirby): void {
			expect('', Business::field($kirby, 'fax'), 'field(fax): both empty gives ""');
		});
		/* ---------------------------------------------------------------------------
		 * 4. facts()
		 * ------------------------------------------------------------------------- */
		bizSite(['options' => bizOn()], function (App $kirby): void {
			$facts = Business::facts($kirby);
			expect('Testhof', $facts['name'], 'facts: name from marke');
			expect('Bio-Hofladen', $facts['tagline'], 'facts: tagline from zusatz');
			expect('030 / 34567000', $facts['phone'], 'facts: phone display string');
			expect('+493034567000', $facts['phoneE164'], 'facts: phoneE164 via Text::phone()');
			expect('info@testhof.example', $facts['email'], 'facts: email');
			expect('Musterweg 1', $facts['street'], 'facts: street');
			expect('12345', $facts['postalCode'], 'facts: postalCode');
			expect('Musterstadt', $facts['city'], 'facts: city');
			expect('DE', $facts['country'], 'facts: country default DE');
			expect('http://example.invalid', $facts['url'], 'facts: url from $site->url()');
			expect('LocalBusiness', $facts['type'], 'facts: type default LocalBusiness');
			expect('business', $facts['entityId'], 'facts: entityId default business');
			expect(null, $facts['image'], 'facts: image null without annai.image');
			expect(null, $facts['geo'], 'facts: geo null without annai.geo');
			expect(null, $facts['action'], 'facts: action null without annai.action');
			expect([], $facts['units'], 'facts: units [] without Noren');
			expect(
				['name', 'tagline', 'description', 'url', 'image', 'phone', 'phoneE164', 'fax', 'faxE164', 'email',
					'street', 'postalCode', 'city', 'country', 'geo', 'sameAs', 'action', 'type', 'entityId', 'units'],
				array_keys($facts),
				'facts: keys of §3.8'
			);
			// The description is rendered with kirbytext() and reduced to plain text, like the
			// meta description, so no tag, Markdown or Kirbytag reaches the JSON-LD or the llms.txt summary.
			expect('Bio-Hofladen mit Café x', $facts['description'], 'facts: description is the site field as plain text (tags gone, newline collapsed, not excerpted)');
		});
		bizSite(
			['options' => bizOn(), 'site' => ['beschreibung' => "**Bio**-Hofladen mit Café.\n\nBestellen: (link: https://shop.example text: Online-Shop)"]],
			function (App $kirby): void {
				$facts = Business::facts($kirby);
				expect('Bio-Hofladen mit Café. Bestellen: Online-Shop', $facts['description'], 'facts: Markdown and Kirbytags in beschreibung become plain text');
				expect('Bio-Hofladen mit Café. Bestellen: Online-Shop', Business::jsonLd($kirby, $kirby->site()->homePage())['description'] ?? null, 'jsonLd: description is that plain text');
			}
		);
		bizSite(
			['options' => bizOn(), 'site' => ['beschreibung' => trim(str_repeat('Gemüse vom Hof, Eier und Brot. ', 10)) . ' Kinder &lt;6 Jahre frei.']],
			function (App $kirby): void {
				$facts = Business::facts($kirby);
				expect(trim(str_repeat('Gemüse vom Hof, Eier und Brot. ', 10)) . ' Kinder <6 Jahre frei.', $facts['description'], 'facts: description is not shortened and keeps a decoded "<6" (strip_tags on the plain text would eat it)');
				holds(mb_strlen(Business::head($kirby, $kirby->site()->homePage())['description']) <= 155, 'head: the meta description of the same text is excerpted');
			}
		);
		bizSite(['options' => bizOn(), 'site' => ['marke' => '', 'title' => 'Hofwelt']], function (App $kirby): void {
			expect('Hofwelt', Business::facts($kirby)['name'], 'facts: name falls back to $site->title() when marke is empty');
		});
		bizSite(
			['options' => bizOn(), 'contact' => ['marke' => 'Falsch', 'zusatz' => 'Falsch', 'beschreibung' => 'Falsch']],
			function (App $kirby): void {
				$facts = Business::facts($kirby);
				expect('Testhof', $facts['name'], 'facts: brand is read from the site even when the location page has the field');
				expect('Bio-Hofladen', $facts['tagline'], 'facts: tagline is read from the site even when the location page has the field');
				holds(str_starts_with($facts['description'], 'Bio-Hofladen'), 'facts: description is read from the site even when the location page has the field');
			}
		);
		bizSite(
			['options' => bizOn(['annai.sameAs' => ['https://example.org/profil', '', 'https://www.instagram.com/testhof']])],
			function (App $kirby): void {
				expect(
					['https://example.org/profil', 'https://www.instagram.com/testhof'],
					Business::facts($kirby)['sameAs'],
					'facts: sameAs = option + instagram field, empty and duplicate dropped'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.sameAs' => fn () => ['https://example.org/closure']])],
			function (App $kirby): void {
				expect(
					['https://example.org/closure', 'https://www.instagram.com/testhof'],
					Business::facts($kirby)['sameAs'],
					'facts: sameAs Closure is read too'
				);
			}
		);
		bizSite(['options' => bizOn(['annai.geo' => ['breite' => 52.52, 'laenge' => 13.41]])], function (App $kirby): void {
			expect(['lat' => 52.52, 'lng' => 13.41], Business::facts($kirby)['geo'], 'facts: geo legacy breite/laenge');
		});
		bizSite(['options' => bizOn(['annai.geo' => [52.52, 13.41]])], function (App $kirby): void {
			expect(['lat' => 52.52, 'lng' => 13.41], Business::facts($kirby)['geo'], 'facts: geo as [lat, lng]');
		});
		bizSite(
			['options' => bizOn(['annai.action' => ['typ' => 'OrderAction', 'name' => 'Online bestellen', 'ziel' => 'https://shop.example/testhof']])],
			function (App $kirby): void {
				expect(
					['type' => 'OrderAction', 'name' => 'Online bestellen', 'target' => 'https://shop.example/testhof'],
					Business::facts($kirby)['action'],
					'facts: action legacy typ/ziel'
				);
			}
		);
		// annai.action als Closure: gelesen wie ein Wert; ein werfender Closure zählt als nicht gesetzt
		bizSite(
			['options' => bizOn(['annai.action' => fn () => ['type' => 'ReserveAction', 'name' => 'Termin buchen', 'target' => 'https://booking.example/praxis']])],
			function (App $kirby): void {
				expect(
					['type' => 'ReserveAction', 'name' => 'Termin buchen', 'target' => 'https://booking.example/praxis'],
					Business::facts($kirby)['action'],
					'facts: action from a Closure'
				);
			}
		);
		bizSite(['options' => bizOn(['annai.action' => fn () => throw new RuntimeException('kaputt')])], function (App $kirby): void {
			expect(null, Business::facts($kirby)['action'], 'facts: a throwing action Closure counts as unset');
		});
		bizSite(['options' => bizOn(['annai.type' => false])], function (App $kirby): void {
			expect(false, Business::facts($kirby)['type'], 'facts: type false is kept');
		});
		bizSite(['options' => bizOn(['annai.type' => 'GroceryStore', 'annai.entityId' => 'betrieb'])], function (App $kirby): void {
			$facts = Business::facts($kirby);
			expect('GroceryStore', $facts['type'], 'facts: type option');
			expect('betrieb', $facts['entityId'], 'facts: entityId option');
		});
		bizSite(['options' => bizOn(['annai.image' => 'assets/teilen.jpg'])], function (App $kirby): void {
			expect('http://example.invalid/assets/teilen.jpg', Business::facts($kirby)['image'], 'facts: image is the home page image, absolute');
		});
		// `annai.phoneCountry` written with the international prefix ("0043") used to make
		// "+00431234"; the leading zeros are no part of the code. A field that
		// holds only a country code publishes nothing, also a foreign one.
		bizSite(['options' => bizOn(['annai.phoneCountry' => '0043']), 'contact' => ['telefon' => '01 234 56', 'fax' => '+43']], function (App $kirby): void {
			$facts = Business::facts($kirby);
			expect('43', Sayamaapps\Annai\Options::phoneCountry($kirby), "phoneCountry '0043' is read as 43");
			expect('+43123456', $facts['phoneE164'], "phoneCountry '0043': a national number gets +43, not +0043");
			expect('', $facts['faxE164'], 'facts: a fax field holding only "+43" publishes nothing');
		});
		bizSite(['options' => bizOn(['annai.phoneCountry' => '+43 ']), 'contact' => ['telefon' => '01 234 56']], function (App $kirby): void {
			expect('+43123456', Business::facts($kirby)['phoneE164'], "phoneCountry '+43 ': the plus and the space are no part of the code");
		});
		bizSite(['options' => bizOn(), 'contact' => ['telefon' => '+43 (0)1 234 56']], function (App $kirby): void {
			expect('+43123456', Business::facts($kirby)['phoneE164'], 'facts: an Austrian number with (0) on a German-configured site is dialable');
		});
		// An Italian number written nationally: the zero of the area code is dialled
		// from abroad too and stays in the E.164.
		bizSite(['options' => bizOn(['annai.phoneCountry' => '39']), 'contact' => ['telefon' => '0471 123456']], function (App $kirby): void {
			expect('+390471123456', Business::facts($kirby)['phoneE164'], "phoneCountry '39': a national number keeps the zero of its area code");
			[, $html] = annai_request($kirby, '');
			expect('+390471123456', annai_jsonld($html)[0]['telephone'] ?? null, "JSON-LD (phoneCountry '39'): telephone keeps the zero");
		});
		// ESC sequences and other control characters in marke, title and einleitung
		// used to reach <title>, the meta description and og:* raw — invalid HTML, and an escape
		// sequence for whoever curls the page in a terminal. Text::line() removes them.
		bizSite(['options' => bizOn(), 'site' => ['marke' => "Test\e[31mhof", 'zusatz' => "Bio\x01-Hofladen"], 'intro' => "Gem\u{FC}se\x01 vom \e[2JFeld, jeden Samstag frisch im Hofladen, Eier vom Hof."], function (App $kirby): void {
			$facts = Business::facts($kirby);
			expect(['Test[31mhof', 'Bio-Hofladen'], [$facts['name'], $facts['tagline']], 'facts: name and tagline without control characters');
			[, $html] = annai_request($kirby, '');
			holds(str_contains($html, '<title>Test[31mhof · Bio-Hofladen</title>'), 'head: <title> without the ESC of the brand field');
			holds(str_contains($html, '<meta property="og:site_name" content="Test[31mhof">'), 'head: og:site_name without the ESC');
			[, $html] = annai_request($kirby, 'laden');
			holds(str_contains($html, 'content="Gemüse vom [2JFeld, jeden Samstag'), 'head: meta description without the control characters of einleitung');
			holds(preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $html) !== 1, 'head: no C0 control character in the rendered page');
		});
		// The phone field (SPEC §3.2, the input contract): the raw value goes to
		// Text::phone(), the display string through line(). A field that is not empty and gives no
		// number is logged with its reason and is a site problem in the check; the JSON-LD then has
		// no telephone or faxNumber, llms.txt shows the field as written without "(+E.164)".
		$bizPhoneSaid = static fn (string $field, string $value, string $reason): string => 'Annai: field ' . $field . ' ' . match ($reason) {
			'characters' => 'holds more than the number (' . $value . ') — nothing is published as E.164; write only the number: digits, spaces, a leading +, "/", "-" and parentheses. Notes go into another field.',
			'invalid' => 'holds no valid phone number (' . $value . ') — nothing is published as E.164; write the whole number with its area code (a national number counts for +49, annai.phoneCountry) or with its country code (+…).',
		}
		;
		$tmp = annai_site();
		try {
			// Two fields of the golden table as phone and fax: telephone and faxNumber, "(+E.164)" in
			// llms.txt, no log line.
			bizFixture($tmp, ['contact' => ['telefon' => '+49 30 34567040', 'fax' => '030 34567010']]);
			$kirby = annai_kirby($tmp, bizOn());
			$log = annai_with_error_log($tmp, function () use ($kirby, &$facts): void {
				$facts = Business::facts($kirby);
			});
			expect(['+493034567040', '+493034567010'], [$facts['phoneE164'], $facts['faxE164']], 'facts: phone and fax of the golden table as E.164');
			$node = Business::jsonLd($kirby, $kirby->site()->homePage()) ?? [];
			expect(['+493034567040', '+493034567010'], [$node['telephone'] ?? null, $node['faxNumber'] ?? null], 'JSON-LD: telephone and faxNumber');
			[, $llms] = annai_request($kirby, 'llms.txt');
			holds(str_contains($llms, "- Phone: +49 30 34567040 (tel:+493034567040)\n"), 'llms.txt: the phone as written with its E.164 number');
			holds(!str_contains($log, 'Annai: field'), 'facts: a number within the contract logs nothing');

			// Outside the contract or no valid number: no number, logged with field, value and
			// reason, the JSON-LD without telephone. The check gets the fax field: Options::warn()
			// says a message once per process, and the fax message is another one.
			foreach ([
				['Tel. 030 34567000', 'characters'], ['<a href="tel:+493034567000">030 34567000</a>', 'characters'],
				['(tel: 030 34567000)', 'characters'], ["030 34567000\n0171 1234567", 'characters'], ['0351 / 12.12.2010', 'characters'],
				['34567', 'invalid'], ['+49 12345678901234', 'invalid'],
			] as [$field, $reason]) {
				bizFixture($tmp, ['contact' => ['telefon' => $field]]);
				$kirby = annai_kirby($tmp, bizOn());
				$log = annai_with_error_log($tmp, function () use ($kirby, &$facts): void {
					$facts = Business::facts($kirby);
				});
				$value = json_encode($field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				expect(
					['', null],
					[$facts['phoneE164'], (Business::jsonLd($kirby, $kirby->site()->homePage()) ?? [])['telephone'] ?? null],
					'facts and JSON-LD: ' . $value . ' publishes no telephone'
				);
				holds(str_contains($log, $bizPhoneSaid('telefon', $value, $reason)), 'facts: ' . $value . ' is logged as ' . $reason);
				bizFixture($tmp, ['contact' => ['fax' => $field]]);
				$kirby = annai_kirby($tmp, bizOn());
				holds(
					in_array('configuration: ' . substr($bizPhoneSaid('fax', $value, $reason), strlen('Annai: ')), Sayamaapps\Annai\Check::site($kirby)['problems'], true),
					'check: ' . $value . ' in the fax field is a site problem'
				);
				expect(
					['', null],
					[Business::facts($kirby)['faxE164'], (Business::jsonLd($kirby, $kirby->site()->homePage()) ?? [])['faxNumber'] ?? null],
					'facts and JSON-LD: ' . $value . ' as fax publishes no faxNumber'
				);
			}
			// llms.txt keeps the field as written, on one line, without an E.164 number.
			bizFixture($tmp, ['contact' => ['telefon' => "Tel. 030 34567000\n(Zentrale)"]]);
			[, $llms] = annai_request(annai_kirby($tmp, bizOn()), 'llms.txt');
			holds(str_contains($llms, "- Phone: Tel. 030 34567000 (Zentrale)\n"), 'llms.txt: a field outside the contract as written, without "(+E.164)"');
			// An empty field: no number, no log line, no problem (the log file starts empty).
			@unlink($tmp . '/php-error.log');
			bizFixture($tmp, ['contact' => ['telefon' => '']]);
			$kirby = annai_kirby($tmp, bizOn());
			$log = annai_with_error_log($tmp, function () use ($kirby, &$facts): void {
				$facts = Business::facts($kirby);
			});
			expect(['', ''], [$facts['phone'], $facts['phoneE164']], 'facts: an empty phone field gives no number');
			holds(!str_contains($log, 'Annai: field'), 'facts: an empty phone field logs nothing');
			bizFixture($tmp, ['contact' => ['telefon' => '030 34567000']]);
			expect([], Sayamaapps\Annai\Check::site(annai_kirby($tmp, bizOn()))['problems'], 'check: a number within the contract is no problem');
		} finally {
			annai_remove_tree($tmp);
		}
		// Only http(s) URLs reach sameAs. "@testhof" in the instagram field and a
		// URL without a scheme in the option used to go into the JSON-LD as written.
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['site' => ['instagram' => '@testhof', 'facebook' => 'facebook.com/testhof']]);
			$kirby = annai_kirby($tmp, bizOn(['annai.sameAs' => ['https://g.page/testhof', 'g.page/testhof', 'HTTP://Example.org/x', ' https://example.org/y ']]));
			$log = annai_with_error_log($tmp, function () use ($kirby, &$facts, &$document): void {
				$facts = Business::facts($kirby);
				$document = Business::jsonLd($kirby, $kirby->site()->homePage());
			});
			expect(['https://g.page/testhof', 'HTTP://Example.org/x', 'https://example.org/y'], $facts['sameAs'], 'facts: sameAs keeps http(s) URLs only (scheme case-insensitive, trimmed)');
			expect(['https://g.page/testhof', 'HTTP://Example.org/x', 'https://example.org/y'], $document['sameAs'] ?? null, 'jsonLd: sameAs carries no handle and no URL without a scheme');
			holds(str_contains($log, 'sameAs') && str_contains($log, '"@testhof"') && str_contains($log, 'instagram'), 'facts: the instagram handle is logged with its field');
			holds(str_contains($log, '"facebook.com/testhof"') && str_contains($log, 'facebook'), 'facts: the facebook value without a scheme is logged with its field');
			holds(str_contains($log, '"g.page/testhof"') && str_contains($log, 'annai.sameAs'), 'facts: the option entry without a scheme is logged with the option name');
		} finally {
			annai_remove_tree($tmp);
		}
		// A page below a draft is unpublished like the draft itself (Kirby's
		// isDraft() is false for it): it is no business page either.
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['contact' => false]);
			annai_page($tmp, '_drafts/entwurf/filiale', 'betrieb', ['title' => 'Filiale', 'strasse' => 'Nebenweg 2']);
			$kirby = annai_kirby($tmp, bizOn(['annai.location' => 'entwurf/filiale']));
			holds($kirby->page('entwurf/filiale') instanceof Page && !$kirby->page('entwurf/filiale')->isDraft(), 'fixture: the page below the draft exists and is not a draft for Kirby');
			expect(null, Business::location($kirby)?->id(), 'location(): a page below a draft named by id is never used');
			expect(null, Business::location(annai_kirby($tmp, bizOn(['annai.location' => fn () => App::instance()->page('entwurf/filiale')])))?->id(), 'location(): a page below a draft returned by the Closure is never used');
			expect(null, Business::location(annai_kirby($tmp, bizOn()))?->id(), 'location(): a betrieb page below a draft is not found by template');
		} finally {
			annai_remove_tree($tmp);
		}
		/* ---------------------------------------------------------------------------
		 * 5. jsonLd()
		 * ------------------------------------------------------------------------- */
		bizSite(['options' => bizOn()], function (App $kirby): void {
			$document = Business::jsonLd($kirby, $kirby->site()->homePage());
			holds(is_array($document) && ($document['@type'] ?? null) === 'LocalBusiness', 'jsonLd: home page gives one LocalBusiness node');
			holds(is_array($document) && ($document['@context'] ?? null) === 'https://schema.org', 'jsonLd: document carries @context');
			expect(null, Business::jsonLd($kirby, $kirby->page('laden')), 'jsonLd: a subpage gives null');
		});
		bizSite(
			['options' => bizOn(['annai.jsonld' => function (Page $page, array $nodes, array $facts): array {
				$nodes[] = ['@type' => 'WebSite', 'name' => 'Hook'];
				return $nodes;
			}])],
			function (App $kirby): void {
				$document = Business::jsonLd($kirby, $kirby->site()->homePage());
				holds(is_array($document) && count($document['@graph'] ?? []) === 2, 'jsonLd: a hook node is added to the business node in a @graph');
				expect(
					['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Hook'],
					Business::jsonLd($kirby, $kirby->page('laden')),
					'jsonLd: hook on a subpage gives only the hook node'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.jsonld' => fn (Page $page, array $nodes, array $facts): array => []])],
			function (App $kirby): void {
				expect(null, Business::jsonLd($kirby, $kirby->site()->homePage()), 'jsonLd: hook returning [] gives null');
			}
		);
		bizSite(
			['options' => bizOn(['annai.type' => false, 'annai.jsonld' => fn (Page $page, array $nodes, array $facts): array => [
				['@type' => 'WebSite', 'name' => 'Hook'],
			]])],
			function (App $kirby): void {
				expect(
					['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Hook'],
					Business::jsonLd($kirby, $kirby->site()->homePage()),
					'jsonLd: type false plus a hook gives the hook node alone'
				);
			}
		);
		/* ---------------------------------------------------------------------------
		 * 6. head()
		 * ------------------------------------------------------------------------- */
		bizSite(['options' => bizOn()], function (App $kirby): void {
			$head = Business::head($kirby, $kirby->site()->homePage());
			expect(
				['title', 'description', 'canonical', 'robots', 'ogType', 'siteName', 'locale', 'image', 'twitterCard', 'alternates', 'jsonld'],
				array_keys($head),
				'head: keys of §3.7.1 input'
			);
			expect('Testhof · Bio-Hofladen', $head['title'], 'head: home title = brand · tagline');
			expect('Testhof', $head['siteName'], 'head: siteName = facts name');
			expect('website', $head['ogType'], 'head: ogType website');
			expect('http://example.invalid', $head['canonical'], 'head: home canonical');
			expect([], $head['alternates'], 'head: no alternates without multilang');
			expect(null, $head['image'], 'head: image null without annai.image');
			expect(
				Text::excerpt("Bio-Hofladen\nmit Café </script><b>x</b>", 155),
				$head['description'],
				'head: home description = excerpt of the site description (no intro on the home page)'
			);
			holds(!str_contains($head['description'], '<'), 'head: description has no markup');
		});
		bizSite(['options' => bizOn(), 'site' => ['zusatz' => '']], function (App $kirby): void {
			expect('Testhof', Business::head($kirby, $kirby->site()->homePage())['title'], 'head: home title without tagline');
		});
		bizSite(['options' => bizOn()], function (App $kirby): void {
			$head = Business::head($kirby, $kirby->page('laden'));
			expect('Hofladen · Testhof', $head['title'], 'head: subpage title = title · brand');
			expect('Gemüse vom eigenen Feld.', $head['description'], 'head: description from the page intro (einleitung)');
			expect('http://example.invalid/laden', $head['canonical'], 'head: canonical is the page URL');
			holds(!str_contains($head['canonical'], '?'), 'head: canonical has no query string');
		});
		bizSite(['options' => bizOn(), 'site' => ['marke' => '', 'title' => 'Hofwelt']], function (App $kirby): void {
			expect('Hofwelt · Bio-Hofladen', Business::head($kirby, $kirby->site()->homePage())['title'], 'head: empty brand falls back to the site title');
		});
		bizSite(
			['options' => bizOn(['annai.descriptionLength' => 20]), 'site' => ['beschreibung' => 'aaaa bbbb cccc dddd eeee ffff']],
			function (App $kirby): void {
				$description = Business::head($kirby, $kirby->site()->homePage())['description'];
				holds(mb_strlen($description) <= 20 && str_ends_with($description, '…'), 'head: description is excerpted to descriptionLength (20)');
			}
		);
		// annai.descriptionFallback (off by default): an intro under the check's 50 characters gives way to the
		// site description, if that is longer. The hook and a long intro are not touched.
		$shortIntro = 'Gemüse vom eigenen Feld.';
		$siteLong = 'Bio-Hofladen mit Café, Gemüse vom eigenen Hof und Eier aus Freilandhaltung.';
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => $shortIntro, 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($siteLong): void {
				expect(
					$siteLong,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, short intro → the longer site description'
				);
			}
		);
		bizSite(
			['options' => bizOn(), 'intro' => $shortIntro, 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($shortIntro): void {
				expect(
					$shortIntro,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback off (default) → the short intro, as before'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => 'Gemüse vom eigenen Feld, jeden Samstag frisch geerntet und direkt im Hofladen verkauft.', 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby): void {
				expect(
					'Gemüse vom eigenen Feld, jeden Samstag frisch geerntet und direkt im Hofladen verkauft.',
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, intro of 50 characters or more → kept'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => $shortIntro, 'site' => ['beschreibung' => 'Bio-Hofladen.']],
			function (App $kirby) use ($shortIntro): void {
				expect(
					$shortIntro,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, site text not longer than the intro → the intro is kept'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true, 'annai.description' => fn (Page $page, array $facts): string => 'Hook text.']), 'intro' => $shortIntro, 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby): void {
				expect(
					'Hook text.',
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, the annai.description hook still wins'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($siteLong): void {
				expect(
					$siteLong,
					Business::head($kirby, $kirby->site()->homePage())['description'],
					'head: descriptionFallback on, home page (no intro) → the site description, as before'
				);
			}
		);
		// The chain with descriptionFallback: intro, then the page's beschreibung, then the site's.
		// Each step gives way only while it is under 50 characters and the next one is longer.
		$pageLong = 'Hofladen mit Gemüse, Eiern und Brot aus eigener Herstellung, jeden Tag frisch.';
		$pageShort = 'Hofladen mit Eiern.';
		$pageMiddle = 'Hofladen mit Eiern und Gemüse vom Hof.';
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => $shortIntro, 'page' => ['beschreibung' => $pageLong], 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($pageLong): void {
				expect(
					$pageLong,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, short intro → the page beschreibung before the longer site description'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => '', 'page' => ['beschreibung' => $pageShort], 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($siteLong): void {
				expect(
					$siteLong,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, empty intro, short page beschreibung → the longer site description'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => '', 'page' => ['beschreibung' => $pageLong], 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($pageLong): void {
				expect(
					$pageLong,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, empty intro, page beschreibung of 50 characters or more → it, not the site'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.descriptionFallback' => true]), 'intro' => $shortIntro, 'page' => ['beschreibung' => $pageMiddle], 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($siteLong): void {
				expect(
					$siteLong,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback on, the minimum applies to the page step too: short page beschreibung → the longer site description'
				);
			}
		);
		bizSite(
			['options' => bizOn(), 'intro' => '', 'page' => ['beschreibung' => $pageLong], 'site' => ['beschreibung' => $siteLong]],
			function (App $kirby) use ($siteLong): void {
				expect(
					$siteLong,
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: descriptionFallback off (default) → the page beschreibung is ignored, the site description as before'
				);
			}
		);
		// Markdown and Kirbytags are rendered first.
		bizSite(
			[
				'options' => bizOn(),
				'intro'   => 'Gemüse **vom** eigenen Feld, siehe [Karte](https://x.test) und (link: kontakt text: Kontakt).',
				'site'    => ['beschreibung' => 'Wir sind *Bio* & regional, siehe (link: laden text: Hofladen).'],
			],
			function (App $kirby): void {
				expect(
					'Gemüse vom eigenen Feld, siehe Karte und Kontakt.',
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: Markdown and Kirbytags in einleitung become plain text'
				);
				expect(
					'Wir sind Bio & regional, siehe Hofladen.',
					Business::head($kirby, $kirby->site()->homePage())['description'],
					'head: Markdown and Kirbytags in the site beschreibung become plain text'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.description' => fn (Page $page, array $facts): string => 'Hook text.'])],
			function (App $kirby): void {
				expect('Hook text.', Business::head($kirby, $kirby->page('laden'))['description'], 'head: annai.description Closure replaces the source');
			}
		);
		// The hook delivers plain text, not HTML. Text::excerpt() strips tags, and
		// strip_tags() used to eat everything from a literal "<6" on: "Kinder <6 Jahre …"
		// became "Kinder". What the hook writes is what the description says: "&amp;"
		// stays "&amp;" (it is not rendered, so it is not decoded either).
		bizSite(
			['options' => bizOn(['annai.description' => fn (Page $page, array $facts): string => 'Tom & Jerry: Kinder <6 Jahre essen gratis, Eltern zahlen <b>den</b> normalen Preis. Ein &amp; bleibt.'])],
			function (App $kirby): void {
				expect(
					'Tom & Jerry: Kinder <6 Jahre essen gratis, Eltern zahlen <b>den</b> normalen Preis. Ein &amp; bleibt.',
					Business::head($kirby, $kirby->page('laden'))['description'],
					'head: annai.description text is plain text: "<6", "<b>" and "&amp;" stay as written'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.description' => fn (Page $page, array $facts): string => str_repeat('Kinder <6 Jahre frei. ', 20)])],
			function (App $kirby): void {
				$description = Business::head($kirby, $kirby->page('laden'))['description'];
				holds(mb_strlen($description) <= 155 && str_ends_with($description, '…') && str_starts_with($description, 'Kinder <6 Jahre frei. Kinder <6'), 'head: a long hook text is excerpted, the "<6" kept');
			}
		);
		bizSite(
			['options' => bizOn(['annai.title' => fn (Page $page, array $facts): string => 'Eigen: ' . $page->title()])],
			function (App $kirby): void {
				expect('Eigen: Hofladen', Business::head($kirby, $kirby->page('laden'))['title'], 'head: annai.title Closure replaces the rule');
			}
		);
		bizSite(
			['options' => bizOn(['annai.canonical' => fn (Page $page): string => 'https://canonical.example/x'])],
			function (App $kirby): void {
				expect('https://canonical.example/x', Business::head($kirby, $kirby->page('laden'))['canonical'], 'head: annai.canonical Closure');
			}
		);
		// The rule that no control character from a Panel field reaches the head
		// once had two gaps: og:image:alt — the annai.image hook usually hands over
		// $page->image()->alt() — and the JSON-LD sameAs from the profile fields; the
		// hook's URL and canonical were only trimmed too.
		$tmp = annai_site();
		try {
			bizFixture($tmp, ['site' => ['instagram' => "https://www.instagram.com/testhof\u{9B}2J", 'facebook' => "https://www.facebook.com/test\e[2Jhof"]]);
			$kirby = annai_kirby($tmp, bizOn([
				'annai.image'     => fn (Page $page): array => ['url' => "https://cdn.example/\e[2Jteilen.jpg", 'alt' => "Hof\e[2J\u{9B}ansicht\n am  Berg"],
				'annai.canonical' => fn (Page $page): string => "https://canonical.example/\u{9B}x\e[2J",
			]));
			$log = annai_with_error_log($tmp, function () use ($kirby, &$head, &$facts, &$html): void {
				$head = Business::head($kirby, $kirby->page('laden'));
				$facts = Business::facts($kirby);
				[, $html] = annai_request($kirby, '');
			});
			expect('Hof[2Jansicht am Berg', $head['image']['alt'] ?? null, 'head: og:image:alt from the hook loses its control characters and is one line');
			expect('https://cdn.example/[2Jteilen.jpg', $head['image']['url'] ?? null, 'head: the hook image URL loses its control characters');
			expect('https://canonical.example/x[2J', $head['canonical'], 'head: the hook canonical loses its control characters');
			expect([], $facts['sameAs'], 'facts: a profile URL with a control character is left out of sameAs');
			// Text::shown(), as for the phone fields: the C1 character is logged as an escape, never raw.
			holds(str_contains($log, 'Annai: sameAs entry "https://www.instagram.com/testhof\\u009b2J" (field instagram) holds a space or a control character — left out.'), 'facts: the instagram URL with a C1 control is logged with its field, the control as an escape');
			holds(!str_contains($log, "\u{9B}"), 'facts: no raw C1 character in the log');
			holds(str_contains($log, '(field facebook) holds a space or a control character'), 'facts: the facebook URL with an escape sequence is logged too');
			expect(0, preg_match('/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u', $html), 'page: no control character anywhere in the rendered page');
			holds(str_contains($html, '<meta property="og:image:alt" content="Hof[2Jansicht am Berg">'), 'page: og:image:alt is printed clean');
			holds(str_contains($html, '"@type":"LocalBusiness"') && !str_contains($html, '"sameAs"'), 'home page: the business node has no sameAs when every profile URL was left out');
			bizFixture($tmp, ['site' => ['instagram' => 'https://www.instagram.com/testhof', 'facebook' => 'https://www.facebook.com/test hof']]);
			$facts = Business::facts(annai_kirby($tmp, bizOn()));
			expect(['https://www.instagram.com/testhof'], $facts['sameAs'], 'facts: a profile URL with a space inside is left out, a clean one stays');
		} finally {
			annai_remove_tree($tmp);
		}
		bizSite(['options' => bizOn(['annai.image' => 'assets/teilen.jpg'])], function (App $kirby): void {
			expect(
				['url' => 'http://example.invalid/assets/teilen.jpg', 'width' => null, 'height' => null, 'alt' => ''],
				Business::head($kirby, $kirby->site()->homePage())['image'],
				'head: image as a path becomes an absolute URL'
			);
		});
		bizSite(
			['options' => bizOn(['annai.image' => ['url' => 'https://cdn.example/teilen.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Hofansicht']])],
			function (App $kirby): void {
				expect(
					['url' => 'https://cdn.example/teilen.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Hofansicht'],
					Business::resolveImage($kirby, $kirby->page('laden')),
					'resolveImage: array form with width, height and alt'
				);
			}
		);
		bizSite(
			['options' => bizOn(['annai.image' => fn (Page $page) => ['url' => 'assets/' . $page->id() . '.jpg']])],
			function (App $kirby): void {
				expect(
					['url' => 'http://example.invalid/assets/laden.jpg', 'width' => null, 'height' => null, 'alt' => ''],
					Business::resolveImage($kirby, $kirby->page('laden')),
					'resolveImage: Closure form gets the page'
				);
			}
		);
		bizSite(['options' => bizOn(['annai.image' => fn (Page $page): string|null => null])], function (App $kirby): void {
			expect(null, Business::resolveImage($kirby, $kirby->page('laden')), 'resolveImage: Closure returning null gives null');
		});
		bizSite(['options' => bizOn(['locale' => 'de_DE.utf8'])], function (App $kirby): void {
			expect('de_DE', Business::head($kirby, $kirby->site()->homePage())['locale'], 'head: locale from Kirby option de_DE.utf8 → de_DE');
		});
		bizSite(['options' => bizOn(['locale' => 'de_DE.utf8', 'annai.locale' => 'en-gb'])], function (App $kirby): void {
			expect('en_GB', Business::head($kirby, $kirby->site()->homePage())['locale'], 'head: annai.locale wins and is normalised to xx_YY');
		});
		bizSite(['options' => bizOn(['annai.twitterCard' => 'summary_large_image'])], function (App $kirby): void {
			expect('summary_large_image', Business::head($kirby, $kirby->site()->homePage())['twitterCard'], 'head: valid twitterCard kept');
		});
		bizSite(['options' => bizOn(['annai.twitterCard' => 'nope'])], function (App $kirby): void {
			expect(null, Business::head($kirby, $kirby->site()->homePage())['twitterCard'], 'head: invalid twitterCard → null');
		});
		/* ---------------------------------------------------------------------------
		 * 7. Noren hours (needs kirby-noren; otherwise SKIP)
		 * ------------------------------------------------------------------------- */
		$norenChecks = [
			'units: two areas give two units, primary first',
			'units: days[0] blocks [[480,720],[840,1080]] and note on Saturday',
			'units: closures carry label, hours and the Heiligabend hours',
			'units: schema keys are present and carry the holiday date',
			'units: annai.area selects the primary unit',
			'units: no unit matches annai.area → first unit is primary',
			'units: annai.noren false → []',
			'units: noren.active false → []',
			'units: two locations, each its own hours (department dayOfWeek)',
			'units: a second location without areas becomes a department',
			'units: noren.mergeAreas keeps every area and its own closure',
		];
		if ($norenPath === null) {
			foreach ($norenChecks as $what) {
				skip($what, 'kirby-noren not found (ANNAI_NOREN_PATH or ../kirby-noren)');
			}
		} else {
			bizSite(['options' => bizOn(['noren.active' => true, 'noren.language' => 'de']), 'noren' => true], function (App $kirby) use ($year): void {
				$units = Business::units($kirby);
				expect(2, count($units), 'units: two areas give two units');
				expect('laden', $units[0]['area'] ?? null, 'units: laden first (primary)');
				expect(true, $units[0]['primary'] ?? null, 'units: first unit is primary without annai.area');
				expect(false, $units[1]['primary'] ?? null, 'units: cafe is not primary');
				expect('Hofcafé', $units[1]['name'] ?? null, 'units: name from Noren');

				$days = $units[0]['days'];
				expect([[480, 720], [840, 1080]], $days[0]['blocks'], 'units: Monday blocks in minutes');
				expect(null, $days[0]['note'], 'units: Monday has no note');
				expect([], $days[1]['blocks'], 'units: Tuesday closed');
				expect('nur Verkauf', $days[5]['note'], 'units: Saturday note');
				expect([[780, 1020]], $units[1]['days'][6]['blocks'], 'units: cafe Sunday 13:00–17:00');
				expect(true, is_bool($units[0]['holidays']), 'units: holidays is a bool');

				$closures = $units[0]['closures'];
				$silvester = bizFind($closures, 'label', 'Silvester');
				expect($year . '-12-31', $silvester['from'] ?? null, 'closures: Silvester from (label from the occasion)');
				expect(null, $silvester['hours'] ?? null, 'closures: Silvester hours null (closed)');
				$eve = bizFind($closures, 'label', 'Heiligabend');
				expect([510, 720], $eve['hours'] ?? null, 'closures: Heiligabend hours 08:30–12:00 in minutes');
				$range = bizFind($closures, 'from', $year . '-08-02');
				expect('Betriebsferien', $range['label'] ?? null, 'closures: range without occasion gets the closure label');
				expect($year . '-08-15', $range['to'] ?? null, 'closures: range to');

				$schema = $units[0]['schema'];
				holds(count($schema['openingHoursSpecification']) > 0, 'schema: openingHoursSpecification present for the primary unit');
				holds(count(array_filter($schema['openingHoursSpecification'], fn ($s) => str_ends_with((string)($s['dayOfWeek'] ?? ''), 'Monday'))) > 0, 'schema: Monday in openingHoursSpecification');
				holds(bizFind($schema['specialOpeningHoursSpecification'], 'validFrom', $year . '-12-24') !== null, 'schema: Heiligabend in specialOpeningHoursSpecification');
			});

			bizSite(['options' => bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.area' => 'cafe']), 'noren' => true], function (App $kirby): void {
				$units = Business::units($kirby);
				expect('cafe', $units[0]['area'] ?? null, 'units: annai.area = cafe puts the cafe first');
				expect(true, $units[0]['primary'] ?? null, 'units: annai.area selects the primary unit');
				expect(false, $units[1]['primary'] ?? null, 'units: laden is not primary when cafe is');
			});

			bizSite(['options' => bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.area' => 'nichts']), 'noren' => true], function (App $kirby): void {
				$log = annai_with_error_log($kirby->root('base'), function () use ($kirby, &$units): void {
					$units = Business::units($kirby);
				});
				expect('laden', $units[0]['area'] ?? null, 'units: unknown annai.area → first unit stays first');
				expect(true, $units[0]['primary'] ?? null, 'units: unknown annai.area → first unit is primary');
				// The fallback used to be silent.
				holds(str_contains($log, 'annai.area "nichts" is no area with hours of any Noren location'), 'units: unknown annai.area is reported');
			});

			bizSite(['options' => bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.area' => 'cafe']), 'noren' => true], function (App $kirby): void {
				$log = annai_with_error_log($kirby->root('base'), function () use ($kirby): void {
					Business::units($kirby);
				});
				expect('', $log, 'units: a matching annai.area logs nothing');
			});

			bizSite(['options' => bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.noren' => false]), 'noren' => true], function (App $kirby): void {
				expect([], Business::units($kirby), 'units: annai.noren false → []');
				expect([], Business::facts($kirby)['units'], 'facts: annai.noren false → units []');
			});

			bizSite(['options' => bizOn(['noren.active' => false, 'noren.language' => 'de']), 'noren' => true], function (App $kirby): void {
				expect([], Business::units($kirby), 'units: noren.active false → []');
			});

			// noren.mergeAreas groups areas that look alike today; a café closure beyond
			// Noren's lead time does not split them. JSON-LD and llms.txt need every
			// area with its own closures, not the display groups.
			$tmp = annai_site(['noren']);
			try {
				$closure = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->modify('+30 days')->format('Y-m-d');
				$rows = [];
				foreach (['laden', 'cafe'] as $area) {
					$rows[] = ['bereich' => $area, 'tag' => 'Montag', 'von1' => '09:00', 'bis1' => '17:00'];
				}
				annai_page($tmp, '', 'site', bizSiteDefaults());
				annai_page($tmp, '1_kontakt', 'betrieb', array_merge(bizContactDefaults(), [
					'bereiche' => [['schluessel' => 'laden', 'name' => 'Hofladen'], ['schluessel' => 'cafe', 'name' => 'Hofcafé']],
					'zeiten' => $rows,
					'schliessungen' => [['anlass' => 'Renovierung', 'von' => $closure, 'bis' => $closure, 'bereich' => 'cafe']],
				]));
				$kirby = annai_kirby($tmp, bizOn(['noren.active' => true, 'noren.mergeAreas' => true, 'noren.language' => 'de']));
				expect(1, count($kirby->site()->norenUnits()), 'mergeAreas: Noren shows one group today');
				$units = Business::units($kirby);
				expect(['laden', 'cafe'], array_column($units, 'area'), 'units: noren.mergeAreas keeps every area');
				expect('Hofcafé', $units[1]['name'] ?? null, 'units: the café keeps its own name');
				expect($closure, $units[1]['closures'][0]['from'] ?? null, 'units: the café keeps its own closure');
				expect([], $units[0]['closures'], 'units: the laden has no closure');
				holds(bizFind($units[1]['schema']['specialOpeningHoursSpecification'] ?? [], 'validFrom', $closure) !== null, 'units: noren.mergeAreas keeps every area and its own closure');
			} finally {
				annai_remove_tree($tmp);
			}

			// A Noren page with hours but no address of its own, the address on
			// annai.location: the unit is at the business, its hours on the business node.
			$tmp = annai_site(['noren']);
			try {
				annai_page($tmp, '', 'site', ['title' => 'Hof', 'marke' => 'Hof']);
				annai_page($tmp, '1_kontakt', 'default', ['title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt', 'telefon' => '030 34567001']);
				annai_page($tmp, '2_zeiten', 'betrieb', ['title' => 'Zeiten',
					'bereiche' => [['schluessel' => 'laden', 'name' => 'Laden']],
					'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
				$kirby = annai_kirby($tmp, ['annai.active' => true, 'noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de', 'annai.location' => 'kontakt']);
				$units = Business::units($kirby);
				$doc = Business::jsonLd($kirby, $kirby->site()->homePage());
				expect([null], array_column($units, 'place'), 'hours page without an address: no place of its own');
				expect([true], array_column($units, 'primary'), 'hours page without an address: its unit is the primary one');
				expect(1, count($doc['openingHoursSpecification'] ?? []), 'hours page without an address: hours on the business node');
				expect([], $doc['department'] ?? [], 'hours page without an address: no department');
			} finally {
				annai_remove_tree($tmp);
			}

			// The address in site.txt (the site.txt pattern), annai.location
			// without one, the Noren page repeating it: still at the business.
			$tmp = annai_site(['noren']);
			try {
				annai_page($tmp, '', 'site', ['title' => 'Hof', 'marke' => 'Hof', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt']);
				annai_page($tmp, '1_kontakt', 'default', ['title' => 'Kontakt']);
				annai_page($tmp, '2_zeiten', 'betrieb', ['title' => 'Zeiten', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt',
					'bereiche' => [['schluessel' => 'laden', 'name' => 'Laden']],
					'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
				$kirby = annai_kirby($tmp, ['annai.active' => true, 'noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de', 'annai.location' => 'kontakt']);
				$units = Business::units($kirby);
				$doc = Business::jsonLd($kirby, $kirby->site()->homePage());
				expect([null], array_column($units, 'place'), 'address in site fields, repeated on the hours page: no place');
				expect([true], array_column($units, 'primary'), 'address in site fields, repeated on the hours page: primary');
				expect(1, count($doc['openingHoursSpecification'] ?? []), 'address in site fields, repeated on the hours page: hours on the business node');
			} finally {
				annai_remove_tree($tmp);
			}

			// The address page is annai.location, the hours with two areas on another
			// page without an address of its own: the units are at the business, so the second
			// area is the department `#cafe` (README: the bare area key on the business), not
			// `#zeiten-cafe` — a changed @id is a new entity for Google. The same without a
			// business page (`fn () => null`) and with a business page that has no address ('home').
			foreach ([
				"annai.location => 'kontakt'" => ['annai.location' => 'kontakt'],
				'annai.location => fn () => null' => ['annai.location' => fn () => null],
				"annai.location => 'home'" => ['annai.location' => 'home'],
			] as $label => $extra) {
				$tmp = annai_site(['noren']);
				try {
					annai_page($tmp, '', 'site', ['title' => 'Hof', 'marke' => 'Hof']);
					annai_page($tmp, '1_kontakt', 'default', ['title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt', 'telefon' => '030 34567001']);
					annai_page($tmp, '2_zeiten', 'betrieb', ['title' => 'Zeiten',
						'bereiche' => [['schluessel' => 'laden', 'name' => 'Laden'], ['schluessel' => 'cafe', 'name' => 'Café']],
						'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00'],
							['bereich' => 'cafe', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
					$kirby = annai_kirby($tmp, ['annai.active' => true, 'noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de'] + $extra);
					$units = Business::units($kirby);
					$doc = Business::jsonLd($kirby, $kirby->site()->homePage());
					expect([true, true], array_column($units, 'atBusiness'), "$label, hours page without an address: both units are at the business");
					expect([null, null], array_column($units, 'place'), "$label, hours page without an address: no place of their own");
					expect([true, false], array_column($units, 'primary'), "$label, hours page without an address: laden is primary, cafe a department");
					expect(['http://example.invalid/#cafe'], array_column($doc['department'] ?? [], '@id'), "$label, hours page without an address: the department @id is the bare area key");
				} finally {
					annai_remove_tree($tmp);
				}
			}

			// Two betrieb pages at the same address, the same area key on both:
			// the departments keep distinct @ids.
			$tmp = annai_site(['noren']);
			try {
				annai_page($tmp, '', 'site', ['title' => 'Hof', 'marke' => 'Hof']);
				$address = ['strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt'];
				annai_page($tmp, '1_a', 'betrieb', ['title' => 'A'] + $address + [
					'bereiche' => [['schluessel' => 'laden', 'name' => 'Laden'], ['schluessel' => 'cafe', 'name' => 'Café']],
					'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00'], ['bereich' => 'cafe', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
				annai_page($tmp, '2_b', 'betrieb', ['title' => 'B'] + $address + [
					'bereiche' => [['schluessel' => 'cafe', 'name' => 'Café']],
					'zeiten' => [['bereich' => 'cafe', 'tag' => 'Mittwoch', 'von1' => '10:00', 'bis1' => '18:00']]]);
				$kirby = annai_kirby($tmp, ['annai.active' => true, 'noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de']);
				$doc = Business::jsonLd($kirby, $kirby->site()->homePage());
				$ids = array_map(fn (array $d): string => (string)($d['@id'] ?? ''), $doc['department'] ?? []);
				expect(count($ids), count(array_unique($ids)), 'same address, same area key on two pages: department @ids are unique');
				holds(in_array(true, array_map(fn (string $id): bool => str_ends_with($id, '#b-cafe'), $ids), true), 'same address: the second page\'s area is #b-cafe');
				// A page with an address of its own, even the business's, is its own location: not "at the business".
				expect([true, true, false], array_column(Business::units($kirby), 'atBusiness'), 'same address: the second page\'s unit is not at the business (its page carries an address)');
			} finally {
				annai_remove_tree($tmp);
			}

			// Two Noren locations (two betrieb pages): each unit's hours come from its own page.
			$twoLocations = function (array $second, array $options = []): void {
				$tmp = annai_site(['noren']);
				try {
					annai_page($tmp, '', 'site', ['title' => 'Praxis', 'marke' => 'Praxis']);
					annai_page($tmp, '1_a', 'betrieb', ['title' => 'Standort A', 'strasse' => 'Musterweg 1', 'ort' => 'Musterstadt', 'telefon' => '030 34567001',
						'bereiche' => [['schluessel' => 'laden', 'name' => 'Laden A']],
						'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
					// $second may replace a field of location B (its telefon, for example).
					annai_page($tmp, '2_b', 'betrieb', array_merge(['title' => 'Standort B', 'strasse' => 'Bahnhofstraße 2', 'ort' => 'Musterdorf', 'telefon' => '030 34567002'], $second));
					$kirby = annai_kirby($tmp, bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de'] + $options));
					$log = annai_with_error_log($tmp, function () use ($kirby, &$units, &$document): void {
						$units = Business::units($kirby);
						$document = Business::jsonLd($kirby, $kirby->site()->homePage()) ?? [];
					});
					[, $llms] = annai_request($kirby, 'llms.txt');
					$GLOBALS['bizTwoLocations'] = [$units, $document['department'] ?? [], $document, $log, $llms];
				} finally {
					annai_remove_tree($tmp);
				}
			};
			$days = fn (array $node): array => array_map(fn ($spec) => $spec['dayOfWeek'] ?? null, $node['openingHoursSpecification'] ?? []);

			$twoLocations([
				'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis B']],
				'zeiten' => [['bereich' => 'praxis', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']],
			]);
			[$units, $departments, $document] = $GLOBALS['bizTwoLocations'];
			expect(['a', 'b'], array_column($units, 'location'), 'units: two locations, each unit names its location page');
			expect(['https://schema.org/Monday'], $days($document), 'units: two locations, the business node has location A\'s Monday');
			expect(1, count($departments), 'units: two locations, one department for location B');
			expect(['https://schema.org/Tuesday'], $days($departments[0] ?? []), 'units: two locations, department carries location B\'s Tuesday');
			expect('http://example.invalid/#b-praxis', $departments[0]['@id'] ?? null, 'units: two locations, department @id from location and area');
			expect('Bahnhofstraße 2', $departments[0]['address']['streetAddress'] ?? null, 'units: two locations, department address from its own page');
			expect('+493034567002', $departments[0]['telephone'] ?? null, 'units: two locations, department phone from its own page');
			// With units of two location pages the name is Noren's label ("<location> · <area>"), see below.
			holds(
				str_contains($GLOBALS['bizTwoLocations'][4], "### Standort B · Praxis B\n\n- Adresse: Bahnhofstraße 2, Musterdorf\n- Telefon: 030 34567002 (tel:+493034567002)\n\n- Montag: geschlossen\n- Dienstag: 9:00–17:00 Uhr"),
				'units: two locations, llms.txt has location B\'s address and phone under its heading'
			);
			expect(['Standort A · Laden A', 'Standort B · Praxis B'], array_column($units, 'name'), 'units: two locations, the names carry the location (Noren\'s label)');

			// annai.area names the area of location B: the business node keeps location A's
			// address, phone and hours, Praxis B stays a department with its own.
			$twoLocations([
				'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis B']],
				'zeiten' => [['bereich' => 'praxis', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']],
			], ['annai.area' => 'praxis']);
			[$units, $departments, $document, $log] = $GLOBALS['bizTwoLocations'];
			expect(['laden', 'praxis'], array_column($units, 'area'), 'units: annai.area at another location → the business location\'s unit stays first');
			expect([true, false], array_column($units, 'primary'), 'units: annai.area at another location → that unit is not primary');
			expect(
				['Musterweg 1', '+493034567001', ['https://schema.org/Monday']],
				[$document['address']['streetAddress'] ?? null, $document['telephone'] ?? null, $days($document)],
				'units: annai.area at another location → business node pairs location A\'s address, phone and hours'
			);
			expect(
				[['http://example.invalid/#b-praxis', 'Bahnhofstraße 2', '+493034567002', ['https://schema.org/Tuesday']]],
				array_map(fn ($d) => [$d['@id'] ?? null, $d['address']['streetAddress'] ?? null, $d['telephone'] ?? null, $days($d)], $departments),
				'units: annai.area at another location → Praxis B is the department with location B\'s address, phone and hours'
			);
			holds(str_contains($log, 'annai.area "praxis" is an area of the location "b"'), 'units: annai.area at another location is reported');

			// A location page's phone follows the input contract like the business phone (SPEC §3.2):
			// within it the department has its number, a note around it gives none.
			$twoLocations(['telefon' => '030 34567030', 'zeiten' => [['tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
			[, $departments] = $GLOBALS['bizTwoLocations'];
			expect('+493034567030', $departments[0]['telephone'] ?? null, 'units: a department phone "030 34567030" is read');
			$twoLocations(['telefon' => '(Tel. 030 34567030)', 'zeiten' => [['tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
			[, $departments] = $GLOBALS['bizTwoLocations'];
			expect(null, $departments[0]['telephone'] ?? null, 'units: a department phone "(Tel. 030 34567030)" is outside the contract, no telephone');

			$twoLocations(['zeiten' => [['tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
			[$units, $departments] = $GLOBALS['bizTwoLocations'];
			expect([null], array_column(array_slice($units, 1), 'area'), 'units: second location without areas is a unit with area null');
			expect(1, count($departments), 'units: a second location without areas becomes a department');
			expect('http://example.invalid/#b', $departments[0]['@id'] ?? null, 'units: department @id of a location without areas is the page id');
			expect(['https://schema.org/Tuesday'], $days($departments[0] ?? []), 'units: department of a location without areas carries its hours');

			// No business page (`annai.location => fn () => null`), the address in
			// site.txt, two Noren locations with addresses of their own. otherPlace() used
			// to give up at the missing business page, so both units counted as at the business:
			// the second location became a department with the MAIN address and phone and
			// lost its address lines in llms.txt. Now the comparison is against the
			// site fields, as facts() reads them. Two locations with the same area name
			// used to give two identical `### Praxis` headings; the name is Noren's label
			// "<location> · <area>" as soon as units of two location pages exist.
			$twoNoren = function (array $site): void {
				$tmp = annai_site(['noren']);
				try {
					annai_page($tmp, '', 'site', ['title' => 'Praxis', 'marke' => 'Praxis Muster', 'telefon' => '030 34567001'] + $site);
					annai_page($tmp, '1_nord', 'betrieb', ['title' => 'Praxis Nord', 'strasse' => 'Nordweg 2', 'plz' => '12345', 'ort' => 'Musterstadt', 'telefon' => '030 34567002',
						'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
						'zeiten' => [['bereich' => 'praxis', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
					annai_page($tmp, '2_sued', 'betrieb', ['title' => 'Praxis Süd', 'strasse' => 'Südweg 3', 'plz' => '12346', 'ort' => 'Musterdorf', 'telefon' => '030 34567003',
						'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
						'zeiten' => [['bereich' => 'praxis', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
					$kirby = annai_kirby($tmp, bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de',
						'noren.locations' => ['nord', 'sued'], 'annai.location' => fn () => null]));
					$units = Business::units($kirby);
					$document = Business::jsonLd($kirby, $kirby->site()->homePage()) ?? [];
					[, $llms] = annai_request($kirby, 'llms.txt');
					$GLOBALS['bizTwoNoren'] = [$units, $document, $llms];
				} finally {
					annai_remove_tree($tmp);
				}
			};

			// The site address is location Nord's: Nord is at the business, Süd elsewhere.
			$twoNoren(['strasse' => 'Nordweg 2', 'plz' => '12345', 'ort' => 'Musterstadt']);
			[$units, $document, $llms] = $GLOBALS['bizTwoNoren'];
			expect([null, 'Südweg 3'], array_map(fn (array $u) => $u['place']['street'] ?? null, $units), 'no business page: the unit at the site address has no place, the other its own');
			expect([true, false], array_column($units, 'primary'), 'no business page: the unit at the site address is primary');
			expect(
				['Nordweg 2', '+493034567001', ['https://schema.org/Monday']],
				[$document['address']['streetAddress'] ?? null, $document['telephone'] ?? null, $days($document)],
				'no business page: the business node has the site address and phone with Nord\'s hours'
			);
			expect(
				[['http://example.invalid/#sued-praxis', 'Südweg 3', '+493034567003', ['https://schema.org/Tuesday']]],
				array_map(fn ($d) => [$d['@id'] ?? null, $d['address']['streetAddress'] ?? null, $d['telephone'] ?? null, $days($d)], $document['department'] ?? []),
				'no business page: the second location is a department with its OWN address and phone'
			);
			expect(['Praxis Nord · Praxis', 'Praxis Süd · Praxis'], array_column($units, 'name'), 'two locations, same area name: the names are Noren\'s labels');
			expect(['Praxis Muster — Praxis Süd · Praxis'], array_column($document['department'] ?? [], 'name'), 'two locations, same area name: the department name carries the location');
			holds(str_contains($llms, "### Praxis Nord · Praxis\n\n- Montag: 8:00–12:00 Uhr"), 'two locations, same area name: llms.txt heading of the first names its location');
			holds(
				str_contains($llms, "### Praxis Süd · Praxis\n\n- Adresse: Südweg 3, 12346 Musterdorf\n- Telefon: 030 34567003 (tel:+493034567003)\n\n- Montag: geschlossen\n- Dienstag: 9:00–17:00 Uhr"),
				'no business page: llms.txt has the second location\'s address and phone under its heading'
			);
			expect(0, substr_count($llms, "### Praxis\n"), 'two locations, same area name: no bare duplicate heading');

			// The site address is neither location's: both are elsewhere,
			// both departments with their own addresses, no primary; the business node keeps the
			// site address without hours.
			$twoNoren(['strasse' => 'Hauptstraße 1', 'plz' => '12345', 'ort' => 'Musterstadt']);
			[$units, $document, $llms] = $GLOBALS['bizTwoNoren'];
			expect(['Nordweg 2', 'Südweg 3'], array_map(fn (array $u) => $u['place']['street'] ?? null, $units), 'no business page, site address elsewhere: both units have their own place');
			expect([false, false], array_column($units, 'primary'), 'no business page, site address elsewhere: no primary unit');
			expect(['Hauptstraße 1', []], [$document['address']['streetAddress'] ?? null, $days($document)], 'no business page, site address elsewhere: the business node has the site address, no hours');
			expect(['Nordweg 2', 'Südweg 3'], array_map(fn ($d) => $d['address']['streetAddress'] ?? null, $document['department'] ?? []), 'no business page, site address elsewhere: two departments with their own addresses');
			holds(str_contains($llms, '- Adresse: Nordweg 2, 12345 Musterstadt') && str_contains($llms, '- Adresse: Südweg 3, 12346 Musterdorf'), 'no business page, site address elsewhere: llms.txt has both addresses');

			// A draft named in `noren.locations` (Noren's $kirby->page() finds drafts)
			// used to be published with its address, phone and hours: in llms.txt and as a department
			// of the home page's JSON-LD, although the page itself is in no sitemap.
			$draftLocation = function (bool $draft) use ($year): void {
				$tmp = annai_site(['noren']);
				try {
					annai_page($tmp, '', 'site', ['title' => 'Praxis', 'marke' => 'Praxis Muster', 'telefon' => '030 34567001', 'strasse' => 'Nordweg 2', 'plz' => '12345', 'ort' => 'Musterstadt']);
					annai_page($tmp, '1_nord', 'betrieb', ['title' => 'Praxis Nord', 'strasse' => 'Nordweg 2', 'plz' => '12345', 'ort' => 'Musterstadt', 'telefon' => '030 34567002',
						'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
						'zeiten' => [['bereich' => 'praxis', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
					annai_page($tmp, $draft ? '_drafts/sued' : '2_sued', 'betrieb', ['title' => 'Praxis Süd', 'strasse' => 'Südweg 3', 'plz' => '12346', 'ort' => 'Musterdorf', 'telefon' => '030 34567003',
						'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
						'zeiten' => [['bereich' => 'praxis', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']],
						'schliessungen' => [['anlass' => 'Umbau', 'von' => $year . '-03-01', 'bis' => $year . '-03-05']]]);
					$kirby = annai_kirby($tmp, bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de',
						'noren.locations' => ['nord', 'sued'], 'annai.location' => fn () => null]));
					$log = annai_with_error_log($tmp, function () use ($kirby, &$units, &$document, &$llms): void {
						$units = Business::units($kirby);
						$document = Business::jsonLd($kirby, $kirby->site()->homePage()) ?? [];
						[, $llms] = annai_request($kirby, 'llms.txt');
					});
					$sued = $kirby->page('sued');
					$GLOBALS['bizDraftLocation'] = [$units, $document, $llms, $log, $sued instanceof Page && Sayamaapps\Annai\Pages::isDraft($sued)];
				} finally {
					annai_remove_tree($tmp);
				}
			};
			$draftLocation(true);
			[$units, $document, $llms, $log, $isDraft] = $GLOBALS['bizDraftLocation'];
			expect(true, $isDraft, 'draft location: Noren finds the draft page (fixture check)');
			expect(['nord'], array_column($units, 'location'), 'draft location: no unit of a draft location');
			expect(['Praxis'], array_column($units, 'name'), 'draft location: one visible location keeps the bare area name, not Noren\'s label');
			expect([true], array_column($units, 'primary'), 'draft location: the visible unit is primary');
			holds(!str_contains($llms, 'Südweg') && !str_contains($llms, '030 34567003') && !str_contains($llms, 'Umbau'), 'draft location: llms.txt has neither the address, the phone nor the closure of the draft');
			holds(!str_contains($llms, '### '), 'draft location: llms.txt has no unit heading for one unit');
			expect([], $document['department'] ?? [], 'draft location: the home page JSON-LD has no department of the draft');
			expect(['https://schema.org/Monday'], $days($document), 'draft location: the business node keeps the visible location\'s hours');
			holds(str_contains($log, 'Annai: Noren location "sued" is a draft — left out.'), 'draft location: logged once');
			expect(1, substr_count($log, 'is a draft'), 'draft location: logged once, not per call');
			$draftLocation(false);
			[$units, $document, $llms, $log] = $GLOBALS['bizDraftLocation'];
			expect(['nord', 'sued'], array_column($units, 'location'), 'published location: both units (counter-check)');
			expect(1, count($document['department'] ?? []), 'published location: one department (counter-check)');
			holds(str_contains($llms, 'Südweg 3'), 'published location: llms.txt has the second address (counter-check)');
			holds(!str_contains($log, 'is a draft'), 'published location: nothing logged (counter-check)');

			// The phone of another location (place()) follows the same rule as the
			// business phone: the raw value to Text::phone(), a field that gives no number logged
			// (SPEC §3.2, the input contract: a line break is outside the gate, two numbers
			// joined by "/" are no valid number).
			$tmp = annai_site(['noren']);
			try {
				annai_page($tmp, '', 'site', ['title' => 'Praxis', 'marke' => 'Praxis Muster', 'strasse' => 'Nordweg 2', 'plz' => '12345', 'ort' => 'Musterstadt']);
				annai_page($tmp, '1_nord', 'betrieb', ['title' => 'Praxis Nord', 'strasse' => 'Nordweg 2', 'plz' => '12345', 'ort' => 'Musterstadt',
					'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
					'zeiten' => [['bereich' => 'praxis', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
				annai_page($tmp, '2_sued', 'betrieb', ['title' => 'Praxis Süd', 'strasse' => 'Südweg 3', 'plz' => '12346', 'ort' => 'Musterdorf', 'telefon' => "030 3\n0171 4",
					'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
					'zeiten' => [['bereich' => 'praxis', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00']]]);
				// A value of its own: Options::warn() logs a message once per process.
				annai_page($tmp, '3_west', 'betrieb', ['title' => 'Praxis West', 'strasse' => 'Westweg 4', 'plz' => '12346', 'ort' => 'Musterdorf', 'telefon' => '030 34567000 / 0171 1234569',
					'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis']],
					'zeiten' => [['bereich' => 'praxis', 'tag' => 'Mittwoch', 'von1' => '09:00', 'bis1' => '17:00']]]);
				$kirby = annai_kirby($tmp, bizOn(['noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de',
					'noren.locations' => ['nord', 'sued', 'west'], 'annai.location' => fn () => null]));
				$log = annai_with_error_log($tmp, function () use ($kirby, &$units): void {
					$units = Business::units($kirby);
				});
				expect(['030 3 0171 4', ''], [$units[1]['place']['phone'] ?? null, $units[1]['place']['phoneE164'] ?? null], 'place(): the display string is one line; two numbers publish no E.164 number');
				expect(['030 34567000 / 0171 1234569', ''], [$units[2]['place']['phone'] ?? null, $units[2]['place']['phoneE164'] ?? null], 'place(): two numbers joined by a slash publish no E.164 number');
				holds(str_contains($log, 'Annai: field telefon holds more than the number ("030 3\\n0171 4")'), 'place(): two numbers on two lines are logged, the line break visible');
				holds(str_contains($log, 'Annai: field telefon holds no valid phone number ("030 34567000 / 0171 1234569")'), 'place(): two numbers joined by a slash are logged');
			} finally {
				annai_remove_tree($tmp);
			}

			// With Noren installed EVERY page used to lose Kirby's page cache — head() →
			// facts() → units() → norenSchema(), and Noren switches the cache off for
			// time-dependent output. Only the home page's JSON-LD and the jsonld hook show
			// hours, so a subpage leaves Noren alone and stays cacheable.
			$tmp = annai_site(['noren']);
			try {
				bizFixture($tmp, ['noren' => true]);
				$cached = bizOn(['noren.active' => true, 'noren.language' => 'de', 'cache' => ['pages' => ['active' => true]]]);
				$kirby = annai_kirby($tmp, $cached);
				[$code] = annai_request($kirby, 'laden');
				expect(200, $code, 'units lazy: the subpage renders with Noren on');
				expect(true, $kirby->response()->cache(), 'units lazy: a subpage with Noren on stays cacheable (no Noren call)');
				// Kirby's cache id (Page::cacheId() is protected): "<id>.<version>.<type>".
				expect(true, $kirby->cache('pages')->exists('laden.latest.html'), 'units lazy: the subpage has its page cache entry');

				$kirby = annai_kirby($tmp, $cached);
				[$code, $html] = annai_request($kirby, '');
				$document = annai_jsonld($html)[0] ?? [];
				holds(count($document['openingHoursSpecification'] ?? []) > 0, 'units lazy: the home page JSON-LD still carries the hours');
				expect(false, $kirby->response()->cache(), 'units lazy: the home page with hours is kept out of the page cache (Noren)');
				expect(false, $kirby->cache('pages')->exists('home.latest.html'), 'units lazy: the home page has no page cache entry');

				$kirby = annai_kirby($tmp, $cached + ['annai.jsonld' => function (Page $page, array $nodes, array $facts): array {
					$GLOBALS['bizHookUnits'] = count($facts['units']);
					return $nodes;
				}]);
				$kirby->cache('pages')->flush();   // else the subpage is served from the cache the first request wrote
				annai_request($kirby, 'laden');
				expect(2, $GLOBALS['bizHookUnits'] ?? null, 'units lazy: with an annai.jsonld hook the facts carry the units on a subpage');
				expect(false, $kirby->response()->cache(), 'units lazy: with an annai.jsonld hook the subpage is out of the page cache (Noren)');

				expect(2, count(Business::facts($kirby)['units']), 'facts(): the units by default');
				expect([], Business::facts($kirby, false)['units'], 'facts(false): no units');
			} finally {
				annai_remove_tree($tmp);
			}
		}
		/* ---------------------------------------------------------------------------
		 * 8. Without Noren: units stay empty even when noren.active is set
		 * ------------------------------------------------------------------------- */
		bizSite(['options' => bizOn(['noren.active' => true])], function (App $kirby): void {
			expect([], Business::units($kirby), 'units: no Noren plugin installed → []');
		});
		// annai.labels: bekannte Schlüssel mit gleichen Platzhaltern bleiben; der Rest fällt weg
		bizSite(
			['options' => bizOn(['annai.labels' => ['llms.hours' => 'Sprechzeiten', 'llms.hoursLine' => 'nur Uhr', 'llms.nope' => 'x', 'llms.closed' => '  ']])],
			function (App $kirby): void {
				expect(['llms.hours' => 'Sprechzeiten'], \Sayamaapps\Annai\Options::labels($kirby), 'labels: known keys only, placeholders kept');
			}
		);
	}
}
