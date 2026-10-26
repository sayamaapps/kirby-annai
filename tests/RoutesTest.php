<?php

use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pages::hasContent() reads Kirby's `@internal` Version::contentFile().
 * This storage throws exactly there (and nowhere else — the backtrace says who
 * asks), as a Kirby release that closes the method would. The classes extend
 * Kirby's, which helpers.php loads only inside annai_site(), so they are
 * declared here, conditionally, once the autoloader is in place.
 */
function rtClosedModels(): void
{
    if (class_exists('AnnaiClosedPage', false)) {
        return;
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    class AnnaiClosedStorage extends Kirby\Content\PlainTextStorage
    {
        public static int $thrown = 0;

        public function contentFile(Kirby\Content\VersionId $versionId, Kirby\Cms\Language $language): string
        {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3) as $frame) {
                if (($frame['class'] ?? '') === Kirby\Content\Version::class && $frame['function'] === 'contentFile') {
                    self::$thrown++;
                    throw new BadMethodCallException('Version::contentFile() is internal');
                }
            }
            return parent::contentFile($versionId, $language);
        }
    }

    class AnnaiClosedPage extends Kirby\Cms\Page
    {
        private ?Kirby\Content\Storage $closed = null;

        public function storage(): Kirby\Content\Storage
        {
            return $this->closed ??= new AnnaiClosedStorage($this);
        }
    }
}

/** Declares the Koyomi event model used by the untitled-event checks (needs kirby-koyomi). */
function rtDeclareEventWithoutFallback(): void
{
    if (!class_exists('RtEventWithoutFallback', false)) {
        /** A Koyomi event page whose content in a non-default language is that language's own fields only. */
        class RtEventWithoutFallback extends \Koyomi\Models\EventPage
        {
            public function content(?string $languageCode = null): \Kirby\Content\Content
            {
                $language = \Kirby\Cms\Language::ensure($languageCode ?? 'current');
                if ($language->isDefault()) {
                    return parent::content($languageCode);
                }
                return new \Kirby\Content\Content(parent: $this, data: $this->version()->read($language) ?? [], normalize: false);
            }
        }
    }
}

/** Options that switch Annai on, plus the given ones. */
function rtOn(array $extra = []): array
{
    return ['annai.active' => true] + $extra;
}

/**
 * Boots a fresh site with $content applied to the directory, runs $fn with
 * [$kirby, $tmp] and removes the directory afterwards.
 *
 * @param callable(App, string): void $fn
 */
function rtRun(array $options, callable $fn, ?callable $content = null, array $with = []): void
{
    $tmp = annai_site($with);
    try {
        if ($content !== null) {
            $content($tmp);
        }
        $kirby = annai_kirby($tmp, $options);
        $fn($kirby, $tmp);
    } finally {
        annai_remove_tree($tmp);
    }
}

/** The default fixture: home, Hofladen (listed), Kontakt (betrieb), Zum Shop (weiter), Impressum (unlisted), a draft. */
function rtFixture(string $tmp): void
{
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => 'Bio-Hofladen mit Café']);
    annai_page($tmp, '2_laden', 'default', ['title' => 'Hofladen', 'einleitung' => 'Gemüse vom eigenen Feld.']);
    annai_page($tmp, '3_kontakt', 'betrieb', [
        'title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt',
        'telefon' => '030 / 34567000', 'email' => 'hof@example.test',
    ]);
    annai_page($tmp, '4_weiter', 'weiter', ['title' => 'Zum Shop']);
    annai_page($tmp, 'impressum', 'rechtstext', ['title' => 'Impressum']);
    annai_page($tmp, '_entwurf', 'default', ['title' => 'Entwurf']);
}

/** Locations of a sitemap document. @return list<string> */
function rtLocs(string $xml): array
{
    preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches);
    return $matches[1];
}

/** Multilang fixture: languages de (default) and en, content in both for home and Hofladen, Kontakt only de. */
function rtMultilang(string $tmp): void
{
    @unlink($tmp . '/content/home/home.txt');
    mkdir($tmp . '/site/languages', 0700, true);
    file_put_contents($tmp . '/site/languages/de.php', "<?php\n\nreturn ['code' => 'de', 'default' => true, 'locale' => 'de_DE.utf8', 'name' => 'Deutsch', 'url' => 'http://example.invalid'];\n");
    file_put_contents($tmp . '/site/languages/en.php', "<?php\n\nreturn ['code' => 'en', 'default' => false, 'locale' => 'en_US.utf8', 'name' => 'English', 'url' => 'http://example.invalid/en'];\n");
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => 'Bio-Hofladen mit Café'], 'de');
    annai_page($tmp, 'home', 'home', ['title' => 'Start'], 'de');
    annai_page($tmp, 'home', 'home', ['title' => 'Start'], 'en');
    annai_page($tmp, '2_laden', 'default', ['title' => 'Hofladen', 'einleitung' => 'Gemüse vom eigenen Feld.'], 'de');
    annai_page($tmp, '2_laden', 'default', ['title' => 'Farm shop'], 'en');
    annai_page($tmp, '3_kontakt', 'betrieb', ['title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt'], 'de');
}

/** Koyomi fixture: calendar, one past and one upcoming event, venue. */
function rtKoyomi(string $tmp): void
{
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => 'Bio-Hofladen mit Café']);
    annai_page($tmp, '5_events', 'koyomi-calendar', ['title' => 'Termine']);
    annai_page($tmp, '5_events/20260201_silent-retreat', 'koyomi-event', [
        'title' => 'Schweigeretreat', 'startDate' => '2026-02-01', 'startTime' => '18:00',
        'endDate' => '2026-02-02', 'endTime' => '12:00', 'venue' => 'venues/sonnenhof',
        // A fixed UUID: without it Kirby writes one into the file on first read, which rewrites the file and resets its mtime.
        'Uuid' => 'qxaucheptmxlkz6y',
    ]);
    annai_page($tmp, '5_events/20270312_breathing', 'koyomi-event', [
        'title' => 'Atemseminar', 'startDate' => '2027-03-12', 'startTime' => '18:00',
        'endDate' => '2027-03-13', 'endTime' => '12:00', 'venue' => 'venues/sonnenhof',
    ]);
    annai_page($tmp, 'venues', 'koyomi-venues', ['title' => 'Orte']);
    annai_page($tmp, 'venues/sonnenhof', 'koyomi-venue', [
        'title' => 'Seminarhaus Beispielhof', 'street' => 'Seminarweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt',
    ]);
    // The past event was last edited long before it ended: the lastmod rule must lift it to its end.
    touch($tmp . '/content/5_events/20260201_silent-retreat/koyomi-event.txt', strtotime('2026-01-01 00:00:00 UTC'));
    clearstatcache();
}

/** rtKoyomi() on the multilang fixture, every Koyomi page with German content only. */
function rtKoyomiGerman(string $tmp): void
{
    rtKoyomi($tmp);
    rtMultilang($tmp);
    foreach (['5_events', '5_events/20260201_silent-retreat', '5_events/20270312_breathing', 'venues', 'venues/sonnenhof'] as $dir) {
        foreach (glob($tmp . '/content/' . $dir . '/*.txt') ?: [] as $file) {
            if (preg_match('#/[a-z-]+\.txt$#', $file) === 1) {
                rename($file, substr($file, 0, -4) . '.de.txt');
            }
        }
    }
}

/**
 * The routes and page selection with real Kirby (SPEC.md §9.9), in throwaway
 * site directories: robots.txt, sitemap.xml (+ chunks), llms.txt, the page
 * methods Pages::robots/alternates/lastmod, the multilang fixture and the
 * Koyomi lastmod rule and llms list (skipped without Koyomi).
 *
 *   ASDF_PHP_VERSION=8.5.3 vendor/bin/phpunit tests/RoutesTest.php
 *
 * The fake Sudare class below is the contract of SPEC.md §7:
 * Pages::preview() asks `Sudare\Sudare::active()`, on the CLI also
 * `Sudare\Sudare::protects()`. Declared here, so the
 * file uses braced namespace blocks (a file cannot mix them with a global
 * block).
 */
#[CoversNothing]
final class RoutesTest extends TestCase
{
    public function testSuite(): void
    {
        require_once __DIR__ . '/support/sudare-fake.php';

// ── Off ──────────────────────────────────────────────────────────────────
rtRun([], function (App $kirby): void {
    foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $path) {
        [$code, $body] = annai_request($kirby, $path);
        // Whatever Kirby answers (404 or its error page), none of Annai's output may be in it.
        holds(!str_contains($body, 'User-agent') && !str_contains($body, '<urlset') && !str_contains($body, '# Testhof'),
            "off: $path is not served by Annai (status $code)");
    }
    holds(Annai\Pages::all($kirby) === [], 'off: Pages::all() is []');
    holds(Annai\Pages::entries($kirby) === [], 'off: Pages::entries() is []');
}
, 'rtFixture');

// `'annai.active' => 'true'` (an env-driven config) used to switch Annai off in
// silence, although the README says a non-boolean option is logged.
rtRun(['annai.active' => 'true'], function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        expect(false, annai_active(), "active 'true': annai_active() is false");
        expect(false, Annai\Options::active($kirby), "active 'true': Options::active() is false");
        [$code, $body] = annai_request($kirby, 'robots.txt');
        holds(!str_contains($body, 'User-agent'), "active 'true': robots.txt falls through (status $code)");
    });
    holds(str_contains($log, 'Annai: annai.active must be true or false — using false.'), "active 'true': logged like every other boolean option");
}, 'rtFixture');

// ── Status codes, content types, HEAD and GET ───────────────────────────
rtRun(rtOn(), function (App $kirby): void {
    foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $path) {
        [$code] = annai_request($kirby, $path, 'HEAD');
        expect(200, $code, "HEAD $path is 200");
        [$code, $body, $type] = annai_request($kirby, $path, 'GET');
        expect(200, $code, "GET $path is 200");
        holds(str_starts_with((string) $type, $path === 'sitemap.xml' ? 'application/xml' : 'text/plain'),
            "GET $path has the right content type (" . $type . ')');
        holds($body !== '', "GET $path has a body");
    }
    [$code] = annai_request($kirby, 'sitemap-1.xml');
    expect(404, $code, 'sitemap-1.xml is 404 when the sitemap is one file');
}, 'rtFixture');

// ── robots.txt ───────────────────────────────────────────────────────────
rtRun(rtOn(), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    expect("User-agent: *\nAllow: /\nDisallow: /panel\n\nSitemap: http://example.invalid/sitemap.xml\n", $body,
        'robots.txt default: /panel and absolute Sitemap line from $site->url()');
}, 'rtFixture');

// A '*' in robots.agents would be a second, ineffective "User-agent: *" group.
rtRun(rtOn(['annai.robots.agents' => ['*' => false], 'debug' => false]), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    expect(1, substr_count($body, 'User-agent: *'), "robots.agents ['*' => false]: still one group for everyone");
}, 'rtFixture');

// An agent name is a product token. "x\nDisallow: /" would write a rule line
// of its own into robots.txt; "Fo#o" and "Claude Bot" are no names either.
rtRun(rtOn(['annai.robots.agents' => ["x\nDisallow: /" => true, 'Fo#o' => false, 'Claude Bot' => false, ' CCBot ' => false, 'Claude-User' => true, 'Google-Extended' => false, 'my_bot.v2' => false, "Bot\u{9B}31m" => false],
    'annai.disallow' => ['/panel', "x\u{9B}31m"]]),
    function (App $kirby, string $tmp): void {
        $log = annai_with_error_log($tmp, function () use ($kirby, &$body): void {
            [, $body] = annai_request($kirby, 'robots.txt');
        });
        expect(3, substr_count($body, "Disallow: /\n"), 'robots.agents: no injected "Disallow: /" line, only the groups of CCBot, Google-Extended and my_bot.v2');
        holds(!str_contains($body, "User-agent: x\n") && !str_contains($body, 'Fo#o') && !str_contains($body, 'Claude Bot'), 'robots.agents: names that are no token are left out');
        foreach (['CCBot', 'Google-Extended', 'my_bot.v2'] as $name) {
            holds(str_contains($body, "User-agent: $name\nDisallow: /\n"), "robots.agents: $name is a token and blocked");
        }
        holds(str_contains($body, "User-agent: Claude-User\nAllow: /\n"), 'robots.agents: Claude-User is a token and allowed');
        holds(str_contains($log, 'annai.robots.agents name "x\\nDisallow: /"') && str_contains($log, '"Fo#o"') && str_contains($log, '"Claude Bot"'), 'robots.agents: each bad name is reported, the line feed escaped');
        // Text::shown(): a C1 control character in a name or a disallow path is logged as an escape, never raw.
        holds(str_contains($log, 'Annai: annai.robots.agents name "Bot\\u009b31m" is not a user agent token'), 'robots.agents: a name with a C1 control is logged with the escape');
        holds(str_contains($log, 'Annai: annai.disallow entry "x\\u009b31m" is not a path'), 'annai.disallow: a path with a C1 control is logged with the escape');
        holds(!str_contains($log, "\u{9B}"), 'robots.agents and annai.disallow: no raw C1 character in the log');
    }, 'rtFixture');
