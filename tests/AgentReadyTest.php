<?php
/**
 * What an agent fetches, with real Kirby (SPEC.md §9.10): llms.txt, robots
 * and JSON-LD of the home page, against fixture content with two opening-hour
 * areas, an upcoming closure and a `</script>` in the description. The 41 checks of the agent-ready run, without the
 * PHP server: every request goes through annai_request().
 *
 *   ASDF_PHP_VERSION=8.5.3 php tests/AgentReadyTest.php
 *
 * Skipped (with the reason printed) when Noren is not available: the hours
 * come from Noren's schema.org arrays.
 */

require_once __DIR__ . '/helpers.php';

use Kirby\Cms\App;

if (annai_sibling('noren') === null) {
    skip('Agent-ready checks (41)', 'kirby-noren not found (ANNAI_NOREN_PATH or ../kirby-noren)');
    finish('Annai agent-ready');
}

/** Fixture content, through the content helpers. */
function arFixture(string $tmp, int $year): void
{
    annai_page($tmp, '', 'site', [
        'title' => 'Testhof', 'marke' => 'Testhof',
        'beschreibung' => "Bio-Hofladen\nmit Café </script><b>x</b>",
    ]);
    annai_page($tmp, '1_kontakt', 'betrieb', [
        'title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt',
        'telefon' => '030 / 34567000', 'email' => '',
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
            ['anlass' => 'Silvester', 'von' => "$year-12-31", 'bis' => "$year-12-31"],
            ['anlass' => 'Heiligabend', 'von' => "$year-12-24", 'bis' => "$year-12-24", 'oeffnet' => '08:30', 'schliesst' => '12:00'],
        ],
    ]);
    annai_page($tmp, '2_laden', 'default', ['title' => 'Hofladen', 'einleitung' => 'Gemüse vom eigenen Feld.']);
    annai_page($tmp, '3_shop', 'weiter', ['title' => 'Zum Shop']);
    annai_page($tmp, 'impressum', 'rechtstext', ['title' => 'Impressum']);
}

/** The options of the main run as annai.* keys. */
function arOptions(array $extra = []): array
{
    // array_merge, not "+": the overrides must win over the defaults
    // (arOptions(['annai.type' => false]) has to switch the node off).
    return array_merge([
        'annai.active' => true,
        'annai.language' => 'de',
        'noren.active' => true,
        'noren.language' => 'de',
        'annai.exclude.templates' => ['error', 'weiter'],
        'annai.type' => 'GroceryStore',
        'annai.sameAs' => ['https://www.instagram.com/testhof', ''],
        'annai.action' => ['type' => 'OrderAction', 'name' => 'Online bestellen', 'target' => 'https://shop.example/testhof'],
    ], $extra);
}