foreach (array_keys(Annai\Robots::AI_AGENTS) as $name) {
    holds(preg_match('/^[A-Za-z0-9_.-]+$/D', $name) === 1, "Robots::AI_AGENTS: $name is a valid token");
}

rtRun(rtOn(['annai.disallow' => ['/panel', '/anruf']]), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    holds(str_contains($body, "Disallow: /panel\nDisallow: /anruf\n"), 'robots.txt: annai.disallow lists every path');
}, 'rtFixture');

rtRun(rtOn(['annai.robots.ai' => 'blockTraining']), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    holds(str_contains($body, "User-agent: GPTBot\nDisallow: /\n"), 'robots.txt: blockTraining blocks GPTBot');
    holds(!str_contains($body, 'User-agent: ChatGPT-User'), 'robots.txt: blockTraining leaves ChatGPT-User out');
}, 'rtFixture');

rtRun(rtOn(['annai.robots.agents' => ['Claude-User' => false]]), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    holds(str_contains($body, "User-agent: Claude-User\nDisallow: /\n"), 'robots.txt: robots.agents overrides one agent');
}, 'rtFixture');

rtRun(rtOn(['annai.robots.extra' => ['# Hinweis', 'Crawl-delay: 5']]), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    holds(str_contains($body, "# Hinweis\nCrawl-delay: 5\n"), 'robots.txt: robots.extra lines appended');
}, 'rtFixture');

// An annai.robots.extra element with line feeds inside is read line by line,
// so a group written into one string is a group of its own and does not land in the
// * group next to blockTraining's agent groups.
rtRun(rtOn(['annai.robots.extra' => ["# reason\nUser-agent: Googlebot\nDisallow: /", 'Crawl-delay: 5'], 'annai.robots.ai' => 'blockTraining']), function (App $kirby): void {
    expect(['# reason', 'User-agent: Googlebot', 'Disallow: /', 'Crawl-delay: 5'], Annai\Options::robotsExtra($kirby), 'robots.extra: an element with line feeds is split into lines');
    [, $body] = annai_request($kirby, 'robots.txt');
    holds(str_starts_with($body, "User-agent: *\nAllow: /\nDisallow: /panel\n# reason\n\nUser-agent: AI2Bot\nDisallow: /\n"), 'robots.extra with line feeds: the * group holds the comment only, then the agent groups');
    holds(str_contains($body, "\n\nUser-agent: Googlebot\nDisallow: /\nCrawl-delay: 5\n\nSitemap: "), 'robots.extra with line feeds: Googlebot is a group of its own after the agent groups');
    expect(1, substr_count($body, 'User-agent: Googlebot'), 'robots.extra with line feeds: Googlebot once');
}, 'rtFixture');

// A byte that is not UTF-8 in annai.disallow or annai.robots.extra used to make
// robots.txt a non-UTF-8 file (RFC 9309 §2.2 requires UTF-8); every other output
// scrubs such bytes. The path is logged and left out like any other invalid path,
// the extra line is scrubbed to U+FFFD.
rtRun(rtOn(['annai.disallow' => ['/panel', "/caf\xE9"], 'annai.robots.extra' => ["# Grund: caf\xE9 \xFF"]]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby, &$body): void {
        [, $body] = annai_request($kirby, 'robots.txt');
    });
    expect(true, mb_check_encoding($body, 'UTF-8'), 'robots.txt: valid UTF-8 with a Latin-1 byte in disallow and extra');
    holds(str_contains($body, "Disallow: /panel\n") && !str_contains($body, "/caf"), 'robots.txt: the path that is not UTF-8 is left out');
    holds(str_contains($body, "# Grund: caf\u{FFFD} \u{FFFD}\n"), 'robots.txt: the extra line is written with U+FFFD for each bad byte');
    holds(str_contains($log, 'Annai: annai.disallow entry "/caf' . "\u{FFFD}" . '" is not a path'), 'robots.txt: the path that is not UTF-8 is logged');
    $warnings = Annai\Robots::warnings(Annai\Routes::robotsConfig($kirby));
    expect(1, count($warnings), 'robots warnings: the path that is not UTF-8 is named once');
    holds(str_contains($warnings[0] ?? '', 'Disallow: "/caf' . "\u{FFFD}" . '" left out'), 'robots warnings: the warning names the path with U+FFFD');
}, 'rtFixture');

// After the UTF-8 scrub the lines used to be split with `\R` in byte mode,
// which also matches NEL = 0x85, the second byte of "Å" (C3 85) and "内" (E5 86 85):
// the rule "Disallow: /Åland/" became "Disallow: /\xC3" plus a junk line.
rtRun(rtOn(['annai.robots.extra' => ["# Filialen: Åland, 案内\nDisallow: /Åland/", 'Disallow: /Ņ/']]), function (App $kirby): void {
    expect(['# Filialen: Åland, 案内', 'Disallow: /Åland/', 'Disallow: /Ņ/'], Annai\Options::robotsExtra($kirby), 'robots.extra: a line feed splits, a 0x85 byte inside a character does not');
    [, $body] = annai_request($kirby, 'robots.txt');
    expect(true, mb_check_encoding($body, 'UTF-8'), 'robots.txt: UTF-8 with "Å" and "案内" in robots.extra');
    holds(str_contains($body, "# Filialen: Åland, 案内\nDisallow: /Åland/\nDisallow: /Ņ/\n"), 'robots.txt: the rules with "Å" and "Ņ" are whole lines');
    holds(!str_contains($body, "\nland/"), 'robots.txt: no junk line from a character cut in two');
    expect('/Åland/', Annai\Robots::parse($body)['groups'][0]['rules'][2]['path'] ?? null, 'robots.txt: the parser reads the path whole');
}, 'rtFixture');

rtRun(rtOn(['annai.noindex' => true]), function (App $kirby): void {
    [, $body] = annai_request($kirby, 'robots.txt');
    expect("User-agent: *\nDisallow: /\n", $body, 'robots.txt with annai.noindex true: Disallow / only');
}, 'rtFixture');

// ── sitemap.xml ──────────────────────────────────────────────────────────
rtRun(rtOn(), function (App $kirby): void {
    [, $xml, $type] = annai_request($kirby, 'sitemap.xml');
    holds(str_starts_with((string) $type, 'application/xml'), 'sitemap.xml is application/xml');
    $locs = rtLocs($xml);
    holds(in_array('http://example.invalid', $locs, true), 'sitemap: home page');
    holds(in_array('http://example.invalid/laden', $locs, true), 'sitemap: listed page');
    holds(in_array('http://example.invalid/impressum', $locs, true), 'sitemap: unlisted page (Impressum) is in the sitemap');
    holds(in_array('http://example.invalid/weiter', $locs, true), 'sitemap: weiter is in the sitemap without exclude.templates');
    holds(!in_array('http://example.invalid/error', $locs, true), 'sitemap: error template excluded by default');
    holds(!in_array('http://example.invalid/entwurf', $locs, true), 'sitemap: draft missing');
    preg_match_all('#<lastmod>(.*?)</lastmod>#', $xml, $lastmods);
    holds(count($lastmods[1]) === substr_count($xml, '<url>') && $lastmods[1] !== [], 'sitemap: every url has a lastmod');
    holds(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $lastmods[1][0] ?? '') === 1,
        'sitemap: lastmod is W3C-shaped (' . ($lastmods[1][0] ?? 'none') . ')');
}, 'rtFixture');

rtRun(rtOn(['annai.exclude.templates' => ['error', 'weiter'], 'annai.exclude.pages' => ['impressum']]), function (App $kirby): void {
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    $locs = rtLocs($xml);
    holds(!in_array('http://example.invalid/weiter', $locs, true), 'sitemap: excluded template missing');
    holds(!in_array('http://example.invalid/impressum', $locs, true), 'sitemap: excluded page id missing');
    holds(in_array('http://example.invalid/laden', $locs, true), 'sitemap: other pages stay');
}, 'rtFixture');

rtRun(rtOn(['annai.exclude.pages' => fn (Page $page): bool => $page->id() === 'laden']), function (App $kirby): void {
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    holds(!in_array('http://example.invalid/laden', rtLocs($xml), true), 'sitemap: exclude.pages Closure missing');
}, 'rtFixture');

// A Closure that answers 1 (truthy, not true) excludes as well.
rtRun(rtOn(['annai.exclude.pages' => fn (Page $page): int => $page->id() === 'laden' ? 1 : 0]), function (App $kirby): void {
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    holds(!in_array('http://example.invalid/laden', rtLocs($xml), true), 'sitemap: exclude.pages Closure returning 1 excludes');
    holds(in_array('http://example.invalid/kontakt', rtLocs($xml), true), 'sitemap: exclude.pages Closure returning 0 keeps the page');
}, 'rtFixture');

// An excluded id keeps its subpages out too (not only the exact id).
rtRun(rtOn(['annai.exclude.pages' => ['intern'], 'annai.language' => 'de']), function (App $kirby): void {
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    $locs = rtLocs($xml);
    holds(!in_array('http://example.invalid/intern', $locs, true), 'sitemap: exclude.pages id missing');
    holds(!in_array('http://example.invalid/intern/protokoll-vorstand', $locs, true), 'sitemap: exclude.pages keeps the subpage of the id out');
    holds(in_array('http://example.invalid/internat', $locs, true), 'sitemap: exclude.pages matches whole path segments (internat stays)');
    expect('noindex, follow', Annai\Pages::robots($kirby, $kirby->page('intern/protokoll-vorstand')), 'robots meta: subpage of an excluded id → noindex, follow');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '5_intern', 'default', ['title' => 'Intern']);
    annai_page($tmp, '5_intern/protokoll-vorstand', 'default', ['title' => 'Protokoll']);
    annai_page($tmp, '6_internat', 'default', ['title' => 'Internat']);
});

// annai.disallow entries that are no paths are logged, left out of robots.txt
// and reported by the check: "panel" has no effect per RFC 9309, "#"
// starts a comment, a line feed would write a rule of its own.
rtRun(rtOn(['annai.disallow' => ['panel', '/intern#geheim', "/a\nAllow: /b", '/panel']]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        [, $body] = annai_request($kirby, 'robots.txt');
        expect("User-agent: *\nAllow: /\nDisallow: /panel\n\nSitemap: http://example.invalid/sitemap.xml\n", $body, 'annai.disallow: only the valid path is written');
        $warnings = Annai\Check::site($kirby)['robots']['warnings'];
        expect(3, count($warnings), 'annai.disallow: the check warns once per invalid path');
        holds(str_contains($warnings[0] ?? '', 'Disallow: "panel"'), 'annai.disallow: the check names the invalid path');
    });
    holds(str_contains($log, 'annai.disallow') && str_contains($log, '"panel"') && str_contains($log, 'left out'), 'annai.disallow: invalid paths are logged');
}, 'rtFixture');