$year = (int) date('Y') + 1; // the closures lie in the future whenever the test runs
$tmp = annai_site(['noren']);
try {
    arFixture($tmp, $year);
    $basis = 'http://example.invalid';
    $kirby = annai_kirby($tmp, arOptions());

    // Server ready: the fixture answers on the home page.
    [$homeStatus, $html] = annai_request($kirby, '/');
    holds($homeStatus === 200, 'the fixture site answers on /');

    // ── HEAD on the machine-readable files ────────────────────────
    foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $file) {
        [$status] = annai_request($kirby, $file, 'HEAD');
        holds($status === 200, "HEAD $file answers 200");
    }

    // ── llms.txt ────────────────────────────────────────────────────────
    [$status, $md, $type] = annai_request($kirby, 'llms.txt');
    expect(200, $status, 'llms.txt answers 200');
    holds(str_starts_with((string) $type, 'text/plain'), 'llms.txt is text/plain');
    holds(str_starts_with($md, "# Testhof\n\n> Bio-Hofladen mit Café"), 'H1 and summary as a quote, line break flattened');
    holds(str_contains($md, '- Adresse: Musterweg 1, 12345 Musterstadt'), 'address from the location page');
    holds(str_contains($md, '- Telefon: 030 / 34567000 (tel:+493034567000)'), 'phone shown and international');
    holds(!str_contains($md, 'E-Mail'), 'empty email does not appear');
    holds(str_contains($md, '- Online bestellen: https://shop.example/testhof'), 'main action with the real target URL');
    holds(str_contains($md, "### Hofladen\n\n- Montag: 8:00–12:00, 14:00–18:00 Uhr"), 'hours per area, both blocks');
    holds(str_contains($md, '- Dienstag: geschlossen'), 'closed day written out');
    holds(str_contains($md, '- Samstag: 8:00–12:00 Uhr (nur Verkauf)'), 'day note');
    holds(str_contains($md, '### Hofcafé') && str_contains($md, '- Sonntag: 13:00–17:00 Uhr'), 'second area');
    holds(str_contains($md, "- 31.12.$year (Silvester): geschlossen"), 'upcoming closure, more than 14 days ahead');
    holds(str_contains($md, "- 24.12.$year (Heiligabend): nur 8:30–12:00 Uhr"), 'shortened opening');
    holds(str_contains($md, "- [Hofladen]($basis/laden): Gemüse vom eigenen Feld."), 'page with an absolute link and its intro');
    holds(!str_contains($md, 'Zum Shop'), 'redirect template left out, as in the sitemap');
    preg_match_all('#\]\(([^)]*)\)#', $md, $links);
    holds($links[1] !== [] && array_filter($links[1], fn ($link) => !str_starts_with($link, 'http')) === [], 'all links absolute');

    // ── JSON-LD ─────────────────────────────────────────────────────────
    expect(200, $homeStatus, 'home page answers 200');
    holds(substr_count($html, '</script>') === 1, 'a </script> from the description does not break out of the block');
    $blocks = annai_jsonld($html);
    expect(1, count($blocks), 'exactly one JSON-LD block on the home page');
    $ld = $blocks[0] ?? [];
    holds(($ld['@context'] ?? null) === 'https://schema.org' && ($ld['@type'] ?? null) === 'GroceryStore', 'context and configured type');
    holds(($ld['@id'] ?? null) === "$basis/#business" && ($ld['url'] ?? null) === $basis, 'stable @id and URL');
    // The former copy kept the raw field ("… </script><b>x</b>"); Annai's description is the
    // rendered field as plain text, so the tags are gone and the text after them ("x") proves it is whole.
    holds(($ld['description'] ?? '') === 'Bio-Hofladen mit Café x', 'description complete');
    holds(($ld['telephone'] ?? null) === '+493034567000', 'telephone international');
    holds(!array_key_exists('email', $ld) && !array_key_exists('geo', $ld) && !array_key_exists('image', $ld), 'empty facts omitted');
    expect(
        ['@type' => 'PostalAddress', 'streetAddress' => 'Musterweg 1', 'postalCode' => '12345', 'addressLocality' => 'Musterstadt', 'addressCountry' => 'DE'],
        $ld['address'] ?? null,
        'PostalAddress'
    );
    $days = array_column($ld['openingHoursSpecification'] ?? [], 'dayOfWeek');
    expect(['https://schema.org/Monday', 'https://schema.org/Monday', 'https://schema.org/Saturday'], $days,
        'main hours are the first area, without the café');
    holds(($ld['openingHoursSpecification'][1]['opens'] ?? null) === '14:00' && ($ld['openingHoursSpecification'][1]['closes'] ?? null) === '18:00', 'second block');
    $special = array_column($ld['specialOpeningHoursSpecification'] ?? [], null, 'validFrom');
    holds(($special["$year-12-31"]['opens'] ?? '') === '00:00' && ($special["$year-12-31"]['closes'] ?? '') === '00:00', 'closed day as 00:00–00:00');
    holds(($special["$year-12-24"]['opens'] ?? '') === '08:30' && ($special["$year-12-24"]['closes'] ?? '') === '12:00', 'shortened opening as special hours');
    expect(['https://www.instagram.com/testhof'], $ld['sameAs'] ?? null, 'sameAs from config, empty entries dropped');
    expect(
        ['@type' => 'OrderAction', 'name' => 'Online bestellen', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => 'https://shop.example/testhof']],
        $ld['potentialAction'] ?? null,
        'potentialAction with the real target'
    );
    holds(count($ld['department'] ?? []) === 1 && ($ld['department'][0]['name'] ?? '') === 'Testhof — Hofcafé'
        && ($ld['department'][0]['openingHoursSpecification'][0]['dayOfWeek'] ?? '') === 'https://schema.org/Sunday', 'second area as department');
    holds(str_contains($html, '"name":"Testhof — Hofcafé"'), 'umlauts and slashes not escaped');
    [, $sub] = annai_request($kirby, 'laden');
    expect([], annai_jsonld($sub), 'subpages carry no JSON-LD');

    // Main area switched by annai.area, then JSON-LD off by annai.type = false.
    $kirby = annai_kirby($tmp, arOptions(['annai.area' => 'cafe']));
    [, $html] = annai_request($kirby, '/');
    $ld = annai_jsonld($html)[0] ?? [];
    holds(($ld['openingHoursSpecification'][0]['dayOfWeek'] ?? '') === 'https://schema.org/Sunday'
        && ($ld['department'][0]['name'] ?? '') === 'Testhof — Hofladen', 'annai.area picks the main hours');

    $kirby = annai_kirby($tmp, arOptions(['annai.type' => false]));
    [, $html] = annai_request($kirby, '/');
    expect([], annai_jsonld($html), 'annai.type = false switches the business node off');

    $options = arOptions();
    unset($options['annai.type']);
    $kirby = annai_kirby($tmp, $options);
    [, $html] = annai_request($kirby, '/');
    $blocks = annai_jsonld($html);
    holds(($blocks[0]['@type'] ?? null) === 'LocalBusiness', 'default type LocalBusiness without config');
} finally {
    annai_remove_tree($tmp);
}

finish('Annai agent-ready');