// annai.disallow with "/" closes the site for everyone: the * group is "Disallow: /"
// alone, because next to "Allow: /" the Allow would win (RFC 9309 §2.2.2) and the
// check's "Disallow: / for everyone" would be untrue. A space is no path character:
// "/my path" is logged and left out like "panel".
rtRun(rtOn(['annai.disallow' => ['/', '/my path'], 'annai.robots.agents' => ['ChatGPT-User' => true]]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        [, $body] = annai_request($kirby, 'robots.txt');
        expect("User-agent: *\nDisallow: /\n\nUser-agent: ChatGPT-User\nAllow: /\n\nSitemap: http://example.invalid/sitemap.xml\n", $body,
            'annai.disallow "/": the * group blocks without an Allow: /, the allowed agent stays allowed, the sitemap line stays');
        expect([['type' => 'disallow', 'path' => '/']], Annai\Robots::parse($body)['groups'][0]['rules'] ?? null, 'annai.disallow "/": the parsed * group has the one rule');
        $warnings = Annai\Check::site($kirby)['robots']['warnings'];
        expect(2, count($warnings), 'annai.disallow "/" and "/my path": two warnings');
        expect('Disallow: / for everyone', $warnings[0] ?? null, 'annai.disallow "/": the check warns, and now the file does what it says');
        holds(str_contains($warnings[1] ?? '', '"/my path"') && str_contains($warnings[1] ?? '', 'no space'), 'annai.disallow "/my path": the check names the space');
    });
    holds(str_contains($log, '"/my path"') && str_contains($log, 'no space'), 'annai.disallow "/my path": logged with the rule');
}, 'rtFixture');

// annai.descriptionLength that is not a whole number from 20 to 320 used to be
// silently 20 ('abc' → (int) 0 → max(20, 0)); it is logged and 155 is used.
foreach ([['abc', 155], [5, 20], [1000, 320], ['200', 155], [null, 155], [200, 200]] as [$length, $expected]) {
    rtRun(rtOn(['annai.descriptionLength' => $length]), function (App $kirby, string $tmp) use ($length, $expected): void {
        $shown = var_export($length, true);
        $log = annai_with_error_log($tmp, function () use ($kirby, $expected, $shown): void {
            expect($expected, Annai\Options::descriptionLength($kirby), "descriptionLength $shown → $expected");
        });
        if ($expected !== $length && $length !== null) {
            holds(str_contains($log, 'annai.descriptionLength') && str_contains($log, $shown), "descriptionLength $shown is logged");
        } else {
            expect('', $log, "descriptionLength $shown: nothing logged");
        }
    }, 'rtFixture');
}
rtRun(rtOn(['annai.descriptionLength' => 'abc']), function (App $kirby): void {
    [, $html] = annai_request($kirby, 'laden');
    holds(str_contains($html, '<meta name="description" content="Gemüse vom eigenen Feld.">'), "descriptionLength 'abc': the description is excerpted at the default, not at 20");
}, 'rtFixture');

rtRun(rtOn(['annai.sitemap.lastmod' => false]), function (App $kirby): void {
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    holds(!str_contains($xml, '<lastmod>'), 'sitemap: sitemap.lastmod false → no lastmod');
}, 'rtFixture');

rtRun(rtOn(['annai.sitemap.extra' => [['loc' => 'https://extern.example/seite', 'lastmod' => '2027-01-01T00:00:00+00:00']]]),
    function (App $kirby): void {
        [, $xml] = annai_request($kirby, 'sitemap.xml');
        holds(in_array('https://extern.example/seite', rtLocs($xml), true), 'sitemap: sitemap.extra list entry');
    }, 'rtFixture');

// Broken extra entries are dropped or lose their lastmod, logged; the file stays valid XML.
rtRun(rtOn(['annai.sitemap.extra' => [
    ['loc' => "https://extern.example/a\x01b"],
    ['loc' => "https://extern.example/caf\xE9"],
    ['loc' => 'extern.example/ohne-schema'],
    ['loc' => 'https://extern.example/gestern', 'lastmod' => 'gestern'],
    ['loc' => 'https://extern.example/gut', 'lastmod' => '2027-01-01'],
]]), function (App $kirby, string $tmp): void {
    $xml = '';
    $log = annai_with_error_log($tmp, function () use ($kirby, &$xml): void {
        [, $xml] = annai_request($kirby, 'sitemap.xml');
    });
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml);
    libxml_use_internal_errors($previous);
    holds($parsed !== false, 'sitemap.extra with control character, invalid UTF-8 and bad lastmod: well-formed XML');
    $locs = rtLocs($xml);
    holds(!in_array('', $locs, true), 'sitemap.extra: no empty <loc>');
    holds(array_filter($locs, fn ($loc) => str_contains($loc, 'extern.example/a') || str_contains($loc, 'caf') || str_contains($loc, 'ohne-schema')) === [],
        'sitemap.extra: entries with an unusable loc are dropped');
    holds(str_contains($xml, "<loc>https://extern.example/gestern</loc>\n  </url>"), 'sitemap.extra: an invalid lastmod is left out, the entry stays');
    holds(!str_contains($xml, '<lastmod>gestern</lastmod>'), 'sitemap.extra: "gestern" is never emitted');
    holds(str_contains($xml, "<loc>https://extern.example/gut</loc>\n    <lastmod>2027-01-01</lastmod>"), 'sitemap.extra: a W3C date lastmod is kept');
    holds(str_contains($log, 'annai.sitemap.extra entry without an absolute http(s) loc skipped'), 'sitemap.extra: a dropped entry is logged');
    holds(str_contains($log, 'lastmod of https://extern.example/gestern is not a W3C datetime'), 'sitemap.extra: a dropped lastmod is logged');
}, 'rtFixture');

rtRun(rtOn(['annai.sitemap.extra' => fn (): array => [['loc' => 'https://extern.example/closure']]]),
    function (App $kirby): void {
        [, $xml] = annai_request($kirby, 'sitemap.xml');
        holds(in_array('https://extern.example/closure', rtLocs($xml), true), 'sitemap: sitemap.extra Closure entry');
    }, 'rtFixture');

rtRun(rtOn(['annai.sitemap.chunk' => 2, 'annai.exclude.templates' => ['error', 'weiter'], 'annai.exclude.pages' => ['impressum']]),
    function (App $kirby): void {
        [$code, $index, $type] = annai_request($kirby, 'sitemap.xml');
        expect(200, $code, 'chunked: sitemap.xml is 200');
        holds(str_starts_with((string) $type, 'application/xml'), 'chunked: sitemap.xml is application/xml');
        holds(str_contains($index, '<sitemapindex') && substr_count($index, '<sitemap>') === 2, 'chunked: sitemap.xml is a sitemapindex with 2 files');
        holds(str_contains($index, 'http://example.invalid/sitemap-1.xml') && str_contains($index, 'http://example.invalid/sitemap-2.xml'),
            'chunked: index names sitemap-1.xml and sitemap-2.xml');
        [$code, $one] = annai_request($kirby, 'sitemap-1.xml');
        expect(200, $code, 'chunked: sitemap-1.xml is 200');
        expect(2, substr_count($one, '<url>'), 'chunked: sitemap-1.xml has 2 urls');
        [$code, $two] = annai_request($kirby, 'sitemap-2.xml');
        expect(200, $code, 'chunked: sitemap-2.xml is 200');
        expect(1, substr_count($two, '<url>'), 'chunked: sitemap-2.xml has 1 url');
        [$code] = annai_request($kirby, 'sitemap-3.xml');
        expect(404, $code, 'chunked: sitemap-3.xml is 404');
        // Only the canonical number answers: sitemap-01.xml and sitemap-0001.xml
        // used to be duplicates of chunk 1 under different URLs; sitemap-1Xxml
        // too, through an unescaped "." in the route pattern.
        foreach (['sitemap-01.xml', 'sitemap-0001.xml', 'sitemap-0.xml', 'sitemap--1.xml', 'sitemap-1Xxml', 'sitemap-1_xml'] as $path) {
            [$code] = annai_request($kirby, $path);
            expect(404, $code, "chunked: $path is 404 (not a canonical chunk number)");
        }
    }, 'rtFixture');

// annai.sitemap.chunk: only a whole number from 1 to 50,000. false, 'abc' and 0
// became (int) 0 → max(1, …) = one URL per file; 100,000 is above the limit.
foreach ([[false, 'false'], ['abc', "'abc'"], [0, '0'], ['100', "'100'"], [100000, '100000']] as [$chunk, $shown]) {
    rtRun(rtOn(['annai.sitemap.chunk' => $chunk]), function (App $kirby, string $tmp) use ($chunk, $shown): void {
        $log = annai_with_error_log($tmp, function () use ($kirby, &$xml): void {
            [, $xml] = annai_request($kirby, 'sitemap.xml');
        });
        holds(str_contains($xml, '<urlset') && !str_contains($xml, '<sitemapindex'), "sitemap.chunk $shown: one <urlset>, no index");
        expect(5, substr_count($xml, '<url>'), "sitemap.chunk $shown: every page in the one file");
        expect(Annai\Sitemap::MAX_URLS, Annai\Options::sitemapChunk($kirby), "sitemap.chunk $shown: " . Annai\Sitemap::MAX_URLS . ' per file');
        holds(str_contains($log, 'annai.sitemap.chunk') && str_contains($log, $shown), "sitemap.chunk $shown: reported");
    }, 'rtFixture');
}

// The index lastmod of a chunk is its newest entry. A year alone ("2000") is a
// W3C datetime, but DateTimeImmutable reads it as 20:00 today, and "1999-12" is
// no date for it at all: both are read as the first day of the year or month.
rtRun(rtOn(['annai.sitemap.chunk' => 2, 'annai.exclude.pages' => ['home'], 'annai.sitemap.extra' => [
    ['loc' => 'https://extern.example/jahr', 'lastmod' => '2000'],
    ['loc' => 'https://extern.example/tag', 'lastmod' => '2001-01-01'],
    ['loc' => 'https://extern.example/monat', 'lastmod' => '1999-12'],
    ['loc' => 'https://extern.example/juni', 'lastmod' => '1999-06-30'],
]]), function (App $kirby): void {
    [, $index] = annai_request($kirby, 'sitemap.xml');
    preg_match_all('#<sitemap><loc>[^<]*</loc>(?:<lastmod>([^<]*)</lastmod>)?</sitemap>#', preg_replace('/\s+/', '', $index), $lastmods);
    expect(['2001-01-01', '1999-12'], $lastmods[1], 'sitemap index: "2001-01-01" is newer than "2000", "1999-12" newer than "1999-06-30"');
});

// ── Page methods: robots meta, Sudare preview ───────────────────────────
rtRun(rtOn(), function (App $kirby): void {
    $error = $kirby->page('error');
    holds($error instanceof Page, 'fixture has the error page');
    expect('noindex, follow', Annai\Pages::robots($kirby, $error), 'robots meta: excluded template → noindex, follow');
    expect(null, Annai\Pages::robots($kirby, $kirby->site()->homePage()), 'robots meta: home page → null');
}, 'rtFixture');

rtRun(rtOn(['annai.noindexExcluded' => false]), function (App $kirby): void {
    expect(null, Annai\Pages::robots($kirby, $kirby->page('error')), 'robots meta: noindexExcluded false → null');
}, 'rtFixture');

rtRun(rtOn(['annai.noindex' => true]), function (App $kirby): void {
    expect('noindex, nofollow, noarchive', Annai\Pages::robots($kirby, $kirby->site()->homePage()), 'robots meta: annai.noindex true → preview');
}, 'rtFixture');

rtRun(rtOn(['annai.noindex' => false]), function (App $kirby): void {
    Sudare\Sudare::$on = true;
    try {
        expect(false, Annai\Pages::preview($kirby), 'preview: annai.noindex false wins over Sudare');
    } finally {
        Sudare\Sudare::$on = false;
    }
}, 'rtFixture');

rtRun(rtOn(), function (App $kirby): void {
    Sudare\Sudare::$on = true;
    try {
        expect('noindex, nofollow, noarchive', Annai\Pages::robots($kirby, $kirby->site()->homePage()), 'preview: Sudare active → noindex on every page');
        // Sudare answers unauthenticated requests before Kirby renders: the robots.txt that
        // reaches Annai is the normal one (Sitemap line, no Disallow: /), not the hidden one.
        [, $body] = annai_request($kirby, 'robots.txt');
        holds(in_array('Sitemap: http://example.invalid/sitemap.xml', explode("\n", $body), true), 'preview: Sudare active → robots.txt keeps its Sitemap line');
        holds(!in_array('Disallow: /', explode("\n", $body), true), 'preview: Sudare active → robots.txt is not Disallow: / (the normal file)');
        expect(false, Annai\Pages::hidden($kirby), 'preview: Sudare active → not hidden');
    } finally {
        Sudare\Sudare::$on = false;
    }
    expect(false, Annai\Pages::preview($kirby), 'preview: Sudare inactive → false (auto)');
    expect(false, Annai\Pages::hidden($kirby), 'preview: Sudare inactive → not hidden');
    expect(null, Annai\Pages::robots($kirby, $kirby->site()->homePage()), 'preview: Sudare inactive → no robots meta on home');
}, 'rtFixture');

// Pages::hidden() is the explicit annai.noindex => true only: it implies the preview,
// a protected preview alone is not hidden.
rtRun(rtOn(['annai.noindex' => true]), function (App $kirby): void {
    expect(true, Annai\Pages::hidden($kirby), 'hidden: annai.noindex true → hidden');
    expect(true, Annai\Pages::preview($kirby), 'hidden: annai.noindex true → also a preview');
}, 'rtFixture');

rtRun(rtOn(['annai.noindex' => false]), function (App $kirby): void {
    Sudare\Sudare::$on = true;
    try {
        expect(false, Annai\Pages::hidden($kirby), 'hidden: annai.noindex false → not hidden');
    } finally {
        Sudare\Sudare::$on = false;
    }
}, 'rtFixture');

// Kirby's page cache kept the preview noindex after the preview had ended: a
// page rendered while Sudare protected the site was served from the cache
// with "noindex, nofollow, noarchive" by the next App instance. While
// the preview is on, the response is not cached (as Noren does for its times).
$cached = rtOn(['cache' => ['pages' => ['active' => true]]]);
rtRun($cached, function (App $kirby, string $tmp) use ($cached): void {
    Sudare\Sudare::$on = true;
    try {
        [$code, $html] = annai_request($kirby, 'laden');
        expect(200, $code, 'page cache + preview: laden renders');
        holds(str_contains($html, '<meta name="robots" content="noindex, nofollow, noarchive">'), 'page cache + preview: laden carries noindex while protected');
        expect(false, $kirby->response()->cache(), 'page cache + preview: the response is not cached');
    } finally {
        Sudare\Sudare::$on = false;
    }
    $later = annai_kirby($tmp, $cached);
    [$code, $html] = annai_request($later, 'laden');
    expect(200, $code, 'page cache after the preview: laden renders');
    holds(!str_contains($html, 'noindex'), 'page cache after the preview: a new request has no noindex');
    expect(true, $later->response()->cache(), 'page cache after the preview: the response is cacheable again');
}, 'rtFixture');

// A protected preview (Sudare answers 401 before Kirby renders) serves sitemap.xml and
// llms.txt as they are after the go-live; the responses carry Cache-Control: no-store.
rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    Sudare\Sudare::$on = true;
    try {
        [$code, $xml] = annai_request($kirby, 'sitemap.xml');
        expect(200, $code, 'preview: sitemap.xml answers');
        holds(str_contains($xml, '<urlset'), 'preview: sitemap.xml is a urlset');
        expect(count(Annai\Pages::entries($kirby)), count(rtLocs($xml)), 'preview: sitemap.xml lists the entries, as after the go-live');
        holds(rtLocs($xml) !== [], 'preview: sitemap.xml is not empty');
        [$code, $md] = annai_request($kirby, 'llms.txt');
        expect(200, $code, 'preview: llms.txt answers');
        holds(str_starts_with($md, "# Testhof\n") && str_contains($md, "\n## Seiten\n"), 'preview: llms.txt is the full file (sections), not the H1 only');
        // A CDN or proxy in front must not keep the closed robots.txt or the empty
        // sitemap past the go-live when a site exempts them from the protection.
        foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $path) {
            expect('no-store', $kirby->render($path)?->headers()['Cache-Control'] ?? null, "preview: $path is sent with Cache-Control: no-store");
        }
    } finally {
        Sudare\Sudare::$on = false;
    }
    foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $path) {
        holds(!array_key_exists('Cache-Control', $kirby->render($path)?->headers() ?? []), "no preview: $path sets no Cache-Control");
    }
}, 'rtFixture');

// A protected preview keeps the routes its configuration exempts closed: Sudare lets
// such a request through without credentials, so the file is not behind the lock.
rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    try {
        Sudare\Sudare::$on = true;
        Sudare\Sudare::$exempt = ['/robots.txt'];
        [, $body] = annai_request($kirby, 'robots.txt');
        expect("User-agent: *\nDisallow: /\n", $body, 'exempt robots.txt: closed while Sudare exempts it');
        [, $xml] = annai_request($kirby, 'sitemap.xml');
        holds(rtLocs($xml) !== [], 'exempt robots.txt: sitemap.xml is not exempt, so it lists the pages');
        [, $md] = annai_request($kirby, 'llms.txt');
        holds(str_contains($md, "\n## Seiten\n"), 'exempt robots.txt: llms.txt is not exempt, so it is the full file');

        Sudare\Sudare::$exempt = ['/sitemap.xml'];
        [, $xml] = annai_request($kirby, 'sitemap.xml');
        expect([], rtLocs($xml), 'exempt sitemap.xml: an empty urlset');
        holds(str_contains($xml, '<urlset'), 'exempt sitemap.xml: still a urlset');
        [, $body] = annai_request($kirby, 'robots.txt');
        holds(!in_array('Disallow: /', explode("\n", $body), true), 'exempt sitemap.xml: robots.txt is the normal file');

        Sudare\Sudare::$exempt = ['/llms.txt'];
        [, $md] = annai_request($kirby, 'llms.txt');
        expect("# Testhof\n", $md, 'exempt llms.txt: the H1 only');

        Sudare\Sudare::$exempt = ['/robots.txt', '/sitemap.xml', '/llms.txt'];
        foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $path) {
            expect('no-store', $kirby->render($path)?->headers()['Cache-Control'] ?? null, "exempt $path: still Cache-Control: no-store");
        }
        expect(true, Annai\Pages::hidden($kirby, '/robots.txt'), 'exempt: hidden() for the exempted route');
        expect(false, Annai\Pages::hidden($kirby), 'exempt: no path → only the explicit setting counts');
    } finally {
        Sudare\Sudare::$on = false;
        Sudare\Sudare::$exempt = [];
    }
    // Exempt but not protected (no guard ran, the configuration is null): nothing is closed.
    Sudare\Sudare::$exempt = ['/robots.txt'];
    try {
        [, $body] = annai_request($kirby, 'robots.txt');
        holds(!in_array('Disallow: /', explode("\n", $body), true), 'exempt without a protection: robots.txt unchanged');
        holds(!array_key_exists('Cache-Control', $kirby->render('robots.txt')?->headers() ?? []), 'exempt without a protection: no Cache-Control');
        expect(false, Annai\Pages::hidden($kirby, '/robots.txt'), 'exempt without a protection: not hidden');
    } finally {
        Sudare\Sudare::$exempt = [];
    }
}, 'rtFixture');

// A protected preview exempting one chunk closes that chunk only.
rtRun(rtOn(['annai.language' => 'de', 'annai.sitemap.chunk' => 1]), function (App $kirby): void {
    Sudare\Sudare::$on = true;
    Sudare\Sudare::$exempt = ['/sitemap-1.xml'];
    try {
        expect(404, $kirby->render('sitemap-1.xml')?->code(), 'exempt chunk: sitemap-1.xml closed (404)');
        expect(200, $kirby->render('sitemap-2.xml')?->code(), 'exempt chunk: sitemap-2.xml not exempt, answers');
        expect(200, $kirby->render('sitemap.xml')?->code(), 'exempt chunk: the index is not exempt');
    } finally {
        Sudare\Sudare::$on = false;
        Sudare\Sudare::$exempt = [];
    }
}, 'rtFixture');

// An explicit annai.noindex => true hides the files, with no protection in front:
// an empty urlset and the llms.txt H1 only, with Cache-Control: no-store.
rtRun(rtOn(['annai.language' => 'de', 'annai.noindex' => true]), function (App $kirby): void {
    [$code, $xml] = annai_request($kirby, 'sitemap.xml');
    expect(200, $code, 'hidden: sitemap.xml answers');
    expect([], rtLocs($xml), 'hidden: sitemap.xml is an empty urlset');
    holds(str_contains($xml, '<urlset'), 'hidden: sitemap.xml is still a urlset');
    [$code, $md] = annai_request($kirby, 'llms.txt');
    expect(200, $code, 'hidden: llms.txt answers');
    expect("# Testhof\n", $md, 'hidden: llms.txt is the H1 only');
    holds(Annai\Pages::entries($kirby) !== [], 'hidden: Pages::entries() still has the entries (data method)');
    foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $path) {
        expect('no-store', $kirby->render($path)?->headers()['Cache-Control'] ?? null, "hidden: $path is sent with Cache-Control: no-store");
    }
}, 'rtFixture');

// The chunk files carry the header too (a chunked sitemap, preview switched on by option).
rtRun(rtOn(['annai.noindex' => true, 'annai.sitemap.chunk' => 2]), function (App $kirby): void {
    expect('no-store', $kirby->render('sitemap.xml')?->headers()['Cache-Control'] ?? null, 'preview: a chunked sitemap.xml is sent with Cache-Control: no-store');
    expect(404, $kirby->render('sitemap-1.xml')?->code(), 'preview: the chunk files are 404 (nothing to list)');
    $response = Annai\Routes::sitemap($kirby, 1);
    holds($response === null, 'preview: Routes::sitemap(1) is null');
}, 'rtFixture');

// The preview H1 is the name as render() writes it: no HTML, no link.
rtRun(rtOn(['annai.noindex' => true]), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    expect("# Hof (x)(javascript:alert(1))\n", $md, 'hidden: llms.txt H1 without HTML and without a link');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => '<img src=x onerror=alert(1)>Hof [x](javascript:alert(1))']);
});

// Without a brand field and a site title the H1 used to be "# " alone, in the preview
// file as in the full one; the host of Kirby's base URL is the fallback.
$rtNoName = function (string $tmp): void {
    annai_page($tmp, '', 'site', ['beschreibung' => '']);
    annai_page($tmp, '2_laden', 'default', ['title' => 'Hofladen']);
};
rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_starts_with($md, "# example.invalid\n\n## Seiten\n"), 'no site name: the llms.txt H1 is the host');
    Sudare\Sudare::$on = true;
    try {
        [, $md] = annai_request($kirby, 'llms.txt');
        holds(str_starts_with($md, "# example.invalid\n\n## Seiten\n"), 'no site name + protected preview: the full file carries the host as H1');
    } finally {
        Sudare\Sudare::$on = false;
    }
}, $rtNoName);
rtRun(rtOn(['annai.language' => 'de', 'annai.noindex' => true]), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    expect("# example.invalid\n", $md, 'no site name + hidden: the H1-only file carries the host too');
}, $rtNoName);

// A value that is not a bool (env-driven config) is reported and counts as
// automatic: it must not switch Sudare's noindex off.
foreach (['true', 1, 'auto'] as $value) {
    rtRun(rtOn(['annai.noindex' => $value]), function (App $kirby, string $tmp) use ($value): void {
        Sudare\Sudare::$on = true;
        try {
            $robots = null;
            $log = annai_with_error_log($tmp, function () use ($kirby, &$robots): void {
                $robots = Annai\Pages::robots($kirby, $kirby->site()->homePage());
            });
            expect('noindex, nofollow, noarchive', $robots, 'annai.noindex ' . var_export($value, true) . ' + Sudare active → still noindex');
            holds(str_contains($log, 'annai.noindex must be true, false or null'), 'annai.noindex ' . var_export($value, true) . ' is logged');
        } finally {
            Sudare\Sudare::$on = false;
        }
        expect(false, Annai\Pages::preview($kirby), 'annai.noindex ' . var_export($value, true) . ' + Sudare inactive → automatic, false');
        expect(false, Annai\Pages::hidden($kirby), 'annai.noindex ' . var_export($value, true) . ' is never hidden');
    }, 'rtFixture');
}

// On the CLI no request ran Sudare's guard: protects($base) answers.
rtRun(rtOn(), function (App $kirby, string $tmp): void {
    Sudare\Sudare::$protects = true;
    try {
        expect(true, Annai\Pages::preview($kirby), 'preview on the CLI: Sudare::protects() true → preview');
        expect($tmp, Sudare\Sudare::$base, 'preview on the CLI: protects() gets the site base directory');
        [, $body] = annai_request($kirby, 'robots.txt');
        holds(!in_array('Disallow: /', explode("\n", $body), true), 'preview on the CLI: robots.txt is the normal file, not Disallow: /');
        expect(false, Annai\Pages::hidden($kirby), 'preview on the CLI: protects() true → not hidden');
    } finally {
        Sudare\Sudare::$protects = false;
    }
    expect(false, Annai\Pages::preview($kirby), 'preview on the CLI: protects() false → no preview');
}, 'rtFixture');

// Drafts (preview links) are noindex, nofollow whatever noindexExcluded says.
foreach ([true, false] as $flag) {
    rtRun(rtOn(['annai.noindexExcluded' => $flag]), function (App $kirby) use ($flag): void {
        $draft = $kirby->site()->drafts()->find('vorschau');
        holds($draft instanceof Page && $draft->isDraft(), 'fixture has a real draft');
        expect('noindex, nofollow', $draft instanceof Page ? Annai\Pages::robots($kirby, $draft) : null,
            'robots meta: draft → noindex, nofollow (noindexExcluded ' . var_export($flag, true) . ')');
    }, function (string $tmp): void {
        rtFixture($tmp);
        annai_page($tmp, '_drafts/vorschau', 'default', ['title' => 'Vorschau']);
    });
}

// A page below a draft is as unpublished as the draft (an anonymous request
// is 404; an editor's preview renders it), but Kirby's isDraft() is false for it: it
// used to get no robots meta and count as indexable.
foreach ([true, false] as $flag) {
    rtRun(rtOn(['annai.noindexExcluded' => $flag]), function (App $kirby) use ($flag): void {
        $child = $kirby->page('geheim/unterseite');
        holds($child instanceof Page && !$child->isDraft() && $child->parent()?->isDraft() === true, 'fixture: the page below the draft is no draft for Kirby, its parent is');
        $shown = var_export($flag, true);
        expect('noindex, nofollow', $child instanceof Page ? Annai\Pages::robots($kirby, $child) : null, "robots meta: a page below a draft → noindex, nofollow (noindexExcluded $shown)");
        expect(false, $child instanceof Page ? $child->annaiIndexable() : null, "annaiIndexable(): a page below a draft is not indexable (noindexExcluded $shown)");
        $grandchild = $kirby->page('geheim/unterseite/tiefer');
        expect('noindex, nofollow', $grandchild instanceof Page ? Annai\Pages::robots($kirby, $grandchild) : null, "robots meta: two levels below a draft → noindex, nofollow (noindexExcluded $shown)");
        $kirby->impersonate('kirby');
        [$code, $html] = annai_request($kirby, 'geheim/unterseite');
        expect(200, $code, "a page below a draft renders for an editor (noindexExcluded $shown)");
        holds(str_contains($html, '<meta name="robots" content="noindex, nofollow">'), "a page below a draft carries noindex, nofollow in its head (noindexExcluded $shown)");
    }, function (string $tmp): void {
        rtFixture($tmp);
        annai_page($tmp, '_drafts/geheim', 'default', ['title' => 'Geheim']);
        annai_page($tmp, '_drafts/geheim/unterseite', 'default', ['title' => 'Unterseite']);
        annai_page($tmp, '_drafts/geheim/unterseite/tiefer', 'default', ['title' => 'Tiefer']);
    });
}

// ── Multilang: hreflang and sitemap alternates ──────────────────────────
// With hreflang every language entry used to carry the lastmod of the current
// (default) language's file; the English entry now has its own.
rtRun(rtOn(['languages' => true, 'annai.hreflang' => true]), function (App $kirby): void {
    $laden = $kirby->page('laden');
    $de = Annai\Sitemap::lastmod(strtotime('2026-01-01 12:00:00 UTC'), 'Europe/Berlin');
    $en = Annai\Sitemap::lastmod(strtotime('2026-06-01 12:00:00 UTC'), 'Europe/Berlin');
    expect($de, Annai\Pages::lastmod($kirby, $laden), 'hreflang lastmod: default language file without a code');
    expect($de, Annai\Pages::lastmod($kirby, $laden, 'de'), 'hreflang lastmod: de file');
    expect($en, Annai\Pages::lastmod($kirby, $laden, 'en'), 'hreflang lastmod: en file has its own modified time');
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    preg_match_all('#<loc>([^<]*)</loc>\s*<lastmod>([^<]*)</lastmod>#', $xml, $m, PREG_SET_ORDER);
    $lastmods = [];
    foreach ($m as $entry) {
        $lastmods[$entry[1]] = $entry[2];
    }
    expect($de, $lastmods['http://example.invalid/laden'] ?? null, 'hreflang sitemap: the de entry carries the de lastmod');
    expect($en, $lastmods['http://example.invalid/en/laden'] ?? null, 'hreflang sitemap: the en entry carries the en lastmod');
}, function (string $tmp): void {
    rtMultilang($tmp);
    touch($tmp . '/content/2_laden/default.de.txt', strtotime('2026-01-01 12:00:00 UTC'));
    touch($tmp . '/content/2_laden/default.en.txt', strtotime('2026-06-01 12:00:00 UTC'));
    clearstatcache();
});

rtRun(rtOn(['languages' => true]), function (App $kirby): void {
    $laden = $kirby->page('laden');
    holds($laden instanceof Page, 'multilang: laden exists');
    expect([], Annai\Pages::alternates($kirby, $laden), 'hreflang off (default): no alternates');
}, 'rtMultilang');

rtRun(rtOn(['languages' => true, 'annai.hreflang' => true]), function (App $kirby): void {
    $laden = $kirby->page('laden');
    $alternates = Annai\Pages::alternates($kirby, $laden);
    expect(['de', 'en', 'x-default'], array_keys($alternates), 'hreflang: de, en, x-default in this order');
    expect('http://example.invalid/laden', $alternates['de'] ?? null, 'hreflang: de URL');
    expect('http://example.invalid/en/laden', $alternates['en'] ?? null, 'hreflang: en URL');
    expect('http://example.invalid/laden', $alternates['x-default'] ?? null, 'hreflang: x-default is the default language URL');
    $kontakt = $kirby->page('kontakt');
    expect(['de', 'x-default'], array_keys(Annai\Pages::alternates($kirby, $kontakt)),
        'hreflang: a page without English content lists only de and x-default');

    [, $xml] = annai_request($kirby, 'sitemap.xml');
    holds(str_contains($xml, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"'), 'hreflang: xmlns:xhtml declared');
    holds(str_contains($xml, '<xhtml:link rel="alternate" hreflang="en" href="http://example.invalid/en/laden"/>'),
        'hreflang: xhtml:link for en in the sitemap');
    expect(5, substr_count($xml, '<url>'), 'hreflang: one url per language (home 2, laden 2, kontakt 1)');
}, 'rtMultilang');

// Kirby 5 counts the default language as existing whenever the page folder
// exists: a page with only default.en.txt must not get hreflang="de".
rtRun(rtOn(['languages' => true, 'annai.hreflang' => true]), function (App $kirby): void {
    $english = $kirby->page('english');
    holds($english instanceof Page, 'hreflang: the English-only page exists');
    $alternates = $english instanceof Page ? Annai\Pages::alternates($kirby, $english) : [];
    expect(['en' => 'http://example.invalid/en/english', 'x-default' => 'http://example.invalid/en/english'], $alternates,
        'hreflang: a page with only English content lists en, and x-default is the English URL');
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    holds(!str_contains($xml, '<loc>http://example.invalid/english</loc>'), 'hreflang: no German sitemap entry for the English-only page');
    expect(6, substr_count($xml, '<url>'), 'hreflang: one url per language with content (home 2, laden 2, kontakt 1, english 1)');
    $laden = $kirby->page('laden');
    expect('http://example.invalid/laden', Annai\Pages::alternates($kirby, $laden)['x-default'] ?? null, 'hreflang: x-default stays the default language URL when it has content');
}, function (string $tmp): void {
    rtMultilang($tmp);
    annai_page($tmp, '4_english', 'default', ['title' => 'English only'], 'en');
});

// Kirby renders /en/kontakt without kontakt/default.en.txt with the
// German content, and the head used to give that copy a canonical on itself — duplicate
// content in every language a page is not translated into. The canonical
// now points to the language version with the content: the default language's,
// else the first language's with content; the sitemap left the URL out already.
$rtEnglishOnly = function (string $tmp): void {
    rtMultilang($tmp);
    annai_page($tmp, '4_english', 'default', ['title' => 'English only'], 'en');
};
foreach ([false, true] as $hreflang) {
    rtRun(rtOn(['languages' => true, 'annai.hreflang' => $hreflang]), function (App $kirby) use ($hreflang): void {
        $with = 'hreflang ' . ($hreflang ? 'on' : 'off');
        [$code, $html] = annai_request($kirby, 'en/kontakt');
        expect(200, $code, "canonical ($with): /en/kontakt renders (the German content)");
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/kontakt">'), "canonical ($with): /en/kontakt without English content points to /kontakt");
        holds(str_contains($html, '<meta property="og:url" content="http://example.invalid/kontakt">'), "canonical ($with): og:url follows the canonical");
        holds(!str_contains($html, 'href="http://example.invalid/en/kontakt"'), "canonical ($with): no link to the English URL of the untranslated page");
        [, $html] = annai_request($kirby, 'en/laden');
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/en/laden">'), "canonical ($with): /en/laden with English content keeps its own URL");
        [, $html] = annai_request($kirby, 'en');
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/en">'), "canonical ($with): the translated home page keeps its own URL");
        [, $html] = annai_request($kirby, 'kontakt');
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/kontakt">'), "canonical ($with): /kontakt in the default language keeps its own URL");
        [, $html] = annai_request($kirby, 'english');
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/en/english">'), "canonical ($with): the English-only page in the default language points to /en/english (the first language with content)");
        $kirby->setCurrentLanguage('en');
        expect('http://example.invalid/kontakt', Annai\Pages::contentUrl($kirby, $kirby->page('kontakt')), "contentUrl ($with): in English, kontakt → the German URL");
        expect(null, Annai\Pages::contentUrl($kirby, $kirby->page('laden')), "contentUrl ($with): null for a page with content in the current language");
        $kirby->setCurrentLanguage('de');
        expect(null, Annai\Pages::contentUrl($kirby, $kirby->page('kontakt')), "contentUrl ($with): null in German, where kontakt has its content");
        expect('http://example.invalid/en/english', Annai\Pages::contentUrl($kirby, $kirby->page('english')), "contentUrl ($with): in German, the English-only page → the English URL");
    }, $rtEnglishOnly);
}
rtRun(rtOn(['languages' => true, 'annai.canonical' => fn (Page $page): string => 'https://fixed.example/' . $page->id()]), function (App $kirby): void {
    [, $html] = annai_request($kirby, 'en/kontakt');
    holds(str_contains($html, '<link rel="canonical" href="https://fixed.example/kontakt">'), 'canonical: the annai.canonical hook keeps its precedence over the content-language rule');
}, $rtEnglishOnly);
rtRun(rtOn(['languages' => true, 'annai.canonical' => fn (Page $page): ?string => null]), function (App $kirby): void {
    [, $html] = annai_request($kirby, 'en/kontakt');
    holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/kontakt">'), 'canonical: a hook returning null leaves the content-language rule in place');
}, $rtEnglishOnly);
rtRun(rtOn(), function (App $kirby): void {
    expect(null, Annai\Pages::contentUrl($kirby, $kirby->page('kontakt')), 'contentUrl: null on a single-language site');
    expect(null, Annai\Pages::contentLanguage($kirby, $kirby->page('kontakt')), 'contentLanguage: null on a single-language site');
}, 'rtFixture');

// The canonical of a page without content in the current language points to
// the language version with the content, but with hreflang off the sitemap and
// llms.txt used to list the current language's URL all the same — a sitemap
// <loc> whose canonical points elsewhere. Each list now
// names the canonical URL, the sitemap entry with that language's lastmod.
$rtCanonicalLists = function (string $tmp) use ($rtEnglishOnly): void {
    $rtEnglishOnly($tmp);
    annai_page($tmp, '4_english', 'default', ['title' => 'English only', 'einleitung' => 'An English intro for this page.'], 'en');
    annai_page($tmp, 'fragen', 'faq', ['title' => 'Questions'], 'en');
    touch($tmp . '/content/4_english/default.en.txt', strtotime('2026-06-01 12:00:00 UTC'));
    clearstatcache();
};
rtRun(rtOn(['languages' => true, 'annai.llms.optional' => ['faq']]), function (App $kirby): void {
    // A fresh app, in the default language: sitemap.xml and llms.txt as a crawler requests them.
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    $locs = rtLocs($xml);
    holds(in_array('http://example.invalid/en/english', $locs, true), 'canonical lists (hreflang off): the sitemap lists the English-only page under its canonical /en/english');
    holds(!in_array('http://example.invalid/english', $locs, true), 'canonical lists (hreflang off): not under /english, whose canonical points to /en/english');
    holds(in_array('http://example.invalid/kontakt', $locs, true) && in_array('http://example.invalid/laden', $locs, true), 'canonical lists (hreflang off): pages with German content keep their German URL');
    preg_match('#<loc>http://example\.invalid/en/english</loc>\s*<lastmod>([^<]*)</lastmod>#', $xml, $m);
    expect(Annai\Sitemap::lastmod(strtotime('2026-06-01 12:00:00 UTC'), 'Europe/Berlin'), $m[1] ?? null, 'canonical lists (hreflang off): the entry carries the lastmod of the English content file');
    expect('en', Annai\Pages::contentLanguage($kirby, $kirby->page('english')), 'contentLanguage: in German, the English-only page → en');
    expect(null, Annai\Pages::contentLanguage($kirby, $kirby->page('kontakt')), 'contentLanguage: null where the current language has the content');
    [, $llms] = annai_request($kirby, 'llms.txt');
    holds(str_contains($llms, '(http://example.invalid/en/english)'), 'canonical lists: llms.txt links the English-only page to /en/english');
    holds(!str_contains($llms, '(http://example.invalid/english)'), 'canonical lists: llms.txt has no link to /english');
    holds(str_contains($llms, '(http://example.invalid/en/fragen)') && !str_contains($llms, '(http://example.invalid/fragen)'), 'canonical lists: an optional page in llms.txt follows the same rule');
    holds(str_contains($llms, '(http://example.invalid/laden)'), 'canonical lists: llms.txt keeps the German URL of a page with German content');
    // The link named /en/english, but the title used to be the slug of the empty
    // German version and the note was missing: both come from the linked version.
    holds(str_contains($llms, "- [English only](http://example.invalid/en/english): An English intro for this page.\n"), 'canonical lists: title and note of the English-only page come from the English version it links');
    holds(str_contains($llms, '- [Questions](http://example.invalid/en/fragen)'), 'canonical lists: an optional page is titled from the version it links');
    holds(str_contains($llms, '- [Hofladen](http://example.invalid/laden): Gemüse vom eigenen Feld.'), 'canonical lists: a page with content in the current language keeps its own title and note');
}, $rtCanonicalLists);
// The same rule where llms.txt is built in another language ($site->annaiLlms()
// in an English template): kontakt has German content only.
rtRun(rtOn(['languages' => true]), function (App $kirby): void {
    $kirby->setCurrentLanguage('en');
    $llms = $kirby->site()->annaiLlms();
    holds(str_contains($llms, '(http://example.invalid/en/laden)'), 'canonical lists (English): a translated page keeps its English URL');
    holds(str_contains($llms, '(http://example.invalid/kontakt)') && !str_contains($llms, '(http://example.invalid/en/kontakt)'), 'canonical lists (English): an untranslated page is linked under its German URL');
}, $rtEnglishOnly);
// The home page link too: a home page with English content only.
rtRun(rtOn(['languages' => true]), function (App $kirby): void {
    [, $llms] = annai_request($kirby, 'llms.txt');
    holds(str_contains($llms, '(http://example.invalid/en)'), 'canonical lists: a home page with English content only is linked as /en');
}, function (string $tmp) use ($rtEnglishOnly): void {
    $rtEnglishOnly($tmp);
    unlink($tmp . '/content/home/home.de.txt');
});

// Version::contentFile() is `@internal` in Kirby 5. Should a release close it,
// Kirby's own answer stands (hreflang stays, as it does without the file check)
// instead of an exception in the head of every page. The page model below throws exactly
// there; `default` is also Kirby's fallback model, so every page uses it.
rtClosedModels();
Page::$models['default'] = 'AnnaiClosedPage';
try {
    rtRun(rtOn(['languages' => true, 'annai.hreflang' => true]), function (App $kirby): void {
        $laden = $kirby->page('laden');
        holds($laden instanceof AnnaiClosedPage && $laden->storage() instanceof AnnaiClosedStorage, 'closed contentFile(): the fixture page uses the throwing storage (fixture check)');
        AnnaiClosedStorage::$thrown = 0;
        try {
            $alternates = Annai\Pages::alternates($kirby, $laden);
        } catch (\Throwable $error) {
            $alternates = get_class($error) . ': ' . $error->getMessage();
        }
        expect(['de', 'en', 'x-default'], is_array($alternates) ? array_keys($alternates) : $alternates, 'closed contentFile(): the alternates are Kirby\'s answer, no exception');
        holds(AnnaiClosedStorage::$thrown > 0, 'closed contentFile(): the internal method did throw (fixture check)');
        [$code, $html] = annai_request($kirby, 'laden');
        expect(200, $code, 'closed contentFile(): the page renders');
        holds(str_contains($html, 'hreflang="en"'), 'closed contentFile(): the head keeps its hreflang links');
    }, 'rtMultilang');
} finally {
    Page::$models = [];
}

rtRun(rtOn(['languages' => true]), function (App $kirby): void {
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    expect(false, str_contains($xml, 'xmlns:xhtml'), 'multilang without hreflang: no xhtml namespace');
    expect(3, substr_count($xml, '<url>'), 'multilang without hreflang: one url per page');
}, 'rtMultilang');

// A page that says noindex offers no hreflang alternates. An excluded
// template used to get "noindex, follow" and <link rel="alternate" hreflang=…> in one head,
// inviting engines to language versions of a page it tells them not to index.
rtRun(rtOn(['languages' => true, 'annai.hreflang' => true, 'annai.exclude.templates' => ['error', 'weiter']]), function (App $kirby): void {
    $weiter = $kirby->page('weiter');
    holds($weiter instanceof Page, 'hreflang + noindex: the excluded page exists');
    expect('noindex, follow', Annai\Pages::robots($kirby, $weiter), 'hreflang + noindex: the excluded page is noindex, follow');
    expect([], Annai\Pages::alternates($kirby, $weiter), 'hreflang + noindex: no alternates for a noindex page');
    expect([], $weiter->annaiAlternates(), 'hreflang + noindex: annaiAlternates() is empty too');
    [, $html] = annai_request($kirby, 'weiter');
    holds(str_contains($html, '<meta name="robots" content="noindex, follow">') && !str_contains($html, 'rel="alternate"'), 'hreflang + noindex: the head carries the robots meta and no hreflang link');
    expect(['de', 'en', 'x-default'], array_keys(Annai\Pages::alternates($kirby, $kirby->page('laden'))), 'hreflang + noindex: an indexable page keeps its alternates');
    expect(0, Annai\Check::page($kirby, $weiter)['alternates'], 'hreflang + noindex: the check counts no alternates');
    Sudare\Sudare::$on = true;
    try {
        expect([], Annai\Pages::alternates($kirby, $kirby->page('laden')), 'hreflang + preview: no alternates while every page is noindex');
        // The sitemap entries (the data method, the check tool) keep one entry per
        // language while the preview is on — they used to collapse to one per page because
        // alternates() answered [] for every page.
        $entries = $kirby->site()->annaiSitemap();
        expect(5, count($entries), 'hreflang + preview: annaiSitemap() still has one entry per language (home 2, laden 2, kontakt 1)');
        $laden = array_values(array_filter($entries, fn (array $entry) => $entry['loc'] === 'http://example.invalid/en/laden'));
        expect(['de', 'en', 'x-default'], array_keys($laden[0]['alternates'] ?? []), 'hreflang + preview: the entries carry their alternates');
        [, $xml] = annai_request($kirby, 'sitemap.xml');
        expect(5, count(rtLocs($xml)), 'hreflang + preview: the sitemap.xml route lists one entry per language (protected: the normal file)');
    } finally {
        Sudare\Sudare::$on = false;
    }
}, function (string $tmp): void {
    rtMultilang($tmp);
    annai_page($tmp, '4_weiter', 'weiter', ['title' => 'Zum Shop'], 'de');
    annai_page($tmp, '4_weiter', 'weiter', ['title' => 'To the shop'], 'en');
});

// Languages without a `url` key: every language has a prefix (/de, /en) and
// $site->url() is the current language's URL. The files live at the base URL.
rtRun(rtOn(['languages' => true, 'annai.hreflang' => true, 'annai.sitemap.chunk' => 2]), function (App $kirby): void {
    expect('http://example.invalid/de', $kirby->site()->url(), 'multilang without url keys: $site->url() has the /de prefix (fixture check)');
    [, $robots] = annai_request($kirby, 'robots.txt');
    holds(str_contains($robots, "Sitemap: http://example.invalid/sitemap.xml\n"), 'multilang without url keys: Sitemap line is /sitemap.xml at the base URL');
    [$code, $index] = annai_request($kirby, 'sitemap.xml');
    expect(200, $code, 'multilang without url keys: sitemap.xml is 200');
    $locs = rtLocs($index);
    holds($locs !== [] && str_contains($index, '<sitemapindex'), 'multilang without url keys: sitemap.xml is an index');
    foreach ($locs as $loc) {
        holds(str_starts_with($loc, 'http://example.invalid/sitemap-'), "multilang without url keys: index loc at the base URL ($loc)");
        [$code] = annai_request($kirby, substr($loc, strlen('http://example.invalid/')));
        expect(200, $code, "multilang without url keys: index loc answers 200 ($loc)");
    }

    $ids = [];
    foreach (['de', 'en'] as $code) {
        [, $html] = annai_request($kirby, $code);
        $ids[$code] = annai_jsonld($html)[0]['@id'] ?? null;
    }
    expect('http://example.invalid/#business', $ids['de'], 'multilang without url keys: business @id in de at the base URL');
    expect($ids['de'], $ids['en'], 'multilang without url keys: business @id is the same in every language');
}, function (string $tmp): void {
    rtMultilang($tmp);
    file_put_contents($tmp . '/site/languages/de.php', "<?php\n\nreturn ['code' => 'de', 'default' => true, 'locale' => 'de_DE.utf8', 'name' => 'Deutsch'];\n");
    file_put_contents($tmp . '/site/languages/en.php', "<?php\n\nreturn ['code' => 'en', 'default' => false, 'locale' => 'en_US.utf8', 'name' => 'English'];\n");
});

// ── llms.txt ─────────────────────────────────────────────────────────────
rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_starts_with($md, "# Testhof\n\n> Bio-Hofladen mit Café\n"), 'llms: H1 first, summary as quote');
    holds(str_contains($md, "## Seiten\n\n- [Startseite](http://example.invalid)\n"), 'llms: Seiten starts with the home page');
    holds(str_contains($md, '- [Hofladen](http://example.invalid/laden): Gemüse vom eigenen Feld.'), 'llms: listed page with excerpt');
    holds(str_contains($md, '## Kontakt') && str_contains($md, '- Adresse: Musterweg 1, 12345 Musterstadt'), 'llms: German Kontakt section');
    holds(!str_contains($md, 'Impressum'), 'llms: unlisted page not in Seiten');
    holds(!str_contains($md, '## Optional'), 'llms: no Optional section without llms.optional');
}, 'rtFixture');

// A listed home page (content/0_home) used to appear twice: as the "Startseite" line
// Routes::llmsModel() adds first and again from Pages::menu().
rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    holds($kirby->site()->homePage()->isListed(), 'listed home: the fixture home page is listed');
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_contains($md, "## Seiten\n\n- [Startseite](http://example.invalid)\n- [Hofladen](http://example.invalid/laden)"), 'listed home: Startseite once, then the first listed page');
    holds(!str_contains($md, '- [Home](http://example.invalid)'), 'listed home: the home page is not listed a second time under its title');
    expect(1, substr_count($md, '](http://example.invalid)'), 'listed home: exactly one link to the home URL');
    expect(['laden', 'kontakt', 'weiter'], array_map(fn (Page $page): string => $page->id(), Annai\Pages::menu($kirby)), 'listed home: Pages::menu() leaves the home page out');
    [, $xml] = annai_request($kirby, 'sitemap.xml');
    expect(1, count(array_keys(rtLocs($xml), 'http://example.invalid', true)), 'listed home: the sitemap lists the home URL once');
}, function (string $tmp): void {
    rtFixture($tmp);
    rename($tmp . '/content/home', $tmp . '/content/0_home');
});

rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_contains($md, '- [Hofladen](http://example.invalid/laden): Gemüse vom eigenen Feld, siehe Karte und Kontakt.' . "\n"),
        'llms: Markdown and Kirbytags in the page note become plain text');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '2_laden', 'default', ['title' => 'Hofladen',
        'einleitung' => 'Gemüse **vom** eigenen Feld, siehe [Karte](https://x.test) und (link: kontakt text: Kontakt).']);
});

rtRun(rtOn(), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_starts_with($md, "# Testhof\n\n> Hofladen klick\n"), 'llms: HTML and a javascript: link in beschreibung do not reach the summary');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => 'Hofladen <img src=x onerror=alert(1)> [klick](javascript:alert(1))']);
});

// The summary and the JSON-LD description are the site description rendered with
// kirbytext() and reduced to plain text, like the meta description but not
// shortened; Markdown and Kirbytags used to go in raw ("**Bio**", "(link: …)").
rtRun(rtOn(['annai.language' => 'de']), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_starts_with($md, "# Testhof\n\n> Bio-Hofladen mit Café. Bestellen: Online-Shop\n"), 'llms: Markdown and Kirbytags in beschreibung become plain text in the summary');
    [, $html] = annai_request($kirby, '');
    expect('Bio-Hofladen mit Café. Bestellen: Online-Shop', annai_jsonld($html)[0]['description'] ?? null, 'JSON-LD: the description is the same plain text');
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $meta);
    expect('Bio-Hofladen mit Café. Bestellen: Online-Shop', $meta[1] ?? null, 'meta description: the same text');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => "**Bio**-Hofladen mit Café.\n\nBestellen: (link: https://shop.example text: Online-Shop)"]);
});

rtRun(rtOn(['annai.language' => 'de', 'annai.exclude.templates' => ['error', 'weiter']]), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(!str_contains($md, 'Zum Shop'), 'llms: excluded template missing from Seiten');
}, 'rtFixture');

// The home page is listed in llms.txt only when it is not excluded. It used to be
// written first under its own label whatever `annai.exclude.pages` said, while the
// sitemap left it out.
foreach (['Closure' => fn (Page $page): bool => $page->isHomePage(), 'id' => ['home']] as $how => $exclude) {
    rtRun(rtOn(['annai.language' => 'de', 'annai.exclude.pages' => $exclude]), function (App $kirby) use ($how): void {
        [, $md] = annai_request($kirby, 'llms.txt');
        holds(!str_contains($md, 'Startseite') && !str_contains($md, '](http://example.invalid)'), "llms: home excluded by $how is not listed");
        holds(str_contains($md, "## Seiten\n\n- [Hofladen](http://example.invalid/laden)"), "llms: home excluded by $how: Seiten starts with the first listed page");
        [, $xml] = annai_request($kirby, 'sitemap.xml');
        holds(!in_array('http://example.invalid', rtLocs($xml), true), "llms: home excluded by $how is not in the sitemap either (fixture check)");
    }, 'rtFixture');
}

rtRun(rtOn(['annai.llms.optional' => ['rechtstext']]), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_contains($md, "## Optional\n\n- [Impressum](http://example.invalid/impressum)\n"), 'llms: llms.optional lists Impressum');
}, 'rtFixture');

rtRun(rtOn(['annai.llms.extra' => function (array $model, array $facts): array {
    $model['summary'] = 'Geändert';
    return $model;
}]), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_contains($md, "> Geändert\n"), 'llms: llms.extra hook changes the summary');
}, 'rtFixture');

rtRun(rtOn(), function (App $kirby): void {
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_contains($md, "## Contact\n"), 'llms: English default without annai.language → "## Contact"');
    holds(str_contains($md, "## Pages\n"), 'llms: English default has a Pages heading');
    preg_match_all('#\]\(([^)]*)\)#', $md, $links);
    holds($links[1] !== [] && array_filter($links[1], fn ($l) => !str_starts_with($l, 'http')) === [], 'llms: all links absolute');
    holds(str_ends_with($md, "\n") && !str_ends_with($md, "\n\n") && !str_contains($md, "\n\n\n"), 'llms: one final newline, no triple newlines');
}, 'rtFixture');

// ── Content that is not UTF-8 (imported text) ───────────────────────────
rtRun(rtOn(), function (App $kirby): void {
    [$code, $html] = annai_request($kirby, '');
    expect(200, $code, 'invalid UTF-8 in beschreibung: home page still renders');
    try {
        $blocks = annai_jsonld($html);
    } catch (JsonException) {
        $blocks = [];
    }
    expect(1, count($blocks), 'invalid UTF-8 in beschreibung: one JSON-LD block that decodes');
    expect("Caf\u{FFFD} am Hof", $blocks[0]['description'] ?? null, 'invalid UTF-8 in beschreibung: the byte becomes U+FFFD');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => "Caf\xE9 am Hof"]);
});

// kirbytext() turns a text with an invalid byte into "<p></p>": the byte is
// replaced before, so description, og:description and the llms.txt note stay.
rtRun(rtOn(), function (App $kirby): void {
    [, $html] = annai_request($kirby, 'laden');
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $description);
    preg_match('#<meta property="og:description" content="([^"]*)"#', $html, $og);
    expect("Gem\u{FFFD}se vom eigenen Feld.", $description[1] ?? null, 'invalid UTF-8 in einleitung: meta description keeps the text, byte as U+FFFD');
    expect("Gem\u{FFFD}se vom eigenen Feld.", $og[1] ?? null, 'invalid UTF-8 in einleitung: og:description keeps the text');
    [, $html] = annai_request($kirby, '');
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $description);
    expect("Caf\u{FFFD} am Hof", $description[1] ?? null, 'invalid UTF-8 in beschreibung: home meta description keeps the text');
    [, $md] = annai_request($kirby, 'llms.txt');
    holds(str_contains($md, "(http://example.invalid/laden): Gem\u{FFFD}se vom eigenen Feld.\n"), 'invalid UTF-8 in einleitung: llms.txt note keeps the text');
}, function (string $tmp): void {
    rtFixture($tmp);
    annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof', 'beschreibung' => "Caf\xE9 am Hof"]);
    annai_page($tmp, '2_laden', 'default', ['title' => 'Hofladen', 'einleitung' => "Gem\xFCse vom eigenen Feld."]);
});

// ── Hooks that throw or return the wrong type ───────────────────────────
// A site's hook must not take a page down: logged, Annai's default is used.
$boom = function (): never {
    throw new RuntimeException('kaputt');
};
rtRun(rtOn([
    'annai.jsonld'        => $boom,
    'annai.description'   => $boom,
    'annai.canonical'     => $boom,
    'annai.image'         => $boom,
    'annai.sameAs'        => $boom,
    'annai.sitemap.extra' => $boom,
    'annai.llms.extra'    => $boom,
]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        [$code, $html] = annai_request($kirby, '');
        expect(200, $code, 'throwing hooks: home page renders');
        expect('LocalBusiness', annai_jsonld($html)[0]['@type'] ?? null, 'throwing annai.jsonld: Annai\'s business node stays');
        holds(str_contains($html, '<meta name="description" content="Bio-Hofladen mit Café">'), 'throwing annai.description: the field rule applies');
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid">'), 'throwing annai.canonical: the page URL');
        [$code, $xml] = annai_request($kirby, 'sitemap.xml');
        expect(200, $code, 'throwing annai.sitemap.extra: sitemap.xml is 200');
        holds(in_array('http://example.invalid/laden', rtLocs($xml), true), 'throwing annai.sitemap.extra: the pages are listed');
        [$code, $md] = annai_request($kirby, 'llms.txt');
        expect(200, $code, 'throwing annai.llms.extra: llms.txt is 200');
        holds(str_starts_with($md, "# Testhof\n\n> Bio-Hofladen mit Café\n"), "throwing annai.llms.extra: Annai's model is served");
    });
    foreach (['jsonld', 'description', 'canonical', 'image', 'sameAs', 'sitemap.extra', 'llms.extra'] as $key) {
        holds(str_contains($log, "Annai: annai.$key threw RuntimeException: kaputt"), "throwing annai.$key is logged");
    }
}, 'rtFixture');

foreach (['array' => fn () => ['Titel'], 'stdClass' => fn () => new stdClass()] as $type => $hook) {
    rtRun(rtOn(['annai.title' => $hook, 'annai.description' => $hook]), function (App $kirby, string $tmp) use ($type): void {
        $log = annai_with_error_log($tmp, function () use ($kirby, $type): void {
            [$code, $html] = annai_request($kirby, 'laden');
            expect(200, $code, "annai.title returning $type: page renders");
            holds(str_contains($html, '<title>Hofladen · Testhof</title>'), "annai.title returning $type: the title rule applies");
            holds(str_contains($html, 'content="Gemüse vom eigenen Feld."'), "annai.description returning $type: the field rule applies");
        });
        holds(str_contains($log, 'annai.title must return a string, not ' . $type), "annai.title returning $type is logged");
    }, 'rtFixture');
}

// A hook option that holds anything but a Closure ('annai.title' =>
// 'Eigener Titel', an array of nodes as annai.jsonld) used to be ignored in silence,
// although the README promised a log line for a broken value. It is
// logged once (Options::hook()), Annai's rule applies, the check reports it.
rtRun(rtOn([
    'annai.title'       => 'Eigener Titel',
    'annai.description' => 'Eigene Beschreibung',
    'annai.canonical'   => 'https://fixed.example/',
    'annai.jsonld'      => [['@type' => 'Thing']],
    'annai.llms.extra'  => ['sections' => []],
    'annai.location'    => 42,
]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        [$code, $html] = annai_request($kirby, 'laden');
        expect(200, $code, 'non-Closure hooks: the page renders');
        holds(str_contains($html, '<title>Hofladen · Testhof</title>'), 'non-Closure annai.title: the title rule applies');
        holds(str_contains($html, 'content="Gemüse vom eigenen Feld."'), 'non-Closure annai.description: the field rule applies');
        holds(str_contains($html, '<link rel="canonical" href="http://example.invalid/laden">'), 'non-Closure annai.canonical: the page URL');
        [, $home] = annai_request($kirby, '');
        expect('LocalBusiness', annai_jsonld($home)[0]['@type'] ?? null, "non-Closure annai.jsonld: Annai's business node stays");
        [, $md] = annai_request($kirby, 'llms.txt');
        holds(str_starts_with($md, "# Testhof\n"), "non-Closure annai.llms.extra: Annai's model is served");
        expect('kontakt', Annai\Business::location($kirby)?->id(), 'non-Closure annai.location: the template search finds the business page');
    });
    foreach (['title' => "'Eigener Titel'", 'description' => "'Eigene Beschreibung'", 'canonical' => "'https://fixed.example/'", 'jsonld' => 'array', 'llms.extra' => 'array'] as $key => $shown) {
        holds(str_contains($log, "Annai: annai.$key must be a Closure, not $shown — ignored."), "non-Closure annai.$key is logged with its value or type");
    }
    holds(str_contains($log, 'Annai: annai.location must be a page id or a Closure, not 42 — ignored.'), 'annai.location 42 is logged with its value');
    expect(1, substr_count($log, 'annai.title must be a Closure'), 'non-Closure annai.title: logged once per process, not once per page');
    $problems = Annai\Check::site(annai_kirby($tmp, rtOn(['annai.title' => 'Anderer Titel', 'annai.location' => ['kontakt']])))['problems'];
    holds(in_array("configuration: annai.title must be a Closure, not 'Anderer Titel' — ignored.", $problems, true), 'check: a non-Closure hook is a configuration problem');
    holds(in_array('configuration: annai.location must be a page id or a Closure, not array — ignored.', $problems, true), 'check: a non-string, non-Closure annai.location is a configuration problem');
}, 'rtFixture');

rtRun(rtOn(['annai.title' => null, 'annai.location' => null]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        annai_request($kirby, 'laden');
    });
    holds(!str_contains($log, 'must be a Closure') && !str_contains($log, 'annai.location'), 'null hook options count as unset and log nothing');
}, 'rtFixture');

rtRun(rtOn(['annai.title' => fn (Page $page) => $page->title()]), function (App $kirby): void {
    [, $html] = annai_request($kirby, 'laden');
    holds(str_contains($html, '<title>Hofladen</title>'), 'annai.title returning a Field (Stringable) is used as text');
}, 'rtFixture');

// The exclusion Closure fails closed: a page it cannot judge is kept out.
rtRun(rtOn(['annai.exclude.pages' => $boom]), function (App $kirby, string $tmp): void {
    $log = annai_with_error_log($tmp, function () use ($kirby): void {
        [$code, $xml] = annai_request($kirby, 'sitemap.xml');
        expect(200, $code, 'throwing annai.exclude.pages: sitemap.xml is 200');
        expect([], rtLocs($xml), 'throwing annai.exclude.pages: fails closed, nothing is listed');
    });
    holds(str_contains($log, 'annai.exclude.pages threw RuntimeException: kaputt — the page counts as excluded'), 'throwing annai.exclude.pages is logged');
}, 'rtFixture');

// With Kirby's debug option on, a failing hook is thrown, so the developer sees it.
rtRun(rtOn(['annai.jsonld' => $boom, 'debug' => true]), function (App $kirby): void {
    $thrown = null;
    try {
        Annai\Business::jsonLd($kirby, $kirby->site()->homePage());
    } catch (InvalidArgumentException $error) {
        $thrown = $error->getMessage();
    }
    holds(is_string($thrown) && str_contains($thrown, 'annai.jsonld threw RuntimeException: kaputt'), 'debug on: a throwing hook is thrown as InvalidArgumentException naming the hook');
}, 'rtFixture');

// A hook result that cannot be encoded (NAN, INF, too deep) used to fail only in
// JsonLd::script(), after Options::call() had returned: the home page died
// with a JsonException. The JSON-LD is left out and logged, the page renders.
rtRun(rtOn(['annai.jsonld' => fn (Page $page, array $nodes, array $facts): array => array_merge($nodes, [['@type' => 'Thing', 'value' => fdiv(0, 0)]])]),
    function (App $kirby, string $tmp): void {
        $log = annai_with_error_log($tmp, function () use ($kirby): void {
            $code = null;
            $html = '';
            try {
                [$code, $html] = annai_request($kirby, '');
            } catch (Throwable $error) {
                holds(false, 'unencodable JSON-LD: home page threw ' . get_class($error) . ': ' . $error->getMessage());
            }
            expect(200, $code, 'unencodable JSON-LD: home page renders');
            holds(str_contains($html, '<title>Testhof</title>'), 'unencodable JSON-LD: the rest of the head is there');
            holds(!str_contains($html, 'ld+json'), 'unencodable JSON-LD: no JSON-LD script');
            expect(null, Annai\Business::head($kirby, $kirby->site()->homePage())['jsonld'], 'unencodable JSON-LD: head input has no jsonld');
            holds(is_array(Annai\Business::jsonLd($kirby, $kirby->site()->homePage())), 'unencodable JSON-LD: jsonLd() still returns the document (the check reports it)');
        });
        holds(str_contains($log, 'JSON-LD of home cannot be encoded') && str_contains($log, 'NaN'), 'unencodable JSON-LD is logged with the reason');
    }, 'rtFixture');

// ── Koyomi (skipped without the sibling package) ────────────────────────
if (annai_sibling('koyomi') === null) {
    skip('Koyomi lastmod rule and llms events', 'kirby-koyomi not found (ANNAI_KOYOMI_PATH or ../kirby-koyomi)');
    skip('Koyomi llms list', 'kirby-koyomi not found');
} else {
    $koyomiTmp = annai_site(['koyomi']);
    try {
        rtKoyomi($koyomiTmp);
        // German, so the Termine heading is the one asserted below (English default: "Events").
        $kirby = annai_kirby($koyomiTmp, rtOn(['koyomi.active' => true, 'koyomi.now' => '2027-01-01 12:00', 'annai.language' => 'de']));
        $past = $kirby->page('events/silent-retreat');
        $upcoming = $kirby->page('events/breathing');
        holds($past instanceof Page && $upcoming instanceof Page, 'koyomi: both event pages exist');
        if ($past instanceof Page && method_exists($past, 'koyomi') && $past->koyomi() !== null) {
            $end = $past->koyomi()->end;
            $lastmod = Annai\Pages::lastmod($kirby, $past);
            holds($lastmod !== null && (new DateTimeImmutable((string) $lastmod))->getTimestamp() >= $end->getTimestamp(),
                'koyomi: past event lastmod is at least its end (' . $lastmod . ')');
            expect($end->setTimezone(new DateTimeZone('Europe/Berlin'))->format(DATE_ATOM), $lastmod,
                'koyomi: lastmod of a past event equals its end when the edit is older');
        } else {
            holds(false, 'koyomi: past event has a Koyomi event');
        }
        [$code, $md] = annai_request($kirby, 'llms.txt');
        expect(200, $code, 'koyomi: llms.txt is 200');
        holds(str_contains($md, "## Termine\n"), 'koyomi: llms.txt has a Termine section');
        holds(str_contains($md, '[Atemseminar](http://example.invalid/events/breathing)'),
            'koyomi: upcoming event is listed with its absolute URL');
        holds(!str_contains($md, 'Schweigeretreat'), 'koyomi: past event is not listed');
        holds(str_contains($md, 'Seminarhaus Beispielhof'), 'koyomi: venue label of the upcoming event');

        // Exclusions hold for events as for every page: llms.txt and sitemap.xml.
        $excludes = [
            'template' => ['annai.exclude.templates' => ['error', 'koyomi-event']],
            'page id'  => ['annai.exclude.pages' => ['events/breathing']],
        ];
        foreach ($excludes as $label => $exclude) {
            $kirby = annai_kirby($koyomiTmp, rtOn(['koyomi.active' => true, 'koyomi.now' => '2027-01-01 12:00', 'annai.language' => 'de'] + $exclude));
            [, $md] = annai_request($kirby, 'llms.txt');
            holds(!str_contains($md, 'Atemseminar'), "koyomi: event excluded by $label is not in llms.txt");
            holds(!str_contains($md, '## Termine'), "koyomi: event excluded by $label leaves no Termine section");
            [, $xml] = annai_request($kirby, 'sitemap.xml');
            holds(!in_array('http://example.invalid/events/breathing', rtLocs($xml), true), "koyomi: event excluded by $label is not in the sitemap");
        }
    } finally {
        annai_remove_tree($koyomiTmp);
    }

    // Koyomi's event url is the current language's. In an English
    // context ($site->annaiLlms() in an English template) an event with German
    // content only used to be linked as /en/events/…, whose canonical points to the
    // German URL. The events list follows the canonical rule like every other list.
    rtRun(rtOn(['languages' => true, 'koyomi.active' => true, 'koyomi.now' => '2027-01-01 12:00']), function (App $kirby): void {
        $kirby->setCurrentLanguage('en');
        $urls = array_column(Annai\Pages::events($kirby), 'url');
        expect(['http://example.invalid/events/breathing'], $urls, 'koyomi (English): an event with German content only is linked under its canonical German URL');
    }, 'rtKoyomiGerman', ['koyomi']);

    // The title comes from the language version the link points to. Kirby 5
    // fills a language without a content file from the default language's
    // fields, so Koyomi's English title of a German-only event is the German one
    // already, and the line that reads the linked version's title could go
    // without a test turning red. The page model below is Kirby without that fallback (the
    // merge in Version::content() is marked @todo there): Koyomi's own English
    // title is then empty, and llms.txt must still name the German title.
    rtDeclareEventWithoutFallback();
    rtRun(rtOn(['languages' => true, 'koyomi.active' => true, 'koyomi.now' => '2027-01-01 12:00']), function (App $kirby): void {
        $model = Page::$models['koyomi-event'] ?? null;
        Page::$models['koyomi-event'] = RtEventWithoutFallback::class;
        try {
            $kirby->setCurrentLanguage('en');
            $koyomiTitles = array_map(fn ($event) => $event->title, \Koyomi\Repository::upcoming(\Koyomi\Repository::calendar($kirby)));
            expect([''], $koyomiTitles, 'koyomi (English, without Kirby\'s fallback): Koyomi\'s own title of a German-only event is empty (precondition)');
            $llms = $kirby->site()->annaiLlms();
            holds(str_contains($llms, "\n- [Atemseminar](http://example.invalid/events/breathing)"), 'koyomi (English, without Kirby\'s fallback): llms.txt names the event with the title of the German version it links');
        } finally {
            Page::$models['koyomi-event'] = $model;
        }
    }, 'rtKoyomiGerman', ['koyomi']);

    // An empty title in the linked version used to give titleIn()'s slug
    // ("breathing"), while an event in the current language with an empty title
    // kept Koyomi's ''; a later change made both Koyomi's '', and llms.txt wrote
    // "- [](…)". Both take titleIn()'s slug of the linked version now, as
    // every page link does.
    // Koyomi caches its events per App instance, with the URLs of the language
    // they were first read in: each language gets an App of its own, as a
    // request does.
    $untitled = function (string $tmp): void {
        rtKoyomiGerman($tmp);
        annai_page($tmp, '5_events/20270312_breathing', 'koyomi-event', [
            'title' => '', 'startDate' => '2027-03-12', 'startTime' => '18:00',
            'endDate' => '2027-03-13', 'endTime' => '12:00', 'venue' => 'venues/sonnenhof',
        ], 'de');
    };
    foreach (['en' => 'English', 'de' => 'German'] as $code => $language) {
        rtRun(rtOn(['languages' => true, 'koyomi.active' => true, 'koyomi.now' => '2027-01-01 12:00']), function (App $kirby) use ($code, $language): void {
            $kirby->setCurrentLanguage($code);
            $events = Annai\Pages::events($kirby);
            expect([['breathing', 'http://example.invalid/events/breathing']], array_map(fn (array $event): array => [$event['title'], $event['url']], $events), "koyomi ($language): a German-only event with an empty title is named by the slug of the German version it links");
            $llms = $kirby->site()->annaiLlms();
            holds(str_contains($llms, "\n- [breathing](http://example.invalid/events/breathing)"), "koyomi ($language): llms.txt names the event by its slug");
            holds(!str_contains($llms, '[]('), "koyomi ($language): llms.txt has no link without a label");
        }, $untitled, ['koyomi']);
    }
}
    }
}
