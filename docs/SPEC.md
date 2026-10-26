# Annai — build specification

Annai (案内, guidance: the one who shows the way) is a Kirby 5 plugin for
everything search engines and AI agents read from a site: `robots.txt`,
`sitemap.xml`, `llms.txt`, JSON-LD (LocalBusiness and friends), meta
description, canonical, Open Graph, `noindex`, hreflang. **No styling.**

Package `sayamaapps/kirby-annai`, plugin id `sayamaapps/annai`, namespace
`Annai`, option prefix `annai.`, directory: this repository.

It replaces the earlier per-site copies of the same job: the plugin
`site/plugins/suchmaschinen` (the three routes), the snippet
`site/snippets/strukturdaten.php` (JSON-LD) and the `<head>` meta part of
`site/snippets/kopf.php`. Opening hours come from Noren, events from Koyomi;
both are optional (`suggest`, never `require`).

This document is the contract. Section numbers are referenced from code
comments.

---

## 1. Ground rules for contributors

- PHP 8.2 compatible (no typed class constants, no `readonly` classes, no
  8.3+ functions).
- Code, identifiers, comments, README in **English**. German only in
  `translations/de.php`. Legacy German option *keys*
  (`breite`, `ziel`) are accepted as input where §4 says so, never produced.
- Every user-facing string comes from `I18n::t()` (keys in §5).
- Tests: standalone PHP scripts `tests/<Name>Test.php`, exit code 0 = pass, no
  PHPUnit. Use `tests/helpers.php` (`expect()`, `holds()`, `finish()`,
  `annai_site()`, `annai_kirby()`, `annai_page()`, `annai_remove_tree()`,
  `annai_request()`). Kirby-free tests `require_once` `src/I18n.php` and the
  classes they need, never `index.php`; `TextTest` also loads
  `vendor/autoload.php` for libphonenumber (§3.2). Kirby tests never require src files;
  Kirby loads the plugin through the symlink `annai_site()` creates.
- Kirby tests call routes with `annai_request($kirby, 'robots.txt', 'HEAD')`
  (→ `$kirby->render($path, $method)`), not with `php -S`.
- Output must be valid: robots.txt per RFC 9309, sitemap per sitemaps.org,
  llms.txt per llmstxt.org, JSON-LD encoded with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`.
- Drafts never appear anywhere (sitemap, llms.txt, hreflang, check tool).
- Never hard-code a host. Every absolute URL comes from `Pages::base()` (Kirby's base URL, `$kirby->url()`),
  `$page->url()` or Kirby's `url()`.
- Do not touch any other repository (the sources in §0 are read-only). Never
  delete files outside this directory.

### 0. Related packages (read-only sources)

| What | Path |
|---|---|
| Noren | `../kirby-noren/README.md` ("Own markup", "Return shapes"), `src/SchemaOrg.php`, `src/Locations.php` (`units()`), `src/Schedule.php` (`blocks()`, `note()`, `upcomingClosures()`, `label()`) |
| Koyomi | `../kirby-koyomi/README.md`, `src/Repository.php` (`calendar()`, `upcoming()`), `src/Event.php` (`isPast()`, `end`, `url`, `title`), `src/Models/EventPage.php` (`koyomi()`), `src/Format.php` (`when()`), `index.php` (`koyomi_i18n()`) |

### 0.1 Earlier copies

Before 1.0.0 the same job lived as copies in each site: the plugin `site/plugins/suchmaschinen`
(the three routes), the snippet `strukturdaten` (JSON-LD) and the head lines of `kopf.php`, with
variants per site. Annai replaces them. CHANGELOG.md (1.0.0) lists the renamed options and the
behaviour changes. The options that carry the variants are `sayamaapps.annai.jsonld` (`@graph` sites),
`sayamaapps.annai.geo`, `sayamaapps.annai.action`, `sayamaapps.annai.title`, `sayamaapps.annai.description`, `sayamaapps.annai.llms.extra`,
`sayamaapps.annai.llms.optional`, `sayamaapps.annai.image`, `sayamaapps.annai.twitterCard`, `sayamaapps.annai.noindexExcluded` and
`sayamaapps.annai.fields.profiles` (§3–§4). Languages on their own domains are not supported (README,
Requirements).

---

## 2. File tree

```
kirby-annai/
├── composer.json
├── .gitignore, .gitattributes
├── .github/workflows/ci.yml
├── LICENSE
├── docs/SPEC.md
├── index.php   (complete registration, §3.10)
├── src/
│   ├── I18n.php   (§3.1)
│   ├── Options.php   (§3.1, §4)
│   ├── Text.php   (§3.2)
│   ├── Robots.php   (§3.3)
│   ├── Sitemap.php   (§3.4)
│   ├── LlmsTxt.php   (§3.5)
│   ├── JsonLd.php   (§3.6)
│   ├── Head.php   (§3.7)
│   ├── Business.php   (§3.8)
│   ├── Pages.php   (§3.9)
│   ├── Routes.php   (§3.9)
│   └── Check.php   (§3.11)
├── snippets/annai/head.php   (§3.7.3)
├── bin/annai-check   (§3.11)
├── translations/en.php, de.php   (§5)
├── tests/
│   ├── run.sh
│   ├── helpers.php
│   ├── I18nTest.php
│   ├── TextTest.php   (§9.1)
│   ├── RobotsTest.php   (§9.2)
│   ├── SitemapTest.php   (§9.3)
│   ├── LlmsTxtTest.php   (§9.4)
│   ├── JsonLdTest.php   (§9.5)
│   ├── HeadTest.php   (§9.6)
│   ├── SnippetTest.php   (§9.7, Kirby)
│   ├── BusinessTest.php   (§9.8, Kirby)
│   ├── RoutesTest.php   (§9.9, Kirby)
│   ├── AgentReadyTest.php   (§9.10, Kirby, port of the 41 checks)
│   └── CheckTest.php   (§9.11, Kirby)
├── README.md   (§10)
└── CHANGELOG.md
```

Dependency map (who calls whom): `Routes` → `Pages`, `Business`, `Robots`,
`Sitemap`, `LlmsTxt`; `Business` → `Text`, `JsonLd`, `Pages`; `Head` → `JsonLd`;
`Check` → everything.

---

## 3. Architecture and public API

Two layers, as in Noren:

- **Kirby-free** (`Text`, `Robots`, `Sitemap`, `LlmsTxt`, `JsonLd`, `Head`):
  plain arrays in, strings or arrays out. Testable without `vendor/`, except
  `Text::phone()`: it needs giggsey/libphonenumber-for-php-lite (§3.2).
- **Kirby side** (`Business`, `Pages`, `Routes`, `Check`): reads pages,
  options, Noren and Koyomi, builds the plain arrays, hands them to layer one.

Off by default: without `'sayamaapps.annai.active' => true` every route falls through to
Kirby (`$this->next()`, so a site's own route or a real file still works),
every site and page method returns `null`/`[]`/`''`, and the snippet prints
nothing. `Options::active()` is the only switch; every public Kirby-side
method checks it first.

### 3.1 `Annai\I18n`, `Annai\Options`

Same shape as Noren. `I18n::LANGUAGES = ['en', 'de']`, English default and
fallback, `I18n::t($key, $values)` with `{name}` placeholders,
`I18n::day(int $weekday)` (0 = Monday), `I18n::date(DateTimeInterface|string $date)`
("12 Mar 2027" / "12.3.2027"; for future tools — nothing in Annai itself calls it
yet, the tests keep it working).

`Options::hook($kirby, $key): ?Closure` reads a Closure-only option (`title`,
`description`, `canonical`, `jsonld`, `llms.extra`): the Closure, or null when
unset; a set value that is no Closure is Options::warn()ed once (`sayamaapps.annai.title
must be a Closure, not 'Eigener Titel' — ignored.`; a scalar is shown, else
its type) and counts as unset (it
used to be ignored in silence). `Options::location()` warns the same way for
a value that is neither a page id nor a Closure. The options that take a plain
value as well (`image`, `sameAs`, `sitemap.extra`, `exclude.pages`) keep
their own readers.
`Options::call($kirby, $key, Closure $hook, $arguments, $fallback)` runs a hook:
a Throwable is Options::warn()ed and $fallback returned (callers check the
result's type and warn too). Every hook goes through it; the `exclude.pages`
Closure falls back to `true` (excluded, fail closed).

`Options::get($kirby, $key, $default)`, `Options::active()`, `Options::flag()`,
`Options::language()`, `Options::applyLanguage()`, `Options::fields()`,
`Options::warn($kirby, $message)` (logs once per process; with Kirby `debug`
on it throws `InvalidArgumentException`), plus the typed readers listed in §4.
Options with nested names are read as `Options::get($kirby, 'robots.ai')`
(flat `'sayamaapps.annai.robots.ai' => …` and nested `'annai' => ['robots' => ['ai' => …]]`
both work through Kirby's `option()`).

### 3.2 `Annai\Text` — Kirby-free helpers

```php
final class Text
{
    /**
     * The pattern that splits user text into lines: '/\r\n|\r|\n/'. Every line split of user text
     * (robots.extra, Robots::parse() and ::extra(), the llms.txt headings in Check::site()) uses it.
     * Never `\R` without `/u`: in byte mode `\R` also matches NEL = 0x85, the second byte of "Å" (C3 85)
     * and of "内" (E5 86 85), so "Disallow: /Åland/" was cut inside the character. `/u` is no
     * answer: on a byte that is not UTF-8 preg_split() returns false and every line would be lost.
     */
    public const EOL = '/\r\n|\r|\n/';

    /**
     * Collapses all whitespace (incl. newlines) to one space and trims. "a\n b" → "a b". Invalid UTF-8 → U+FFFD.
     * C0/C1 control characters that are not whitespace (ESC, DEL, CSI …; the class of I18n::printable()) are
     * removed first: "a\x1bb\x01c" → "abc", "a\e[2Jb" → "a[2Jb"; excerpt() and plain() inherit it (they
     * used to reach <title>, the meta description and og:* raw from a Panel field, the only outputs without a filter).
     */
    public static function line(string $text): string;

    /**
     * Plain-text excerpt like Kirby's Str::excerpt(): strip tags, decode entities,
     * collapse whitespace; if mb_strlen <= $max return as is; else cut to $max,
     * drop the last partial word (back to the last space; without one keep $max - 1), rtrim,
     * append "…". The result is never longer than $max, ellipsis included.
     * excerpt('Gemüse vom eigenen Feld.', 155) → 'Gemüse vom eigenen Feld.'
     * excerpt('aaaa bbbb cccc', 9) → 'aaaa…'   excerpt('', 155) → ''   excerpt('abcdefghijkl', 8) → 'abcdefg…'
     */
    public static function excerpt(string $text, int $max): string;

    /**
     * Removes null, '' and [] recursively; lists stay lists (array_values).
     * prune(['a' => '', 'b' => ['c' => null, 'd' => 1], 'e' => [[], ['x']]]) → ['b' => ['d' => 1], 'e' => [['x']]]
     * Keeps 0, 0.0 and false.
     */
    public static function prune(array $data): array;

    /**
     * The E.164 number of a phone or fax field, or '' — the input contract below ("Phone numbers: the input
     * contract"). Takes the RAW field value (a line break must still be one here); the caller
     * makes the display string with line(). Trimmed (U+00A0 and U+202F count as a space), the field must hold
     * only the characters of the gate, else '' and 'characters'. libphonenumber reads the rest: parse() with
     * the region of $country (a calling code: '49' → DE, getRegionCodeForCountryCode()), isValidNumber(),
     * format(E164); else '' and 'invalid'. Without the library '' and 'library'.
     * phone('030 / 34567000') → '+493034567000'; phone('+49 (0)30 34567000') → '+493034567000';
     * phone('0049 30 34567000') → '+493034567000'; phone('+43 1 234 56') → '+43123456';
     * phone('01 234 56', '43') → '+43123456'; phone('0471 123456', '39') → '+390471123456';
     * phone('') → '' (no reason); phone('Tel. 030 34567000'), phone('(tel: 030 34567000)'),
     * phone('0351 / 12.12.2010'), phone("030 34567000\n0171 1234567") → '' and 'characters';
     * phone('34567'), phone('+49') → '' and 'invalid'.
     */
    public static function phone(string $display, string $country = '49'): string;

    /**
     * Why phone() gives '' for a field that is not empty: 'characters', 'invalid', 'library'. null when
     * phone() gives a number or the field is empty (spaces only count as empty). Business::e164() logs
     * each reason, the check shows it.
     */
    public static function phoneProblem(string $display, string $country = '49'): ?string;

    /**
     * A field value as a JSON string for a log line or the check: every control character as an escape —
     * C0 by json_encode(), C1 (U+0080–U+009F, kept raw by JSON_UNESCAPED_UNICODE) by a \u00XX escape —,
     * invalid UTF-8 as U+FFFD. shown("030 34567000\n0171 1234567") → '"030 34567000\n0171 1234567"' (a visible \n).
     */
    public static function shown(string $value): string;

    /** "G:i" without leading hour zero, modulo one day: 480 → "8:00", 1080 → "18:00", 1560 → "2:00", 1440 → "0:00". */
    public static function time(int $minutes): string;

    /** "8:00–12:00" (en dash U+2013). */
    public static function range(int $from, int $to): string;

    /** htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, UTF-8, double_encode true. */
    public static function esc(string $text): string;

    /**
     * htmlspecialchars with ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, UTF-8 (for sitemap text nodes
     * and attributes), then characters XML 1.0 does not allow (C0 except tab/LF/CR, U+FFFE/FFFF) removed.
     */
    public static function xml(string $text): string;
}
```

#### Phone numbers: the input contract

**Rule:** a phone or fax field holds **only the
number**. Annai does not look for a number in text. A field outside the
contract publishes nothing, is logged with its reason, and `annai-check`
shows every phone and fax field with its raw value and its result before the
go-live. The number is read by Google's libphonenumber
(`giggsey/libphonenumber-for-php-lite`, a Composer requirement): a standard
instead of a parser of Annai's own.

Why: Annai reads only its own Panel fields `telefon` and `fax` (business page,
`site.txt`, further location pages). Such a field holds a bare number in practice,
and those were always read right. Yet in
testing, first every serious bug and in the end every bug concerned the phone,
each time from an invented input — a note, a date, a time, HTML, a Kirbytag —
that stands in no field Annai reads. libphonenumber turns notes into numbers too
(`0351 / 12.12.2010` → `+4935112122010`), because German numbers have no fixed
length: reading text for a number cannot be done right, and need not be. The
free-text parser (island rule, rule table, exit check, markup reading), the
corpus, the vocabulary, the fuzzer and property P were removed.

**The gate.** Trimmed, the field holds only these characters, each for a
reason (`Text::PHONE_GATE`):

| Character | Why |
|---|---|
| `0`–`9` | the number |
| space (U+00A0 and U+202F count as one, `Text::PHONE_SPACES`) | between groups: `030 34567000`, `0171 123 45 67`; a no-break space looks the same in the Panel and comes with a number copied from a web page or Word |
| `+`, first character only | the country code: `+49 30 34567000` (`0049 …` needs none) |
| `/` | between area code and number: `030 / 34567000` |
| `-` | between groups and before an extension: `0171-1234567`, `34567-0` |
| `(` `)` | around the trunk prefix or the area code: `+49 (0)30 34567000`, `(030) 34567000` |

**Not `.`**: dates and times consist of digits and dots (`12.12.2010`,
`9.00`); with the dot they would pass the gate, and libphonenumber reads
`0351 / 12.12.2010` as the valid German number `+4935112122010`. The price:
`030.34567000` and `+33.1.23.45.67.89` give `''` and are reported — write
spaces. Not a letter (`Tel.`, `ext.`, a Kirbytag, HTML, a note), not a line
break, a tab, another dash (`–`) or another space, not `+` inside the number
(`(+49) 30 …`).

**Reading.** A field within the gate goes to libphonenumber:
`PhoneNumberUtil::parse($field, $region)`, `isValidNumber()`,
`format(E164)`. `$region` is the region of `sayamaapps.annai.phoneCountry` (a calling
code, default `49`): `getRegionCodeForCountryCode()` gives `DE` for 49, `AT`
for 43, `IT` for 39, `US` for 1; a code without a region (`ZZ`) reads only
numbers with `+`. A number with `+` is read for its own country whatever
`phoneCountry` says; one with the international prefix of the region
(`00` in DE, AT, CH, IT; `011` in the NANP) too, so `0049 30 34567000` is read
with `49` and `43`, not with `1`. The trunk prefix `(0)` after a country
code and the leading `0` of an Italian area code are libphonenumber's:
`+49 (0)30 34567000` → `+493034567000`, `0471 123456` with `39` →
`+390471123456`.

| Field | Result | Reason (`Text::phoneProblem()`) |
|---|---|---|
| empty, or spaces only | `''` | `null`, no log line |
| a number within the gate that libphonenumber validates | `+…` | `null` |
| a character outside the gate | `''` | `'characters'` |
| within the gate, but libphonenumber cannot parse it or it is no valid number (`34567`, `0351`, `+49`, `-`, 16 digits) | `''` | `'invalid'` |
| within the gate, libphonenumber not installed | `''` | `'library'` |

`Business::e164()` logs every reason with the raw value as JSON
(`Text::shown()`: a line break shows as `\n`, a control character as an
escape); the check reports the log line as `configuration: …` and
prints each field (§3.11). JSON-LD gets `telephone`/`faxNumber` only for a
number; llms.txt shows the field as written, with ` (+E.164)` when there is a
number.

**The limit of the contract.** A note written only with characters of the
gate is read as libphonenumber reads it: `030 34567000 (12)` →
`+49303456700012`, `030 34567000 0815` → `+4930345670000815`. Annai cannot tell
such digits from the number; the contract keeps them out of the field, and
`annai-check` shows the result before the go-live. An input outside the
contract is therefore **not a bug**, as long as the gate rejects it or it
consists of gate characters only and the check shows the result.

**The library.** `giggsey/libphonenumber-for-php-lite` `^9.0` is in
`composer.json` `require` (PHP `^8.1`; Annai needs `^8.2`). Annai's
`index.php` loads nothing for it: the site's Composer autoloader does, as for
Kirby (`kirby/bootstrap.php` and `annai-check` include the site's
`vendor/autoload.php`). A copy of the plugin without Composer (ZIP, manual
download) runs, but `Text::phone()` gives `''` and `'library'` for every field
that passes the gate; Business logs it once
(`phone numbers need giggsey/libphonenumber-for-php-lite, which is not installed …`);
with Kirby's `debug` option on, `Options::warn()` throws it on every page
instead, as for a broken option. The check reports it on a site whose Kirby
comes from Composer; next to a ZIP Kirby the check needs a
`vendor/autoload.php` and does not run (exit 2). Composer is the supported
installation (README).

**The tests** (`tests/TextTest.php`, table-driven): the golden table of
seven spellings (`01512 3456789`, `030 34567020`, `+49 30 34567040`,
`030 34567060`, `030 / 34567000`, `+49 1512 3456788`, fax `030 34567010`) with
their E.164 numbers as libphonenumber 9.0.41 gives them; the allowed
spellings; numbers from abroad and national numbers per `phoneCountry`;
fields outside the gate; invalid and empty fields; the limit of the contract;
the missing library (a child process without the autoloader); the run time of
four fields of 50,000 characters. Each rule of this section was broken once
in the code and turned its test red (30 mutants).

### 3.3 `Annai\Robots` — RFC 9309 text

```php
final class Robots
{
    /**
     * Known AI user agents and their role. Compiled 2026-10-11 from the vendor pages
     * named below and the community list https://github.com/ai-robots-txt/ai.robots.txt
     * (robots.json). KEEP THIS COMMENT and the sources in the file:
     *   OpenAI     https://platform.openai.com/docs/bots                       GPTBot (training), ChatGPT-User (user), OAI-SearchBot (search)
     *   Anthropic  https://support.anthropic.com/en/articles/8896518            ClaudeBot (training), Claude-User (user), Claude-SearchBot (search)
     *   Google     https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers   Google-Extended (training token)
     *   Apple      https://support.apple.com/en-us/119829                      Applebot-Extended (training)
     *   Common Crawl https://commoncrawl.org/ccbot                             CCBot (training)
     *   Perplexity https://docs.perplexity.ai/guides/bots                      PerplexityBot (search), Perplexity-User (user)
     *   Meta       https://developers.facebook.com/docs/sharing/webmasters/web-crawlers   meta-externalagent (training), meta-externalfetcher (user)
     *   Amazon     https://developer.amazon.com/amazonbot                      Amazonbot (training)
     *   Mistral    https://docs.mistral.ai/robots/                             MistralAI-User (user)
     *   DuckDuckGo https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot/   DuckAssistBot (search)
     *   Allen AI   https://allenai.org/crawler                                 AI2Bot (training)
     *   community list only: Bytespider, cohere-ai, Diffbot, omgili, Timpibot, PanguBot, img2dataset (training), YouBot (search)
     *
     * Roles: 'training' (builds models), 'search' (feeds an AI search index),
     * 'user' (fetches a page because a person asked right now).
     * docs/agent-ready.md rule: never block 'user' and 'search' agents.
     */
    public const AI_AGENTS = [
        'GPTBot' => 'training', 'ChatGPT-User' => 'user', 'OAI-SearchBot' => 'search',
        'ClaudeBot' => 'training', 'Claude-User' => 'user', 'Claude-SearchBot' => 'search',
        'Google-Extended' => 'training', 'Applebot-Extended' => 'training', 'CCBot' => 'training',
        'PerplexityBot' => 'search', 'Perplexity-User' => 'user',
        'meta-externalagent' => 'training', 'meta-externalfetcher' => 'user',
        'Amazonbot' => 'training', 'MistralAI-User' => 'user', 'DuckAssistBot' => 'search',
        'AI2Bot' => 'training', 'Bytespider' => 'training', 'cohere-ai' => 'training',
        'Diffbot' => 'training', 'omgili' => 'training', 'Timpibot' => 'training',
        'PanguBot' => 'training', 'img2dataset' => 'training', 'YouBot' => 'search',
    ];

    public const PRESETS = [null, 'blockTraining', 'allowAll'];

    /**
     * Per-agent rule map from the preset and the overrides: agent → true (allow) | false (block).
     *   preset null          → only $overrides
     *   preset 'blockTraining' → every 'training' agent false, then $overrides
     *   preset 'allowAll'    → every known agent true (explicit groups), then $overrides
     * Unknown agent names in $overrides are kept as given (a site may know a bot we do not).
     * Names compare case-insensitively (RFC 9309 §2.2.1): an override "gptbot" sets the known
     * "GPTBot", it never adds a second group; warnings() looks the role up the same way.
     * Agent names keep their spelling (user agents are case-insensitive per RFC, we do not fold).
     *
     * @param array<string,bool> $overrides
     * @return array<string,bool>
     */
    public static function agents(?string $preset, array $overrides = []): array;

    /**
     * The file. $config:
     *   ['disallow' => ['/panel', '/anruf'],          // paths for the * group; [] = none
     *    'sitemap'  => 'https://example.test/sitemap.xml' | null,
     *    'agents'   => ['GPTBot' => false, 'ChatGPT-User' => true],   // from agents()
     *    'extra'    => ['# comment', 'Crawl-delay: 5'],               // raw lines, see below
     *    'blockAll' => false]                                          // preview: everything closed
     *
     * Output (lines joined with "\n", file ends with "\n"):
     *   User-agent: *
     *   Allow: /
     *   Disallow: /panel
     *   <blank>
     *   User-agent: GPTBot
     *   Disallow: /
     *   <blank>
     *   User-agent: ChatGPT-User
     *   Allow: /
     *   Disallow: /panel          ← an ALLOWED agent group repeats the * disallows, because
     *                               RFC 9309 §2.2.1 applies only the most specific group, never merges
     *   <blank>
     *   # comment
     *   Crawl-delay: 5
     *   <blank>
     *   Sitemap: https://example.test/sitemap.xml
     *
     * blockAll true → exactly "User-agent: *\nDisallow: /\n" (no sitemap line, no agent groups, no extra).
     * "/" among the disallow paths → the * group is "User-agent: *\nDisallow: /" alone (no "Allow: /": the two
     * rules are of equal length and RFC 9309 §2.2.2 lets the Allow win, so the site stayed open; the other
     * paths are covered); the rest of the file (agent groups, extra, Sitemap) stays. An allowed agent group
     * keeps "Allow: /" and repeats the disallows except "/".
     * Blocked agents are listed before allowed ones, each set sorted by name (stable output for tests).
     * Paths in disallow are emitted verbatim after trim; empty strings are skipped, and so is every entry
     * validPath() rejects (RFC 9309 §2.2.2: must start with "/", no space — the path ABNF excludes it —, no
     * "#" — a comment starts there — and no control character — a line feed would write a rule of its own);
     * warnings() names those.
     * Lines in extra are emitted verbatim (trimmed right). The lines before the first User-agent line
     * belong to the * group (RFC 9309: a blank line does not end a group): without agent groups they are
     * their own block after the * group (as shown above, byte-identical to before); with agent groups they
     * are appended inside the * group and their non-comment lines are repeated in every allowed agent
     * group. From the first User-agent line on, extra lines are own groups, one block after the agent groups.
     * No "Sitemap:" line when sitemap is null/''. Never two consecutive blank lines, no trailing blank line.
     */
    public static function render(array $config): string;

    /**
     * Problems a site owner should know about:
     *  - "Blocks <agent> (<role>): agents in the user's service and AI search engines should stay allowed"
     *    for every blocked agent whose role is 'user' or 'search';
     *  - "Disallow: / for everyone" when blockAll or the * group blocks "/";
     *  - 'Disallow: "<entry>" left out: a path starts with / and holds no space, # or control character, and is UTF-8' (entry
     *    through Text::shown(), so a line feed shows as \n and a C1 control character as \u009b) for every disallow entry
     *    validPath() rejects.
     * @return list<string>
     */
    public static function warnings(array $config): array;

    /** RFC 9309 §2.2.2 path: starts with "/", no space, no "#", no control character (C0, DEL), and UTF-8 (§2.2: the file must be). */
    public static function validPath(string $path): bool;

    /**
     * Minimal parser for future tools (a check that reads a served robots.txt): groups of user agents
     * and their rules. Nothing in Annai itself calls it yet; the tests use it as the oracle for render().
     * Comments (#…) and blank lines are ignored and do not end a group (RFC 9309); a User-agent line after
     * a rule starts the next group; "Sitemap:" records are returned under 'sitemaps'.
     * parse("User-agent: *\nDisallow: /panel\n\nSitemap: https://x/sitemap.xml\n") →
     *   ['groups' => [['agents' => ['*'], 'rules' => [['type' => 'disallow', 'path' => '/panel']]]],
     *    'sitemaps' => ['https://x/sitemap.xml']]
     * Field names are matched case-insensitively ("user-agent", "allow", "disallow", "sitemap").
     */
    public static function parse(string $text): array;
}
```

### 3.4 `Annai\Sitemap` — sitemaps.org XML

```php
final class Sitemap
{
    public const MAX_URLS = 50000;   // sitemaps.org limit per file
    public const MAX_BYTES = 52428800;   // sitemaps.org limit per file, uncompressed
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const NS_XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * One <urlset>. $entries: list<array{loc: string, lastmod?: ?string, alternates?: array<string,string>}>.
     *   loc        absolute URL; escaped with Text::xml()
     *   lastmod    already formatted (lastmod()); omitted when null/''
     *   alternates hreflang code → absolute URL; emitted as <xhtml:link rel="alternate" hreflang=".." href=".."/>,
     *              in the given order; xmlns:xhtml is declared on <urlset> only when any entry has alternates
     * Format (two-space indentation, "\n" line ends, trailing "\n"):
     *   <?xml version="1.0" encoding="UTF-8"?>
     *   <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
     *     <url>
     *       <loc>https://example.test/</loc>
     *       <lastmod>2026-10-11T01:14:00+02:00</lastmod>
     *     </url>
     *   </urlset>
     * An empty list gives a valid empty <urlset>.
     */
    public static function render(array $entries): string;

    /** Absolute http(s) URL with a host, valid UTF-8, no whitespace or control characters. */
    public static function validLoc(string $loc): bool;

    /** W3C datetime as sitemaps.org takes it: YYYY, YYYY-MM, YYYY-MM-DD, or date + time + zone (Z, ±hh:mm). */
    public static function validLastmod(string $lastmod): bool;

    /**
     * Splits into lists of at most $size entries (default MAX_URLS) whose render() is at most
     * $bytes long (default MAX_BYTES; the frame counted with the xhtml namespace, each entry as
     * render() writes it, escaping and alternates included); [] → [[]] (one empty file). An
     * entry longer than $bytes alone still gets a file of its own.
     */
    public static function chunks(array $entries, int $size = self::MAX_URLS, int $bytes = self::MAX_BYTES): array;

    /**
     * <sitemapindex> for several files. $sitemaps: list<array{loc: string, lastmod?: ?string}>.
     *   <?xml version="1.0" encoding="UTF-8"?>
     *   <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
     *     <sitemap>
     *       <loc>https://example.test/sitemap-1.xml</loc>
     *     </sitemap>
     *   </sitemapindex>
     */
    public static function index(array $sitemaps): string;

    /**
     * W3C datetime (DATE_ATOM, "2026-10-11T01:14:00+02:00") from a unix timestamp or a
     * DateTimeInterface, in $zone. null or 0 → null. Never uses Kirby's date handler
     * (with handler `intl`, $page->modified('c') is an ICU pattern — a bug of earlier copies).
     */
    public static function lastmod(int|\DateTimeInterface|null $time, string $zone = 'UTC'): ?string;
}
```

### 3.5 `Annai\LlmsTxt` — llmstxt.org markdown

```php
final class LlmsTxt
{
    /**
     * $model (every key optional except title):
     *   ['title'    => 'Testhof',                             // H1 (Text::line)
     *    'summary'  => 'Bio-Hofladen mit Café',               // "> " quote; '' = none
     *    'intro'    => ['Paragraph …', …],                    // free paragraphs after the quote
     *    'contact'  => ['address'   => 'Musterweg 1, 12345 Musterstadt',   // '' = none
     *                   'phone'     => '030 / 34567000', 'phoneE164' => '+493034567000',
     *                   'email'     => 'hof@example.test',
     *                   'action'    => ['name' => 'Online bestellen', 'target' => 'https://shop.example/testhof'] | null,
     *                   'extra'     => ['- Fax: …']],         // hook list lines appended to Contact (Text::line, not escaped)
     *    'units'    => list<unit>  (§3.8 shape: area, place, name, primary, days, holidays, closures),
     *    'events'   => [['title' => 'Retreat', 'url' => 'https://…', 'when' => 'Fr, 12.3.2027, 18:00 Uhr',
     *                    'venue' => 'Beispielhof' | null, 'cancelled' => false], …],
     *    'sections' => [['title' => 'Im Notfall', 'lines' => ['- 112: …']], …],   // before Pages
     *    'pages'    => [['title' => 'Hofladen', 'url' => 'https://…/laden', 'note' => 'Gemüse vom eigenen Feld.'], …],
     *    'optional' => [['title' => 'Impressum', 'url' => '…', 'note' => ''], …]]
     *
     * Output (sections separated by one blank line, file ends with "\n"), with I18n texts (§5):
     *   # Testhof
     *
     *   > Bio-Hofladen mit Café
     *
     *   ## Kontakt                                  ← I18n llms.contact; whole section omitted when all empty
     *
     *   - Adresse: Musterweg 1, 12345 Musterstadt        ← llms.address
     *   - Telefon: 030 / 34567000 (tel:+493034567000)     ← llms.phone; "(E.164)" only when phoneE164 !== ''
     *   - E-Mail: hof@example.test                  ← llms.email
     *   - Online bestellen: https://shop.example/testhof   ← action name (fallback I18n llms.action) + target
     *
     *   ## Öffnungszeiten                           ← llms.hours; omitted without units
     *
     *   ### Hofladen                                ← only when count(units) > 1 and the unit has a name
     *
     *   - Adresse: Bahnhofstraße 2, 12346 Musterdorf  ← only for a unit with `place` (another location):
     *   - Telefon: 030 34567070 (tel:+493034567070)                    its address and phone as in Kontakt, then a blank line
     *
     *   - Montag: 8:00–12:00, 14:00–18:00 Uhr       ← I18n::day(0) + ": " + t('llms.hoursLine', ['hours' => "8:00–12:00, 14:00–18:00"])
     *   - Dienstag: geschlossen                     ← llms.closed when blocks === []
     *   - Samstag: 8:00–12:00 Uhr (nur Verkauf)     ← note appended as " (…)" (Text::line)
     *   - Gesetzliche Feiertage: geschlossen        ← llms.holidaysClosed when unit.holidays
     *   - 31.12.2027 (Silvester): geschlossen       ← closure: from === to → I18n::date(from); else t('llms.range', from, to)
     *   - 24.12.2027 (Heiligabend): nur 8:30–12:00 Uhr   ← hours !== null → t('llms.onlyHours', ['hours' => range])
     *   - 2.8.2027 bis 15.8.2027 (Betriebsferien): geschlossen, Hinweistext   ← ", " + note when note
     *
     *   ## Termine                                  ← llms.events; omitted without events
     *
     *   - [Retreat](https://…): Fr, 12.3.2027, 18:00 Uhr, Beispielhof   ← "when" then ", venue"; cancelled → " (abgesagt)" (llms.cancelled)
     *
     *   ## <section title>                          ← sections, hook lines (Text::line, not escaped)
     *
     *   ## Seiten                                   ← llms.pages; always present
     *
     *   - [Startseite](https://example.test)        ← FIRST pages entry is given by the caller (Pages::menu adds home with t('llms.home'))
     *   - [Hofladen](https://…/laden): Gemüse vom eigenen Feld.   ← ": note" only when note !== ''
     *
     *   ## Optional                                 ← llms.optional; omitted when empty
     *
     *   - [Impressum](https://…/impressum)
     *
     * Every title/note/line goes through Text::line(). Link text: "[" and "]" in titles are replaced by "(" and ")"; an empty title (also from a hook entry) takes the URL as the label, never "- [](…)".
     * Panel text (title, notes, names, labels, contact, events) through plain(): invalid UTF-8 → U+FFFD, then
     * html_entity_decode, control characters (except whitespace) removed, tag-shaped text removed
     * (`<` + letter, `/`, `!` or `?` up to the next `>` or the end — not strip_tags(), which drops all text after
     * "Kinder <6"), repeated until stable; "<"/">" → "\<"/"\>", "[" "]" → "(" ")". The H1 is heading(), also
     * the whole file in preview.
     * Link targets: " ", "(", ")", "<", ">" percent-encoded. Summary: the same decoding first, then every inline
     * link keeps its target only when it (lower-cased, without whitespace/controls) starts with http://, https://,
     * mailto:, tel:, / or # (allowlist); otherwise its label only; all other text escaped as plain().
     * The link pattern is possessive and linear: one level of brackets in a label, up to three levels of
     * parentheses in a bare target (CommonMark's minimum); a deeper target is no link. When PCRE gives up
     * anyway (backtrack or JIT stack limit) the whole summary is escaped: no link, the text stays.
     * Every pattern of plain() is linear as well: the opener run `(?<!<)<++(?=…)` is anchored at the first
     * "<" of a run and possessive (50,000 "<" or "&lt;" well under a second, it used to be 73 s).
     * sections, intro and contact.extra (hook lines) follow ONE rule: Text::line() (one line, trimmed,
     * '' dropped), nothing escaped — contact.extra used to be rtrim() alone.
     */
    public static function render(array $model): string;

    /** "# Name" through plain(); render()'s H1 and the preview file (Routes::llmsText()). */
    public static function heading(string $name): string;

    /** Day lines for one unit (the "- Montag: …" block incl. holidays and closures), used by render(). @return list<string> */
    public static function unitLines(array $unit): array;
}
```

### 3.6 `Annai\JsonLd` — schema.org arrays and encoding

```php
final class JsonLd
{
    public const CONTEXT = 'https://schema.org';
    public const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The business node (no @context) from Business::facts() (§3.8), pruned with Text::prune().
     *   ['@type' => $facts['type'],
     *    '@id' => $facts['url'] . '/#' . $facts['entityId'],          // 'business' by default
     *    'name' => …, 'description' => …, 'url' => $facts['url'], 'image' => $facts['image'],
     *    'telephone' => $facts['phoneE164'], 'faxNumber' => $facts['faxE164'], 'email' => …,
     *    'address' => ['@type' => 'PostalAddress', 'streetAddress' => street, 'postalCode' => …,
     *                  'addressLocality' => city, 'addressCountry' => country]   // null when street AND city are ''
     *    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => float, 'longitude' => float] | null,
     *    'openingHoursSpecification' => primary unit schema[...] ?? [],
     *    'specialOpeningHoursSpecification' => primary unit schema[...] ?? [],
     *    'sameAs' => list<string>,
     *    'department' => [ for every unit with primary false that has an area or a location:
     *        ['@type' => type, '@id' => url . '/#' . fragment   // the bare area for a unit with `atBusiness` (§3.8: no address
     *                                                           // of its own, or the business page); else location id ("/" → "-")
     *                                                           // [. '-' . area] — a unit with `place` (another location),
     *                                                           // a page with an address of its own, or a unit without an area
     *         'name' => name !== '' ? name . ' — ' . unit.name : unit.name,
     *         'address' => same address, or the unit's `place` address, 'telephone' => phoneE164, or place phoneE164,
     *         'openingHoursSpecification' => unit schema, 'specialOpeningHoursSpecification' => unit schema] ],
     *    'potentialAction' => ['@type' => action.type ?? 'Action', 'name' => action.name,
     *                          'target' => ['@type' => 'EntryPoint', 'urlTemplate' => action.target]] | null]
     * Returns [] when $facts['type'] === false or $facts['name'] === ''.
     */
    public static function localBusiness(array $facts): array;

    /**
     * Wraps nodes into one document. [] → null. One node → ['@context' => CONTEXT] + node.
     * Several → ['@context' => CONTEXT, '@graph' => nodes] with each node's own '@context' removed.
     * Nodes that are not arrays or are [] are dropped first.
     */
    public static function document(array $nodes): ?array;

    /** json_encode($document, self::FLAGS); invalid UTF-8 becomes U+FFFD; throws JsonException on failure (INF, NAN, depth; JSON_THROW_ON_ERROR added). */
    public static function encode(array $document): string;

    /** '<script type="application/ld+json">' . encode() . '</script>'. */
    public static function script(array $document): string;
}
```

### 3.7 `Annai\Head` — the `<head>` model and tags

#### 3.7.1 Input and model

```php
final class Head
{
    /**
     * Normalises Business::head() input (§3.8) into the model the snippet and own markup use.
     * $input:
     *   ['title' => 'Testhof · Bio-Hofladen', 'description' => '…', 'canonical' => 'https://…',
     *    'robots' => 'noindex, follow' | 'noindex, nofollow, noarchive' | null,
     *    'ogType' => 'website', 'siteName' => 'Testhof' | '', 'locale' => 'de_DE' | null,
     *    'image' => ['url' => 'https://…/teilen.jpg', 'width' => 1200|null, 'height' => 630|null, 'alt' => '…'|''] | null,
     *    'twitterCard' => 'summary_large_image' | null,
     *    'alternates' => ['de' => 'https://…/de/…', 'en' => '…', 'x-default' => '…'],
     *    'jsonld' => ?array (JsonLd::document())]
     * Model (all keys present):
     *   ['title' => string, 'description' => string, 'canonical' => string, 'robots' => ?string,
     *    'og' => ['og:title' => …, 'og:description' => …, 'og:type' => 'website', 'og:url' => canonical,
     *             'og:image' => …, 'og:image:width' => '1200', 'og:image:height' => '630', 'og:image:alt' => …,
     *             'og:site_name' => …, 'og:locale' => …]        // only keys with a non-empty value; og:image:* only with an og:image URL
     *    'twitter' => ['twitter:card' => …] | [],
     *    'alternates' => [['hreflang' => 'de', 'href' => …], …],
     *    'jsonld' => ?array,
     *    'tags' => list<string>]                                  // tags($model) result, for convenience
     */
    public static function build(array $input): array;

    /**
     * HTML tags in this order (one string each, no indentation, attribute values via Text::esc()):
     *   <title>…</title>                                  (only when title !== '')
     *   <meta name="description" content="…">             (only when description !== '')
     *   <meta name="robots" content="…">                  (only when robots !== null)
     *   <link rel="canonical" href="…">
     *   <link rel="alternate" hreflang="de" href="…">     (one per alternate)
     *   <meta property="og:…" content="…">                (in model order)
     *   <meta name="twitter:card" content="…">
     *   <script type="application/ld+json">…</script>     (JsonLd::script, only when jsonld !== null)
     * $options: ['title' => bool] (false drops the <title>, default true).
     * @return list<string>
     */
    public static function tags(array $model, array $options = []): array;

    /** implode("\n", tags()) . "\n"; '' for an empty tag list. */
    public static function render(array $model, array $options = []): string;
}
```

#### 3.7.2 Title and description rules (implemented in Business::head(), §3.8)

- Title: home page → brand (+ `' · ' . tagline` when tagline non-empty);
  other page → `title . ' · ' . brand`; brand empty → site title. Hook
  `sayamaapps.annai.title` (Closure) replaces the whole rule.
- Description: page `intro` field (`einleitung`), else site `description`
  field (`beschreibung`), rendered with `$field->kirbytext()` (no raw Markdown or Kirbytags in the
  result), then
  `Text::excerpt(…, descriptionLength)`. With `sayamaapps.annai.descriptionFallback` on, an
  chain is intro, then the page's description field (the same name as the site's
  `description`, `sayamaapps.annai.fields`), then the site description. Each step gives way to
  the next only while its plain text is shorter than `Check::DESCRIPTION_MIN` (50)
  and the next one is longer (the check's minimum, one constant). Without the option
  the page's description field is not read.
  Hook `sayamaapps.annai.description` replaces the rule: its result is plain text, passed through `Text::esc()` before
  `Text::excerpt()` (which strips tags and decodes entities for HTML input), so
  it is excerpted, not rendered — `Kinder <6 Jahre` keeps its text and an
  `&amp;` stays as written (`strip_tags()` used to eat everything
  from a literal `<` on).
- Canonical: `$page->url()` (never query parameters); hook `sayamaapps.annai.canonical`.
  On a multi-language site a page without content in the current language
  (`Pages::hasContentIn()` false; Kirby renders it with the default language's
  content) points to `Pages::contentUrl()`: the default language's URL when it
  has content, else the first listed language's with content — the x-default
  of `languageAlternates()`, with or without `sayamaapps.annai.hreflang` (it
  used to point to itself, a duplicate in every untranslated language). The
  hook keeps its precedence; a hook returning `null` leaves the rule in place.

#### 3.7.3 Snippet `snippets/annai/head.php`

```php
<?php snippet('annai/head') ?>                                   // uses $page->annaiHead()
<?php snippet('annai/head', ['title' => false]) ?>               // site prints its own <title>
<?php snippet('annai/head', ['head' => $model]) ?>               // explicit model (tests, own data)
```

Prints `Head::render($head ?? $page->annaiHead(), ['title' => $title ?? true])`;
prints nothing when the model is null (Annai off). No charset, viewport,
theme-color, icons, fonts or stylesheets: those stay in the site's head. The
snippet produces no classes (it is head markup); a site overrides it with
`site/snippets/annai/head.php`.

### 3.8 `Annai\Business` — the facts of the business

```php
final class Business
{
    /**
     * The location page: option `sayamaapps.annai.location` (page id string, or Closure(): ?Page),
     * else $site->norenLocation() when Noren is installed (function_exists('noren_option')) and
     * `sayamaapps.noren.active` is true and `sayamaapps.annai.noren` is true, else the first page whose intendedTemplate
     * is in Options::locationTemplates() ('betrieb' by default): the top-level pages first, then
     * their children, and only when neither level holds one the rest of $site->index() (building
     * the index reads every page folder: seven to ten times the time of a page view with 2,000 posts).
     * The template search runs once per site object (a WeakMap, one request). Drafts are never
     * returned. null when nothing matches.
     */
    public static function location(App $kirby): ?Page;

    /**
     * true when location() came to the template search and found nothing in the top two
     * levels, so it searched the whole $site->index(): Check reports Check::LOCATION_PROBLEM.
     * Also true with Noren in use and `sayamaapps.noren.locations` unset (null or false, Noren's rule):
     * then $site->norenLocation() walks $site->index() itself on every request.
     * That case is tested first, before `sayamaapps.annai.location`: an `sayamaapps.annai.location` spares Annai's
     * lookup, not Noren's walk for the hours (units()).
     */
    public static function searchesIndex(App $kirby): bool;

    /**
     * The Noren case of searchesIndex(): Noren used and `sayamaapps.noren.locations` unset, whatever
     * `sayamaapps.annai.location` says. Check reports Check::NOREN_LOCATIONS_PROBLEM for it.
     */
    public static function norenSearchesIndex(App $kirby): bool;

    /**
     * One business field by its English key (§6): the location page's field when it is not
     * empty, else the site field of the same (configured) name, trimmed. '' when both are empty.
     * A site that keeps address and phone in site.txt needs no location page.
     */
    public static function field(App $kirby, string $key): string;

    /**
     * Everything the outputs need, read once per call (no memoisation across requests):
     *   ['name'        => brand field else $site->title(),         // Text::line()
     *    'tagline'     => tagline field,
     *    'description' => site description field rendered with kirbytext() and reduced to plain text
     *                     (Text::plain(): tags stripped, entities decoded, whitespace collapsed), NOT
     *                     excerpted; the JSON-LD description and the llms.txt summary,
     *    'url'         => Pages::base() (Kirby's base URL, the same in every language),
     *    'image'       => absolute URL string | null   (resolveImage() for the home page),
     *    'phone'       => display string (Text::line()), 'phoneE164' => Text::phone(RAW field, phoneCountry) — '' and
     *                     Options::warn()ed with the reason of Text::phoneProblem() whenever the field is not empty
     *                     (private e164(), also for fax and for place() of another location; §3.2, the input
     *                     contract), the value as JSON: 'characters' ("field telefon holds more than the number (…) —
     *                     nothing is published as E.164; write only the number: digits, spaces, a leading +, "/", "-"
     *                     and parentheses. Notes go into another field."), 'invalid' ("field telefon holds no valid
     *                     phone number (…) — nothing is published as E.164; write the whole number with its area code
     *                     (a national number counts for +49, sayamaapps.annai.phoneCountry) or with its country code (+…)."),
     *                     'library' ("phone numbers need giggsey/libphonenumber-for-php-lite, which is not installed —
     *                     nothing is published as E.164; install Annai with Composer (README, Installation)."),
     *    'fax'         => …, 'faxE164' => …, 'email' => …,
     *    'street' => …, 'postalCode' => …, 'city' => …, 'country' => Options country ('DE'),
     *    'geo'         => ['lat' => 52.52, 'lng' => 13.41] | null   (Options::geo()),
     *    'sameAs'      => list<string>: Options sameAs (list or Closure) + profile fields (§6), '' dropped, unique;
     *                     http(s) only; an entry with a space or a control character (C0, DEL, C1 after
     *                     Text::utf8()) is warned and left out, like one without a scheme;
     *                     http(s) URLs only — any other value ("@testhof", "facebook.com/x") is Options::warn()ed
     *                     with its source (option or field name) and left out; the warning shows the
     *                     entry through Text::shown(), a C1 control character as \u009b,
     *    'action'      => ['type' => 'OrderAction', 'name' => 'Online bestellen', 'target' => 'https://…'] | null,
     *    'type'        => 'LocalBusiness' | 'GroceryStore' | false,
     *    'entityId'    => 'business' (Options),
     *    'units'       => units($kirby), or [] with $withUnits false]
     * [] when Annai is off.
     * $withUnits false leaves Noren alone: head() and jsonLd() ask for the units only
     * where they are shown (showsUnits(): the home page, or an `sayamaapps.annai.jsonld` hook, which gets the
     * facts on every page), and the preview llms.txt (name only) never. Every norenSchema() call
     * switches Kirby's page cache off for the response, so with Noren installed every page used
     * to lose its cache entry. Routes::llmsModel(), annaiFacts() and annaiUnits() read them always.
     */
    public static function facts(App $kirby, bool $withUnits = true): array;

    /** $page->isHomePage() || `sayamaapps.annai.jsonld` is a Closure: the head of this page shows the units. */
    private static function showsUnits(App $kirby, Page $page): bool;

    /**
     * Opening hours from Noren, as plain arrays. [] when Noren is not installed, not active,
     * `sayamaapps.annai.noren` is false, or there are no maintained plans. One entry per $site->norenUnits()
     * item, in Noren's order, minus the units of a draft location page (Pages::isDraft(), warned
     * once: Noren's $kirby->page() finds drafts); the primary unit first:
     *   primary = among the units at the business location (place null) the one whose area equals
     *   `sayamaapps.annai.area`, else the first of them; none when no unit is there. Its hours go into the
     *   business node next to the business address, so a unit at another location is never primary:
     *   an `sayamaapps.annai.area` naming only such a unit is warned and the unit stays a department; an
     *   `sayamaapps.annai.area` matching no unit at all is warned too (it used to fall back in silence).
     *   ['area'     => 'laden' | null, 'location' => page id of the unit's Noren location | null,
     *    'place'    => null, or for a unit at another location page than location():
     *                  that page's own street, postalCode, city, phone, phoneE164 (no site fallback).
     *                  "Another location" = the page carries an address (street or city) that differs
     *                  from the business address as facts() reads it: location()'s fields, else the
     *                  site's — also without a business page (`fn () => null`; every Noren page
     *                  used to count as at the business then, and a second location became a department
     *                  with the main address),
     *    'atBusiness' => bool: place null and (the unit's page is location(), Noren has no page, or the page
     *                  carries no address of its own — the hours on one page, the address on `sayamaapps.annai.location`,
     *                  in site.txt, or none at all with `fn () => null`). Its department @id is the bare area
     *                  key (§3.6). A page with an address of its own, even the business's, is false, so two
     *                  pages with the same area key keep distinct @ids,
     *    'name'     => 'Hofladen': Noren's `name`; as soon as the units come from more than one location
     *                  page, Noren's `label` ("Praxis Nord · Praxis", `<location short> · <area short>`),
     *                  so two locations with the same area name do not give two identical `### Praxis`
     *                  headings in llms.txt and department names in the JSON-LD,
     *    'primary'  => true,
     *    'days'     => [0 => ['blocks' => [[480, 720], [840, 1080]], 'note' => 'nur Verkauf' | null], … 6],
     *                  // $schedule->blocks($weekday) and ->note($weekday), 0 = Monday
     *    'holidays' => $site->norenHolidays() !== [],
     *    'closures' => [['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => $schedule->label($closure),
     *                    'hours' => [510, 720] | null, 'note' => string | null], …]   // ->upcomingClosures(), ALL upcoming (no lead time)
     *    'schema'   => $unit['location']->norenSchema($area)  // the unit's own location page; $site->norenSchema()
     *                  // reads the first location only. ['openingHoursSpecification' => [...], 'specialOpeningHoursSpecification' => [...]]
     *   ]
     * Mixing areas into one plan is wrong (laden + cafe hours would merge into one day), so a unit
     * is never built from norenSchedule(null) when areas exist — norenUnits() already separates them.
     * Noren switches the page cache off itself when it computes time-dependent data, so units() is
     * called only where hours are shown: facts($kirby, true) — the home page head, an `sayamaapps.annai.jsonld`
     * hook, llms.txt, annaiFacts(), annaiUnits() (see facts()).
     */
    public static function units(App $kirby): array;

    /**
     * The JSON-LD document for a page, or null:
     *   nodes = []
     *   if $page->isHomePage() and type !== false: nodes[] = JsonLd::localBusiness(facts)   (skipped when [])
     *   hook `sayamaapps.annai.jsonld` (Closure(Page $page, array $nodes, array $facts): array) may add, replace or remove nodes
     *   return JsonLd::document(nodes)
     * null when Annai is off.
     */
    public static function jsonLd(App $kirby, Page $page, ?array $facts = null): ?array;   // $facts: head() passes its own, else facts($kirby, showsUnits())

    /**
     * Head::build() input for a page (§3.7.1), with the rules of §3.7.2:
     *   title, description (excerpted), canonical, robots (Pages::robots($kirby, $page)),
     *   ogType 'website', siteName = facts name, locale = Options::locale($kirby),
     *   image = resolveImage($kirby, $page), twitterCard = Options twitterCard,
     *   alternates = Pages::alternates($kirby, $page), jsonld = jsonLd($kirby, $page) — or null, Options::warn()ed,
     *   when JsonLd::encode() throws on it (NAN/INF from a hook, depth): the page renders without JSON-LD.
     * facts($kirby, showsUnits($kirby, $page)): the title and description hooks see `units` [] on a page
     * other than the home page when no `sayamaapps.annai.jsonld` hook is set.
     * [] when Annai is off.
     */
    public static function head(App $kirby, Page $page): array;

    /**
     * `sayamaapps.annai.image`: null → null; string → absolute URL (http(s) kept, else Kirby url()); array
     * ['url' => …, 'width' => …, 'height' => …, 'alt' => …] → same with url resolved; Closure(Page): string|array|null.
     * Returns ['url' => string, 'width' => ?int, 'height' => ?int, 'alt' => string] or null.
     * url and alt go through Text::line() (the alt from $page->image()->alt() used to reach og:image:alt
     * raw, the one head text without the control-character filter); canonical() treats the hook's URL the same.
     */
    public static function resolveImage(App $kirby, Page $page): ?array;
}
```

### 3.9 `Annai\Pages`, `Annai\Routes`

```php
final class Pages
{
    /**
     * Kirby's base URL without a trailing slash (rtrim($kirby->url(), '/')). On a
     * multi-language site $site->url() is the current language's URL (/de); the
     * files and the business @id live at the base URL.
     */
    public static function base(App $kirby): string;

    /**
     * Excluded = isDraft(), or intendedTemplate in `sayamaapps.annai.exclude.templates` (default ['error']),
     * or id in `sayamaapps.annai.exclude.pages` (list of ids) or below one of them (id . '/' prefix, whole
     * segments), or `sayamaapps.annai.exclude.pages` is a Closure(Page): bool returning a truthy value
     * ((bool) cast). The error page is excluded by template.
     */
    public static function excluded(App $kirby, Page $page): bool;

    /**
     * $page->isDraft(), or a parent ($page->parents()) is a draft: a page below a draft is as
     * unpublished as the draft (404 for anyone but an editor), but Kirby's isDraft() is false
     * for it. Every draft test of Annai (excluded(), robots(), Business::location()) uses this.
     */
    public static function isDraft(Page $page): bool;

    /** $site->index() (no drafts) minus excluded(), in index order. [] when Annai is off. @return list<Page> */
    public static function all(App $kirby): array;

    /**
     * Preview protection active? `sayamaapps.annai.noindex` true → true; false → false; any other
     * non-null value → Options::warn() and treated as null; null →
     * class_exists('Sudare\\Sudare') && (\Sudare\Sudare::active() === true
     *   || (PHP_SAPI === 'cli' && \Sudare\Sudare::protects($base) === true)),
     * $base = root 'base', else dirname(root 'site'); each method only when it exists.
     * CONTRACT with the Sudare package: static `Sudare\Sudare::active(): bool` (request
     * state: false before the guard ran, true after guard()/handle() with a switched-on
     * configuration — also for an exempt path, Sudare 1.0.0 remembers the Config before
     * Guard::handle() decides; §7) and `Sudare\Sudare::protects(string $base): bool`
     * (stateless, for the CLI where no guard runs). Anything else → false.
     * true → `$kirby->response()->cache(false)` (like Noren): a page rendered during the preview
     * must not be served from Kirby's page cache with its noindex after the preview has ended.
     */
    public static function preview(App $kirby): bool;

    /**
     * The robots meta value for a page, or null:
     *   preview() → 'noindex, nofollow, noarchive'
     *   else isDraft() (a draft or a page below one) → 'noindex, nofollow' (regardless of `sayamaapps.annai.noindexExcluded`)
     *   else excluded() && `sayamaapps.annai.noindexExcluded` (default true) → 'noindex, follow'
     *   else null
     */
    public static function robots(App $kirby, Page $page): ?string;

    /**
     * hreflang map for a page: [] unless $kirby->multilang() and `sayamaapps.annai.hreflang` is true, and []
     * when robots($kirby, $page) is not null (a noindex page offers no language versions).
     * entries() reads the private languageAlternates() instead — the same map without the robots()
     * rule — so the sitemap data behind a preview counts one entry per language, as served after
     * the go-live (the route serves nothing during the preview anyway).
     * Then [code => $page->url(code)] for every language in which the page has content
     * ($page->translation(code)->exists(); for the default language also is_file() of
     * $page->version('latest')->contentFile(): Kirby 5 counts the default language as existing
     * whenever the page folder exists), plus 'x-default' => the default language URL, or the
     * first listed language's when the page has no default-language content.
     * Order: default language first, then the others in Kirby order, x-default last.
     */
    public static function alternates(App $kirby, Page $page): array;

    /**
     * The URL the canonical of a page points to when the page has no content in the current
     * language: the default language's URL when the page has content in it, else the
     * first listed language's with content (the x-default of languageAlternates(), whatever
     * `sayamaapps.annai.hreflang` says). null on a single-language site, when the page has content in the
     * current language, or in no language. Business::canonical() and Check::page() read it, and
     * every list that names a page by its URL follows the same rule (`contentUrl() ?? $page->url()`):
     * entries() without hreflang, llmsModel() (home, menu, optional) and events() (they
     * used to list the current language's URL, whose canonical points elsewhere).
     * Built as $page->url(contentLanguage()).
     */
    public static function contentUrl(App $kirby, Page $page): ?string;

    /** The language code behind contentUrl() (one rule); null in the same cases. */
    public static function contentLanguage(App $kirby, Page $page): ?string;

    /**
     * The content and title of the language version contentLanguage() names ($code null: the current
     * language's; the title falls back to that language's slug, like Kirby's title()). A text next to
     * a page link comes from the version the link names (llms.txt used to link /en/english with
     * the slug of the empty German version as title and without its note).
     */
    public static function contentIn(Page $page, ?string $code): Content;
    public static function titleIn(Page $page, ?string $code): string;

    /**
     * Does the page have a content file in this language? $page->translation(code)->exists(), and for the
     * default language also is_file() of $page->version('latest')->contentFile() (Kirby 5 counts the
     * default language as existing whenever the page folder exists); a closed contentFile() keeps
     * Kirby's answer. Public because contentUrl() reads it too.
     */
    public static function hasContentIn(Page $page, Language $language): bool;

    /**
     * Sitemap lastmod for a page: Sitemap::lastmod($page->modified(null, null, $language), Options::timezone()) — the
     * INTEGER modified(), never modified('c'); $language null = the current language, a code = that
     * language's content file (the hreflang entries). Koyomi rule: when Koyomi is installed
     * (function_exists('koyomi_active')) and active and `sayamaapps.annai.koyomi` is true and $page is a
     * Koyomi\Models\EventPage whose koyomi() event isPast(koyomi_now()), lastmod = the later of
     * modified and the event's end. null when `sayamaapps.annai.sitemap.lastmod` is false.
     */
    public static function lastmod(App $kirby, Page $page, ?string $language = null): ?string;

    /**
     * Sitemap entries (§3.4 shape plus 'page'):
     *   for every page in all(): ['loc' => $page->url(), 'lastmod' => lastmod(), 'alternates' => languageAlternates(), 'page' => Page];
     *   without alternates the loc is the canonical URL: with $code = contentLanguage() not null
     *   loc = $page->url($code) and lastmod = lastmod($kirby, $page, $code)
     *   with hreflang on a multilang site: one entry per language the page exists in (loc = that language URL,
     *   lastmod = lastmod($kirby, $page, $code) of that language's content file, same alternates), also while
     *   the preview is on (Check::site() and $site->annaiSitemap() count what is served after the go-live)
     *   plus `sayamaapps.annai.sitemap.extra` (list, or Closure(): list) entries ['loc' => …, 'lastmod' => ?string, 'alternates' => []] with 'page' => null;
     *   extra entries whose loc fails Sitemap::validLoc() are skipped and Options::warn()ed; a lastmod
     *   that fails Sitemap::validLastmod() is dropped (entry kept) and warned.
     * @return list<array{loc: string, lastmod: ?string, alternates: array<string,string>, page: ?Page}>
     */
    public static function entries(App $kirby): array;

    /** Listed top-level pages ($site->children()->listed()) minus the home page (llmsModel() writes it first) minus excluded() — the "Pages" list of llms.txt. @return list<Page> */
    public static function menu(App $kirby): array;

    /** Pages (any status but draft) whose template is in `sayamaapps.annai.llms.optional`, minus excluded(), minus menu(). @return list<Page> */
    public static function optional(App $kirby): array;

    /**
     * Upcoming Koyomi events for llms.txt, [] unless Koyomi is installed, active and `sayamaapps.annai.koyomi` true:
     *   $calendar = Koyomi\Repository::calendar($kirby); Koyomi\Repository::upcoming($calendar)
     *   koyomi_i18n() once, then per event:
     *   ['title' => titleIn($page, contentLanguage()) of its event page ?? $event->title — the title of the language
     *    version the link points to, an empty one the slug of that version, as for every page link (an earlier
     *    version kept Koyomi's '' and llms.txt wrote "- [](…)"),
     *    'url' => contentUrl() of its event page ?? $event->url, 'when' => Koyomi\Format::when($event),
     *    'venue' => $event->physicalVenue()?->label(), 'cancelled' => $event->isCancelled(),
     *    'start' => $event->start, 'end' => $event->end]
     * Events whose url is '' are skipped, and so are events whose page (one of
     * Koyomi\Repository::eventPages($calendar), matched by URL) is excluded().
     */
    public static function events(App $kirby): array;
}

final class Routes
{
    /** Robots::render() input: disallow, sitemap (Pages::base() . '/sitemap.xml'), agents (Options robotsAi + robotsAgents), extra, blockAll = Pages::hidden('/robots.txt'). */
    public static function robotsConfig(App $kirby): array;

    /** Robots::render(robotsConfig()). */
    public static function robotsText(App $kirby): string;
    // sitemap() and llmsText(): while Pages::hidden($kirby, path) (explicit sayamaapps.annai.noindex => true, or the
    // route exempt from the protection) an empty <urlset> and "# <name>\n"; a closed chunk
    // sitemap-<n>.xml is null → 404 (nothing to split). A protected preview serves the normal files.
    // robots(), sitemap() and llms(): while Pages::preview() is true, the Response
    // carries `Cache-Control: no-store` — a CDN in front of an exempted route must not keep the
    // robots.txt or the sitemap past the go-live (Kirby's cache(false) covers its page cache only).

    /** Response text/plain. */
    public static function robots(App $kirby): Response;

    /**
     * $chunk null: one file when count(entries) <= chunk size, else a <sitemapindex> whose
     * entries are Pages::base() . '/sitemap-' . $n . '.xml' (n from 1), lastmod = the newest lastmod of that chunk
     * (compared as instants; "2000" counts as 2000-01-01, "2000-05" as 2000-05-01 — PHP reads a bare year as a time today).
     * $chunk n: the n-th chunk (1-based), or null (→ 404 via $this->next()) when out of range or when only one file exists.
     * Chunk size: `sayamaapps.annai.sitemap.chunk` (default and cap Sitemap::MAX_URLS, an int from 1 — small values are for tests;
     * anything else is warned and gives the default, it used to be 1 URL per file); every file also stays within Sitemap::MAX_BYTES.
     * Response application/xml.
     */
    public static function sitemap(App $kirby, ?int $chunk = null): ?Response;

    /**
     * LlmsTxt::render() input (§3.5) from Business::facts() and Pages:
     *   title = facts name, else the host of Pages::base() (private siteName(), also for the preview H1 of llmsText();
     *           '' only without a host as well — the CLI without --url),
     *   summary = Text::line(facts description) (plain text of the rendered field, §3.8),
     *   contact = address "street, postalCode city" (Text::line of implode(', ', array_filter([street, trim(postalCode . ' ' . city)]))),
     *             phone, phoneE164, email, action (name + target when target !== ''), extra []
     *   units = facts units, events = Pages::events(),
     *   pages = [['title' => t('llms.home'), 'url' => Pages::contentUrl(home) ?? $site->url(), 'note' => '']] (only when the home page
     *           is not excluded()) + Pages::menu() as
     *           ['title' => Pages::titleIn($page, $code), 'url' => Pages::contentUrl($page) ?? $page->url(), 'note' => Text::excerpt((string) intro field of Pages::contentIn($page, $code)->kirbytext(), 160)]
     *           (every url the page's canonical; title and note from the same language version, $code = contentLanguage())
     *   optional = Pages::optional() as the same shape with note ''
     *   then hook `sayamaapps.annai.llms.extra` (Closure(array $model, array $facts): array) may change anything.
     * Options::applyLanguage() is called first (texts follow the site language).
     */
    public static function llmsModel(App $kirby): array;

    /** LlmsTxt::render(llmsModel()). */
    public static function llmsText(App $kirby): string;

    /** Response text/plain. */
    public static function llms(App $kirby): Response;
}
```

Routes are registered in index.php with `'method' => 'GET|HEAD'`:
`robots.txt`, `sitemap.xml`, `sitemap-([1-9][0-9]*)\.xml` (the canonical number
only: `(:num)` also matched `01` and `0001` and served chunk 1 under three
URLs; the dot escaped, so `sitemap-1Xxml` is 404), `llms.txt`. Each action returns `$this->next()` when Annai is off (or
the chunk is out of range).

### 3.10 index.php — site and page methods

| Method | Returns | Delegates to |
|---|---|---|
| `annai_option($key, $default)` | mixed | `Options::get()` |
| `annai_active()` | bool | `Options::active()` |
| `$site->annaiFacts()` | array (`[]` off) | `Business::facts()` |
| `$site->annaiUnits()` | list | `Business::units()` |
| `$site->annaiRobots()` | string (`''` off) | `Routes::robotsText()` |
| `$site->annaiSitemap()` | list of entries (`[]` off) | `Pages::entries()` |
| `$site->annaiLlms()` | string (`''` off) | `Routes::llmsText()` |
| `$site->annaiEvents()` | list | `Pages::events()` |
| `$site->annaiPreview()` | bool | `Pages::preview()` |
| `$page->annaiHead()` | ?array (`null` off) | `Head::build(Business::head())` |
| `$page->annaiJsonLd()` | ?array | `Business::jsonLd()` |
| `$page->annaiIndexable()` | bool (`false` off) | `!Pages::excluded()` |
| `$page->annaiRobots()` | ?string | `Pages::robots()` |
| `$page->annaiAlternates()` | array | `Pages::alternates()` |
| `$page->annaiLastmod()` | ?string | `Pages::lastmod()` |
| `$page->annaiCheck()` | ?array | `Check::page()` |

### 3.11 `Annai\Check` and `bin/annai-check`

```php
final class Check
{
    /**
     * What engines and agents see on one page:
     *   ['url' => …, 'id' => $page->id(), 'template' => …, 'indexable' => bool, 'robots' => ?string,
     *    'title' => ['text' => …, 'length' => mb_strlen], 'description' => ['text' => …, 'length' => …],
     *    'canonical' => …, 'image' => ?string, 'alternates' => int,
     *    'jsonld' => ['types' => ['GroceryStore', …] (from @type or every @graph node), 'valid' => bool],
     *    'problems' => list<string>,
     *    'notes' => [sprintf(Check::NO_CONTENT_NOTE, language code, canonical)] ('no content in de: canonical
     *               points to https://…/en/…') when Pages::contentUrl() is not null and the canonical equals it;
     *               printed by render() as "  ~ …", never counted; [] otherwise]
     * Problems (English, fixed wording, tested):
     *   'title missing' | 'title longer than 60 characters' | 'description missing' |
     *   'description shorter than 50 characters' | 'description longer than <N> characters' (N =
     *   descriptionMax() = Options::descriptionLength() + DESCRIPTION_TOLERANCE 5, 160 by default) |
     *   'canonical differs from the page URL' (not when the canonical is Pages::contentUrl(): that is the
     *   note above) | 'og:image is not an absolute https URL' |
     *   'JSON-LD is not valid JSON'. The robots meta is reported, never a problem: an indexable page
     *   carries noindex only while Pages::preview() (then every page does, and site() says so once,
     *   Check::PREVIEW_PROBLEM); drafts and excluded pages are not indexable.
     * Uses $page->annaiHead() and JsonLd::encode(); never fetches over HTTP.
     * JSON-LD that cannot be encoded (INF/NAN, too deep) is already left out of annaiHead() by
     * Business::head(); the raw document from annaiJsonLd() is what reports 'JSON-LD is not valid JSON'.
     */
    public static function page(App $kirby, Page $page): array;

    /**
     * Site report:
     *   ['robots'  => ['text' => Routes::robotsText() (as served; Disallow: / only while hidden),
     *                  'warnings' => Robots::warnings(robotsConfig() with blockAll false): the warnings of the
     *                  configuration that applies after the preview — a blocked user agent stays visible],
     *    'sitemap' => ['count' => count(entries), 'chunks' => int, 'lastmod' => bool],
     *    'llms'    => ['length' => strlen, 'headings' => ['Kontakt', 'Öffnungszeiten', …] (lines starting with "## ")],
     *                 // of LlmsTxt::render(Routes::llmsModel()): the content, also while the preview serves only the H1
     *    'preview' => Pages::preview(),
     *    'phones'  => list<['field' => 'phone'|'fax', 'raw' => Business::field() (the stored value, trimmed),
     *                 'e164' => facts()[field . 'E164'] or null when '', 'problem' => Text::phoneProblem(raw) when e164
     *                 is null, else null]> for every non-empty phone and fax field, then ['field' => 'phone',
     *                 'page' => <page id>, 'raw' => that page's own phone field, 'e164' => place phoneE164 or null,
     *                 'problem' => …] for every further location page (Business::units() with a `place`, one entry per
     *                 page, a non-empty phone only: they reach the JSON-LD departments and llms.txt too).
     *                 §3.2, the input contract: a person sees every field and its result before the go-live. Printed by
     *                 render() as "  ~ phone: \"030 / 34567000\" → +493034567000" (the raw value through Text::shown(), so a
     *                 line break or a control character shows as an escape), "  ~ fax: \"Fax folgt\" → (none: more than the number)"
     *                 (Check::PHONE_PROBLEMS: 'characters' → more than the number, 'invalid' → no valid number,
     *                 'library' → libphonenumber is not installed; "(none)" without a reason), a further location
     *                 "  ~ phone (b): …"; never counted by exitCode() (a field without a number is a configuration
     *                 problem through its log line),
     *    'notes'   => [Check::NO_HOST_NOTE] when Pages::base() has no host (the CLI without --url: canonical,
     *                 og:url and the Sitemap: line are paths) — printed by render() as "  ~ …", never counted by
     *                 exitCode(); [] otherwise,
     *    'pages'   => list<page()> for Pages::all() (or $only ids),
     *    'problems' => site-level: 'duplicate title: <title> (<id>, <id>)' for titles used by more than one page;
     *                  'no indexable page with id: <id>' for an unknown or excluded --page id;
     *                  Check::NAME_PROBLEM (sprintf, the brand field name: 'name missing: set the brand field (marke)
     *                  or the site title') when facts()['name'] is '';
     *                  Check::PREVIEW_PROBLEM ('preview on: every page carries noindex') once while Pages::preview();
     *                  Check::OLD_PLUGIN_PROBLEM when site/plugins/suchmaschinen still exists;
     *                  Check::STATIC_FILE_PROBLEM (sprintf, file name) for a robots.txt, sitemap.xml or llms.txt
     *                  in root 'index': the web server serves it before Kirby runs (.htaccess !-f);
     *                  Check::NOREN_LOCATIONS_PROBLEM ('Noren runs without sayamaapps.noren.locations: … set
     *                  sayamaapps.noren.locations (sayamaapps.annai.location does not stop it)') when Business::norenSearchesIndex(),
     *                  also with sayamaapps.annai.location set; else Check::LOCATION_PROBLEM ('no business
     *                  page in the top two page levels: … set sayamaapps.annai.location …') when Business::searchesIndex()
     *                  (no sayamaapps.annai.location, no business page in the top two levels: every request walks the
     *                  whole index);
     *                  Check::CONFIGURATION_PROBLEM (sprintf, 'configuration: <message>') for every message
     *                  Options::warn() logged while the report was built (Options::logged() before/after;
     *                  the "Annai: " prefix removed)]
     * @param list<string> $only  page ids; [] = all indexable pages
     */
    public static function site(App $kirby, array $only = []): array;

    /** Options::descriptionLength() + DESCRIPTION_TOLERANCE (5): the description limit, 160 by default. */
    public static function descriptionMax(App $kirby): int;

    /** 'description longer than <descriptionMax()> characters'. */
    public static function descriptionProblem(App $kirby): string;

    /** Human-readable text, one block per page, problems prefixed "  ! ", warnings "  ! ", phones (raw value as JSON → E.164 or "(none: <reason>)"; a further location's with its page id, "  ~ phone (b): …") and notes (site and page) "  ~ " after the preview line, ends with a summary line "N pages, M problems". */
    public static function render(array $report): string;

    /** Exit code: 1 when any page or site problem or robots warning exists, else 0. */
    public static function exitCode(array $report): int;
}
```

`bin/annai-check` (executable, `#!/usr/bin/env php`, CLI only): boots Kirby from a site directory and prints the report.

```
vendor/bin/annai-check [--root=DIR] [--url=https://example.test] [--page=ID ...] [--json]
```

- `--root` defaults to the current directory. The script requires
  `DIR/vendor/autoload.php` (error exit 2 with a message when missing) and
  creates `new Kirby\Cms\App(['roots' => ['index' => DIR/public (when that
  directory exists, else DIR), 'base' => DIR, 'site' => DIR/site, 'content' =>
  DIR/content, 'storage' => DIR/storage]])`, with `'options' => ['debug' => false, 'url' => --url]`
  (`url` only when `--url` is given: absolute URLs need a host on the CLI; without it
  Check::site() adds `Check::NO_HOST_NOTE` to `notes`, printed, not counted). `debug` is
  forced off (App options win over config.php) so that Options::warn() logs instead of
  throwing: a broken option becomes a site problem, the report is complete, exit 1.
- `--json` prints `json_encode(report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
- Exit code: `Check::exitCode()`; 2 for usage errors; 3 when Kirby cannot boot or the check itself throws
  (a broken Annai option is exit 1, see above); when Annai is off it
  prints "Annai is off (sayamaapps.annai.active)" and exits 2.
- `--help` / `-h` prints the usage line and exits 0. `--url` must be an absolute http(s) URL, else usage error (exit 2).
- Usage errors, boot errors and "Annai is off" messages go to STDERR; the report goes to STDOUT.
- `jsonld.valid` is false only when `JsonLd::encode()` throws; a page without JSON-LD is valid with no types.
- No Panel area (§8).

---

## 4. Options

All read as `annai.<name>`; `null` = not set. Boolean options take a real
`true`/`false` (anything else → `Options::warn()`, default used). Typed
readers live in `Options`; call them instead of `get()`
wherever one exists.

| Option | Default | Reader | Meaning |
|---|---|---|---|
| `active` | `false` | `active()` | Master switch; only a real `true` switches Annai on, anything else is logged and counts as `false` |
| `language` | site language | `language()` | `en`/`de` for llms.txt texts; order: option, current language, default language, `locale`, `en` |
| `fields` | §6 | `fields()` | Field name map, only the keys you change |
| `location` | `null` | `location()` | Page id or `Closure(): ?Page` of the business page; anything else warned, counts as unset |
| `locationTemplate` | `'betrieb'` | `locationTemplates()` | Template(s) of the business page when `location` is unset and Noren is not active |
| `noren` | `true` | `flag()` | Use Noren's hours when Noren is active |
| `koyomi` | `true` | `flag()` | Use Koyomi's events when Koyomi is active |
| `type` | `'LocalBusiness'` | `type()` | schema.org type of the business node; `false` = no business node |
| `entityId` | `'business'` | `entityId()` | Fragment of the node `@id` (`url/#business`) |
| `area` | `null` | `area()` | Noren area key whose hours are the main hours |
| `sameAs` | `[]` | `sameAs()` | List of profile URLs, or `Closure(): list`; Business::facts() keeps http(s) URLs without a space or a control character only (others warned, left out) |
| `geo` | `null` | `geo()` | `['lat' => 52.52, 'lng' => 13.41]`; also `[52.52, 13.41]` and legacy `['breite' => …, 'laenge' => …]`; → `['lat' => float, 'lng' => float]` or null |
| `action` | `null` | `action()` | `['type' => 'ReserveAction', 'name' => 'Termin buchen', 'target' => 'https://…']`; legacy keys `typ`, `ziel` accepted; → normalised array or null (no target = null) |
| `image` | `null` | `get()` | Share image: path (`'assets/teilen.jpg'`), absolute URL, `['url','width','height','alt']`, or `Closure(Page): string\|array\|null` |
| `country` | `'DE'` | `country()` | `addressCountry` |
| `phoneCountry` | `'49'` | `phoneCountry()` | Country code for national numbers: digits without leading zeros (`'+43'`, `'0043'` → `43`); its region (`49` → `DE`) is the one libphonenumber reads a national number for (§3.2) |
| `title` | `null` | `hook()` | `Closure(Page $page, array $facts): string` replaces the title rule; a set value that is no Closure is warned and ignored (also `description`, `canonical`, `jsonld`, `llms.extra`) |
| `description` | `null` | `hook()` | `Closure(Page $page, array $facts): string` replaces the description source: plain text, excerpted, not rendered (§3.7.2) |
| `descriptionFallback` | `false` | `descriptionFallback()` | `true`: intro → page description field → site description; a step under `Check::DESCRIPTION_MIN` (50) gives way to the next when that is longer; the hook is not affected (§3.7.2) |
| `descriptionLength` | `155` | `descriptionLength()` | Excerpt length of the meta description (ellipsis included): int 20…320; anything else (`'abc'`, `'200'`) warned → 155; outside the range warned → clamped. `Check::descriptionMax()` = this + 5 |
| `canonical` | `null` | `hook()` | `Closure(Page): string` |
| `locale` | `null` | `locale()` | `og:locale`; null → multilang current language locale (`LC_ALL` or first), else Kirby `locale` option (`de_DE.utf8` → `de_DE`), else null (omitted). Normalised to `xx_YY` form; invalid → null |
| `twitterCard` | `null` | `twitterCard()` | `'summary'` or `'summary_large_image'`; anything else → null |
| `noindex` | `null` | `get()` | Preview noindex: `null` auto (Sudare), `true` force, `false` never; anything else warned, counts as `null`. |
| `noindexExcluded` | `true` | `flag()` | `noindex, follow` on pages excluded from the sitemap |
| `hreflang` | `false` | `flag()` | hreflang links and sitemap alternates on a multilang site. |
| `exclude.templates` | `['error']` | `excludeTemplates()` | Templates kept out of sitemap, llms.txt, check |
| `exclude.pages` | `[]` | `excludePages()` | Page ids, each with everything below it (`['intern']` also keeps out `intern/protokoll`), or `Closure(Page): bool` (a truthy result excludes) |
| `disallow` | `['/panel']` | `disallow()` | Paths for `Disallow:` in the `*` group; an entry `Robots::validPath()` rejects (no leading `/`, a space, a `#`, a control character, not UTF-8) is warned here, left out by `Robots::render()` and named by `Robots::warnings()`. `/` makes the group `Disallow: /` alone (§3.3) |
| `robots.ai` | `null` | `robotsAi()` | `null`, `'blockTraining'`, `'allowAll'` (§3.3). |
| `robots.agents` | `[]` | `robotsAgents()` | `['GPTBot' => false, 'CCBot' => true]` overrides; a name that is not `^[A-Za-z0-9_.-]+$` (after trim) is warned and dropped, `'*'` too |
| `robots.extra` | `[]` | `robotsExtra()` | Raw lines for the `*` group (repeated for allowed agents); from a `User-agent:` line on, own groups. An element with line feeds inside is split into lines first (`robotsExtra()` and `Robots::extra()`); a byte that is not UTF-8 becomes U+FFFD (`Text::utf8()`, RFC 9309 §2.2) |
| `sitemap.lastmod` | `true` | `flag()` | Emit `<lastmod>` |
| `sitemap.chunk` | `50000` | `sitemapChunk()` | URLs per file: int 1…50000; anything else (`false`, `'abc'`, `0`, `'100'`) warned → 50000; above 50000 warned → 50000 |
| `sitemap.extra` | `null` | `get()` | List or `Closure(): list` of extra entries |
| `jsonld` | `null` | `hook()` | `Closure(Page $page, array $nodes, array $facts): array` |
| `llms.optional` | `[]` | `llmsOptional()` | Templates listed under "Optional" (e.g. `['rechtstext']`) |
| `llms.extra` | `null` | `hook()` | `Closure(array $model, array $facts): array` |
| `timezone` | `'Europe/Berlin'` | `timezone()` | Zone of `<lastmod>` offsets; invalid → warned, default |

Rename table from the earlier keys (README "Upgrading"): `suchmaschinen.ausgeschlossen` →
`sayamaapps.annai.exclude.templates`; `suchmaschinen.gesperrt` → `sayamaapps.annai.disallow`;
`strukturdaten.typ` → `sayamaapps.annai.type`; `strukturdaten.bereich` → `sayamaapps.annai.area`;
`strukturdaten.sameAs` → `sayamaapps.annai.sameAs`; `strukturdaten.geo` → `sayamaapps.annai.geo`;
`strukturdaten.aktion` → `sayamaapps.annai.action` (`typ`/`ziel` → `type`/`target`);
`teilbild` → `sayamaapps.annai.image` (no fallback: `teilbild` is a site option the head
snippet may still read for other purposes); `praxis.nichtIndexieren` →
`sayamaapps.annai.exclude.templates`; `kopf.sticky`, `themenfarbe`, `favicon`, `schriften`
stay with the site.

---

## 5. Translation keys (complete; `translations/en.php`, `de.php`)

| Key | en | de |
|---|---|---|
| `day.1` … `day.7` | Monday … Sunday | Montag … Sonntag |
| `format.date` | `j M Y` | `j.n.Y` |
| `llms.contact` | Contact | Kontakt |
| `llms.address` | Address | Adresse |
| `llms.phone` | Phone | Telefon |
| `llms.email` | Email | E-Mail |
| `llms.action` | Main action | Haupthandlung |
| `llms.hours` | Opening hours | Öffnungszeiten |
| `llms.hoursLine` | `{hours}` | `{hours} Uhr` |
| `llms.closed` | closed | geschlossen |
| `llms.holidaysClosed` | Public holidays: closed | Gesetzliche Feiertage: geschlossen |
| `llms.onlyHours` | only {hours} | nur {hours} Uhr |
| `llms.range` | {from} to {to} | {from} bis {to} |
| `llms.events` | Events | Termine |
| `llms.cancelled` | cancelled | abgesagt |
| `llms.pages` | Pages | Seiten |
| `llms.optional` | Optional | Optional |
| `llms.home` | Home | Startseite |

Registered as Kirby translations `annai.<key>` too. The check tool and the
CLI are English only (developer tools).

---

## 6. Content field and config contract

Field names are read through `Options::fields()`; the defaults are the
German names of the earlier copies, so no content changes.

| Key | Default field | Where | Used for |
|---|---|---|---|
| `brand` | `marke` | site | name (JSON-LD, llms H1, titles, og:site_name); fallback `$site->title()` |
| `tagline` | `zusatz` | site | home title suffix |
| `description` | `beschreibung` | site | JSON-LD description, llms summary, fallback meta description |
| `intro` | `einleitung` | page | meta description, llms page note |
| `street` | `strasse` | location page, else site | PostalAddress |
| `postalCode` | `plz` | location page, else site | PostalAddress |
| `city` | `ort` | location page, else site | PostalAddress |
| `phone` | `telefon` | location page, else site | telephone (E.164), llms |
| `fax` | `fax` | location page, else site | faxNumber |
| `email` | `email` | location page, else site | email |
| `profiles` | `['instagram', 'facebook']` | location page, else site | appended to sameAs (list of field names) |

Location page: `sayamaapps.annai.location`, else Noren's location, else the first page
with template `betrieb` (`sayamaapps.annai.locationTemplate`), searched on the top level,
then on the second level, and only then in the whole index (§3.8). With Noren,
`sayamaapps.noren.locations` should be set, also next to `sayamaapps.annai.location`: without it Noren
walks the whole index for its location pages on every request that shows hours,
and the check reports it (`Check::NOREN_LOCATIONS_PROBLEM`). Opening hours are Noren's
contract (`bereiche`, `zeiten`, `schliessungen`), never read by Annai directly.

Routes: `/robots.txt`, `/sitemap.xml`, `/sitemap-<n>.xml`, `/llms.txt`, all
`GET|HEAD`, all behind whatever runs before Kirby (preview protection), all
built from Kirby's base URL (`Pages::base()`, no language prefix).

---

## 7. Sudare, Noren and Koyomi contracts

- **Sudare** (preview protection): Annai calls `\Sudare\Sudare::active(): bool`
  when the class and the method exist, and on the CLI (check tool) also the
  stateless `\Sudare\Sudare::protects($base): bool`. True → every page gets
  `noindex, nofollow, noarchive`, and robots.txt, sitemap.xml and llms.txt are
  the normal files (`Cache-Control: no-store`): Sudare answers a request without
  credentials with 401 before Kirby renders, so those requests are authenticated.
  Closed (robots.txt `Disallow: /` without a sitemap line, sitemap.xml an empty
  urlset, llms.txt its H1 only, chunk files 404) when `sayamaapps.annai.noindex => true`
  (`Pages::hidden()`), or when the request is exempt: `active()` is the state of
  the configuration, not the outcome for the path (Sudare 1.0.0, `src/Sudare.php`:
  `handle()` stores `self::$config` before `Guard::handle()` decides), so
  `Pages::exempt($kirby, $path)` asks `\Sudare\Sudare::config()->isExempt($path,
  $method, $sent)` per route (`/robots.txt`, `/sitemap.xml`, `/sitemap-<n>.xml`,
  `/llms.txt`); `config()` is null without a guard run, and then nothing is exempt.
  Check::site() counts the sitemap and llms.txt as they will be served after the
  preview, and computes the robots warnings for that state too; the preview is one
  site problem. While the preview is on, the response is kept out of Kirby's page cache.
- **Noren**: detected by `function_exists('noren_option')`; used only when
  `sayamaapps.noren.active` is true (`$site->norenUnits()` returns `[]` otherwise) and
  `sayamaapps.annai.noren` is true. Annai never builds hours itself. Noren is asked only
  where hours are shown (the home page head, an `sayamaapps.annai.jsonld` hook, llms.txt,
  the site methods), because every Noren schema call takes the response out of
  Kirby's page cache; the other pages stay cacheable. Without
  `sayamaapps.noren.locations` Noren resolves its location pages by walking
  `$site->index()` on every request that asks for the business page or the
  hours — with `sayamaapps.annai.location` too, which spares Annai's lookup but not
  `units()`; `Business::norenSearchesIndex()` is then true and the
  check reports `Check::NOREN_LOCATIONS_PROBLEM` — README and SPEC recommend
  `sayamaapps.noren.locations`.
- **Koyomi**: detected by `function_exists('koyomi_active')`; used only when
  `koyomi_active()` and `sayamaapps.annai.koyomi`. Event pages are ordinary pages and are
  in the sitemap anyway; Annai adds the lastmod rule and the llms.txt list.
  Koyomi prints its own event JSON-LD in its layout; Annai does not duplicate
  it.

---

## 8. Design choices

- **AI crawler rules:** `sayamaapps.annai.robots.ai` defaults to `null`: no AI groups, the same robots.txt as without the option. Presets `blockTraining` and `allowAll`; `sayamaapps.annai.robots.agents` per bot. The list in `Robots::AI_AGENTS` was compiled from the vendors' pages on 2026-10-11 and must be checked against them before a release. Blocking a `user` or `search` agent is allowed but warned by the check tool.
- **noindex in preview:** `sayamaapps.annai.noindex` defaults to `null` = automatic, via `Sudare\Sudare::active()`. That contract is shared with the Sudare package; if the two ever differ, one side changes. While the preview is active, the robots meta says noindex on every page; robots.txt, sitemap.xml and llms.txt stay the normal files behind the protection. Only an explicit `sayamaapps.annai.noindex => true` closes them (`Disallow: /`, empty urlset, H1 only).
- **hreflang:** `sayamaapps.annai.hreflang` defaults to `false`, because no site printed hreflang before. Recommended `true` for any multilang site.
- **Check tool:** CLI only (`bin/annai-check`), no Panel area (a Panel area needs a Vue bundle; the family has none).
- **`@id` fragment** is `#business` (English) instead of `#betrieb`; option `sayamaapps.annai.entityId`. A live site that wants to keep the old identifier sets `'sayamaapps.annai.entityId' => 'betrieb'` (a changed `@id` is a new entity for Google).
- **Texts of llms.txt** follow `sayamaapps.annai.language` like Noren: a German site without Kirby `locale` must set `'sayamaapps.annai.language' => 'de'`, otherwise "## Contact" appears. Same rule as in Noren ("en first").
- **`og:site_name` and `og:locale`** are printed whenever known; earlier copies printed them only sometimes. `og:locale` comes from the language or Kirby's `locale`; without either it is omitted.
- **Excluded templates** get `noindex, follow` (`sayamaapps.annai.noindexExcluded`, default true). Earlier output printed nothing for them.
- **`teilbild` is not read:** the share image is `sayamaapps.annai.image`. Sites move one line of config.
- **Koyomi lastmod:** for a past event, lastmod = max(page modified, event end), so the sitemap says "changed" when an event turned past, but a later edit still wins. Upcoming events only in llms.txt (past ones are noise for an agent).
- **No Koyomi event JSON-LD from Annai:** Koyomi's own layout prints it; doing it here too would duplicate the node on sites using Koyomi's templates. A site with its own layout adds `Koyomi\JsonLd::event()` through `sayamaapps.annai.jsonld`.
- **llms.txt stays `text/plain`** (one earlier copy used `text/markdown`). llmstxt.org examples are served as plain text.
- **Legacy German config keys** (`breite`/`laenge`, `typ`/`ziel`) are accepted inside `sayamaapps.annai.geo` / `sayamaapps.annai.action`, so a site's existing values move by renaming the option only. They are never emitted.
- **`Allow: /` stays** in the `*` group (RFC 9309 does not need it; sites that test their robots.txt expect it). Exception: with `/` in `sayamaapps.annai.disallow` the group is `Disallow: /` alone, because next to `Allow: /` the Allow wins the tie (RFC 9309 §2.2.2) and nothing is blocked.
- **Sitemap in multilang without hreflang:** one entry per page in the current language. With `sayamaapps.annai.hreflang`, one entry per language with alternates (sitemaps.org "localized versions").
- **Phone country** is an option (`phoneCountry`, default 49); without it, Germany is assumed.
- **Phone fields: input contract** (§3.2): the field holds only the number. A character gate, then libphonenumber (`giggsey/libphonenumber-for-php-lite`, Composer). It replaces an earlier free-text parser.

Known limitation: bidi control characters (U+202E) are not removed from the `annai-check` output (cosmetic only).

---

## 9. Test plan

Every test file starts with `require_once __DIR__ . '/helpers.php';` and ends
with `finish('<Suite>')`. Kirby-free files require `src/I18n.php` and their
classes (`TextTest` also `vendor/autoload.php`, for libphonenumber); Kirby
files use `annai_site()` / `annai_kirby()`.

### 9.1 tests/TextTest.php
- `line("Bio-Hofladen\nmit  Café ")` → `'Bio-Hofladen mit Café'`; `line("a\x1bb\x01c")` → `'abc'`, `"a\e[2Jb"` → `'a[2Jb'`, DEL and U+009B removed, NEL/tab/VT/FF/CR separate words, U+200B stays; `excerpt()` and `plain()` inherit the filter; Kirby (BusinessTest): ESC in `marke`/`zusatz`/`einleitung` → `<title>`, `og:site_name` and the meta description without it, no C0 in the rendered page
- `excerpt('Gemüse vom eigenen Feld.', 155)` unchanged; `excerpt('aaaa bbbb cccc', 9)` → `'aaaa…'`; `excerpt('<b>x</b> y', 10)` → `'x y'`; `excerpt('', 5)` → `''`
- `prune` cases of §3.2 incl. list re-indexing and keeping `0`/`false`
- `phone` (§3.2, the input contract): the golden table of seven spellings (`01512 3456789` → `+4915123456789`, `030 34567020` → `+493034567020`, `+49 30 34567040` → `+493034567040`, `030 34567060` → `+493034567060`, `030 / 34567000` → `+493034567000`, `+49 1512 3456788` → `+4915123456788`, fax `030 34567010` → `+493034567010`); the further-location pair of LlmsTxtTest, JsonLdTest and §3.5, `030 34567070` → `+493034567070`; the allowed spellings (`0049 …`, `+49 (0)30 …`, `(030) 34567000`, `0171-1234567`, `34567000-0`, U+00A0/U+202F, whitespace around the number); numbers from abroad with `+` (`+43`, `+41`, `+44`, `+39`, `+1`) and national numbers with `phoneCountry` `43`, `39`, `1`; outside the gate → `''` and `'characters'` (text, HTML, Kirbytags, notes, two numbers, a line break, a tab, a date, a time, a dot, `ext.`, a `+` inside, `–`, full-width digits, ESC); within the gate but invalid → `'invalid'` (`34567000`, `0351`, `+49`, `+`, `0`, `(0)`, `-`, `()`, 16 digits, `+1 (555) …`, `030 1`, an unknown `phoneCountry`, 300 digits); empty, spaces, `"\n\t"`, U+00A0 alone → `''` and `null`; the limit of the contract (`030 34567000 (12)` → `+49303456700012`); `shown()`: a line break as `\n`, ESC and CSI as escapes; without libphonenumber (child process) `'library'`, the gate first, empty silent; run time: four fields of 50,000 characters under 0.25 s; BusinessTest: golden fields as phone and fax (telephone, faxNumber, llms.txt `(+E.164)`, no log line), each reason logged with its message and a site problem in the check, JSON-LD without `telephone`/`faxNumber`, llms.txt as written, an empty field silent
- `time(480)` `'8:00'`, `time(1560)` `'2:00'`, `time(1440)` `'0:00'`; `range(480, 720)` `'8:00–12:00'`
- `esc('<a href="x">&')` → `'&lt;a href=&quot;x&quot;&gt;&amp;'`; `xml("a&b'")` → `"a&amp;b&apos;"`

### 9.2 tests/RobotsTest.php
- default config `['disallow' => ['/panel'], 'sitemap' => 'https://x/sitemap.xml']` → exactly `"User-agent: *\nAllow: /\nDisallow: /panel\n\nSitemap: https://x/sitemap.xml\n"`
- no sitemap, two disallows, empty disallow list (`"User-agent: *\nAllow: /\n"`)
- `agents('blockTraining')` has `GPTBot => false`, `ChatGPT-User` absent; `agents('blockTraining', ['GPTBot' => true])` → true; `agents('allowAll')` has every known agent true; `agents(null, ['FooBot' => false])` keeps FooBot
- render with agents: blocked group `"User-agent: GPTBot\nDisallow: /"`, allowed group repeats `Disallow: /panel`, blocked before allowed, sorted
- `extra` block placement; `blockAll` exact output; `disallow` with `/` → `*` group `Disallow: /` alone, an allowed agent keeps `Allow: /` and the other paths
- `validPath`: `/a b`, `/my path` rejected (RFC 9309 excludes the space), `/a%20b` accepted; `"/caf\xE9"`, `"/\xFF"` rejected (not UTF-8), `/café` accepted, `render()` with such a path stays UTF-8; Kirby (RoutesTest): a Latin-1 byte in `sayamaapps.annai.disallow` is logged and left out, in `sayamaapps.annai.robots.extra` written as U+FFFD, robots.txt passes `mb_check_encoding()`; `render(['extra' => ['# 案内', 'Disallow: /Åland/']])` is UTF-8 and holds the rule as one line, `parse()` reads `/Åland` whole while CR and CRLF still split; Kirby (RoutesTest): `sayamaapps.annai.robots.extra` with `Å`, `案内` and `Ņ` → `robotsExtra()` three lines, robots.txt UTF-8, no junk line, `parse()` path `/Åland/`
- `warnings`: blocking `Claude-User` → one warning naming it and `user`; blocking `GPTBot` → none; blockAll → "Disallow: / for everyone"; a disallow entry `"x\u{9B}31m"` is named as `"x\u009b31m"` (`Text::shown()`)
- `parse` round trip of a rendered file: groups, rules, sitemaps; comments ignored; case-insensitive field names
- RFC shape: no two consecutive blank lines, ends with exactly one `"\n"`

### 9.3 tests/SitemapTest.php
- empty list → valid empty urlset with xml declaration
- one entry with lastmod → exact string of §3.4
- escaping: loc with `&` and `'` → `&amp;`, `&apos;`
- alternates → `xmlns:xhtml` declared once, `<xhtml:link rel="alternate" hreflang="de" href="…"/>` per code, in order
- no alternates anywhere → no `xmlns:xhtml`
- `chunks` of 3 entries with size 2 → `[[a,b],[c]]`; `chunks([])` → `[[]]`
- `index` two sitemaps → exact string
- `lastmod(1791000000, 'Europe/Berlin')` → `'2026-10-03T…+02:00'` (compute the expected value in the test with DateTimeImmutable), `lastmod(null)` → null, `lastmod(0)` → null, `lastmod(new DateTimeImmutable('2027-01-01 12:00', UTC), 'UTC')` → `'2027-01-01T12:00:00+00:00'`

### 9.4 tests/LlmsTxtTest.php
Set `I18n::setLanguage('de')` for the main case and reproduce the
expectations of the 41-check fixture): title/summary, `## Kontakt` with the four
lines, `### Hofladen` only with two units, `- Montag: 8:00–12:00, 14:00–18:00 Uhr`,
`- Dienstag: geschlossen`, `- Samstag: 8:00–12:00 Uhr (nur Verkauf)`,
`- 31.12.2027 (Silvester): geschlossen`, `- 24.12.2027 (Heiligabend): nur 8:30–12:00 Uhr`,
`- [Hofladen](https://…/laden): Gemüse vom eigenen Feld.`, home first. Then:
- English: `## Contact`, `- Monday: 8:00–12:00` (no "Uhr"), `- Tuesday: closed`
- one unit → no `###`; holidays true → holidays line after the days; closure range → `2.8.2027 bis 15.8.2027 (Betriebsferien): geschlossen, Sommerpause`
- `contact.extra`: `"  - Fax:\n   030 34567099  "` → `- Fax: 030 34567099` (Text::line), `"\t"` dropped; `- Fax: <b>0</b> [x](y)` written as given (no escaping)
- events section with venue and a cancelled one; sections placement before Pages; optional section; empty contact → no `## Kontakt`
- all links absolute, file ends with one `"\n"`, no `\n\n\n`
- `[`/`]` in a title replaced; an empty title (page, optional entry of whitespace only, event) is labelled with its URL — `- [https://x.test/leer](https://x.test/leer): Notiz`, the encoded `- [https://x.test/a%20b](https://x.test/a%20b)` — no `[](` in the file
- summary link pattern: a 100,000-character `data:` target keeps the summary (`"\n> Hofladen"`, PCRE JIT on and off); three levels of parentheses in a target are a link, four are text; `"[a]("` × 25,000 and 50,000 nested parentheses well under a second; with `pcre.backtrack_limit` 2 the summary stays, links as plain text

### 9.5 tests/JsonLdTest.php
- `localBusiness` on the Testhof facts (two units, cafe not primary): keys and
  values of the 41-check run (`@type`, `@id` `https://x/#business`, telephone
  `+493034567000`, no `email`/`geo`/`image` keys, `address` exact array,
  `openingHoursSpecification` = primary unit, one `department` named
  `Testhof — Hofcafé` with `@id` `…/#cafe`, `sameAs` without `''`,
  `potentialAction` exact)
- `type === false` → `[]`; name `''` → `[]`; no units → no `department`/hours keys; geo floats; faxNumber; `phoneE164`/`faxE164` `''` (a field outside the input contract, §3.2) → no `telephone`/`faxNumber`
- `document([])` null; one node → `@context` + node; two nodes → `@graph`, inner `@context` removed; non-array dropped
- `encode`: `</script>` becomes `</script>`; `&` → `&`; `ü` and `/` unescaped; `script()` wrapper exact

### 9.6 tests/HeadTest.php
- full input → model keys; `og` only non-empty; `og:url` = canonical; width/height as strings
- `tags` order and exact strings (title, description, robots, canonical, alternates, og, twitter, script); `['title' => false]` drops `<title>`; description `''` → no meta description; robots null → no robots meta
- escaping: title `A "B" & <C>` → `A &quot;B&quot; &amp; &lt;C&gt;`; description with `</script>` is escaped in the attribute
- `render` joins with `"\n"` and ends with `"\n"`; empty model (`canonical` only) renders one line

### 9.7 tests/SnippetTest.php (Kirby)
- Annai off: `snippet('annai/head')` prints `''` (page without model)
- explicit `['head' => Head::build([...])]` prints `Head::render()` output (compare strings)
- `['head' => …, 'title' => false]` has no `<title>`
- a site override `site/snippets/annai/head.php` wins (like noren SnippetsTest)

### 9.8 tests/BusinessTest.php (Kirby)
Fixture: site.txt with `Marke`, `Zusatz`, `Beschreibung`, `Instagram`; page
`1_kontakt` template `betrieb` with address/phone (the fixture text of the 41-check run), pages `home`, `2_laden`.
- off → `facts()` `[]`, `jsonLd()` null, `head()` `[]`
- `location()` finds `kontakt` by template; `sayamaapps.annai.location` id wins; Closure wins; draft never
- `location()` next to 50 nested pages: `head()` of the deepest page leaves the `index` property of `$site->children()` null (Reflection); a second-level betrieb page is found without the index, a third-level one through it (`searchesIndex()` true); the top level wins over the second; the search runs once per site object; `head()` calls `facts()` once (a counting `sameAs` Closure)
- `field('street')` from the page; `field('email')` falls back to site; both empty → `''`
- `facts()`: name from `marke`, fallback to title when `marke` empty; `description` is the rendered field as plain text (`**Bio**`, `(link: …)` and tags gone, not shortened); `phoneE164`; `sameAs` merges option + `instagram` field; `geo` legacy keys; `action` legacy keys; `type` default; `units` `[]` without Noren
- With Noren (symlinked when `ANNAI_NOREN_PATH` exists, else print `SKIP` lines and `holds(true)`): `sayamaapps.noren.active`, two areas → two units, primary = `sayamaapps.annai.area` else first, `days[0]['blocks']` `[[480,720],[840,1080]]`, `note`, `closures` with `hours`, `schema` keys present
- With Noren: the address page as `sayamaapps.annai.location` (or `fn () => null`, or a page without an address) and two areas on a Noren page without an address of its own → both units `atBusiness`, the department `@id` is `#cafe`; two Noren pages with the same address and area key → `#cafe` and `#b-cafe`
- With Noren, `sayamaapps.annai.location => fn () => null` and two Noren pages with addresses of their own: with the site address equal to the first page's, that unit is primary (`place` null) and the second a department with its OWN address and phone, its llms.txt block with address lines; with a site address of neither, both are departments with their own addresses, no primary, the business node keeps the site address without hours. Two locations with the same area name → names `Praxis Nord · Praxis` / `Praxis Süd · Praxis` (headings, department name), no bare `### Praxis`. `sayamaapps.annai.area` without a match is logged; a matching one logs nothing
- With Noren and the page cache on: a subpage keeps `response()->cache()` true and its cache entry (`laden.latest.html`); the home page keeps its hours and stays out of the cache; with an `sayamaapps.annai.jsonld` hook the facts carry the units on the subpage; `facts($kirby, false)['units']` is `[]`
- `jsonLd()`: home → one node; `/laden` → null; hook adds a node → `@graph`; hook returning `[]` → null; `type => false` + hook → the hook's node alone
- `head()`: title rules (home with/without tagline, subpage, empty brand), description from `einleitung` else `beschreibung`, excerpt length, closures `sayamaapps.annai.title`/`sayamaapps.annai.description`/`sayamaapps.annai.canonical`, `image` string → absolute URL, array form, Closure form, `locale` from Kirby `locale` option `de_DE.utf8` → `de_DE`, `twitterCard` invalid → null; ESC and U+009B in the hook's `alt`, `url` and canonical are removed (`og:image:alt` printed clean, no control character in the page), a profile field URL with U+009B, ESC or a space is logged (`holds a space or a control character`) and left out of `sameAs`; the U+009B is logged as `\u009b` (`Text::shown()`), no raw C1 in the log

### 9.9 tests/RoutesTest.php (Kirby)
- off: `annai_request($kirby, 'robots.txt')` is not a 200 from Annai (Kirby 404 or error page); same for sitemap.xml and llms.txt
- HEAD and GET 200 on all three; `sitemap-1.xml` 404 when one file
- robots: content type `text/plain`, default `Disallow: /panel`, absolute `Sitemap:` from `Pages::base()` (multilang fixture without language `url` keys: `/sitemap.xml`, index locs answer 200, one business `@id` in every language), `sayamaapps.annai.disallow`, `robots.ai => 'blockTraining'` adds GPTBot group, `robots.agents` (a name `"x\nDisallow: /"`, `Fo#o` or with a space is logged and left out; every `Robots::AI_AGENTS` name is a token; a name `"Bot\u{9B}31m"` and an `sayamaapps.annai.disallow` entry `"x\u{9B}31m"` are logged with `\u009b`, no raw C1 in the log), `robots.extra`; `sayamaapps.annai.noindex => true` → `Disallow: /` only
- sitemap: `application/xml`, home + pages, excluded template and `exclude.pages` id/Closure missing, draft missing, `lastmod` present and W3C-shaped, `sitemap.lastmod => false` → none; `sitemap.extra` list and Closure; `sitemap.chunk => 2` with 3 pages → `sitemap.xml` is a sitemapindex with 2 files, `sitemap-1.xml` has 2 urls, `sitemap-2.xml` 1, `sitemap-3.xml` 404; index lastmod with extra lastmods `2000` and `2001-01-01` in one chunk → `2001-01-01`; `sitemap.chunk` `false`, `'abc'`, `0`, `'100'`, `100000` → one `<urlset>`, logged
- `Pages::robots`: excluded → `noindex, follow`; `noindexExcluded => false` → null; `noindex => true` → `noindex, nofollow, noarchive`; a fake `Sudare\Sudare` class declared in the test with `active()` returning true → preview; returning false → null
- multilang fixture (`languages => true`, `site/languages/de.php`, `en.php`): `hreflang` false → `[]`; true → `de`, `en`, `x-default` in order, sitemap has `xhtml:link`, one `<url>` per language; a page with only `default.en.txt` → `en` and `x-default` to the English URL, no German sitemap entry; with hreflang on and off: `/en/kontakt` (German content only) → canonical and `og:url` `http://example.invalid/kontakt`, `/en/laden` and `/en` keep their URL, `/english` in German → canonical `/en/english`; `Pages::contentUrl()` the German URL for kontakt after an `/en` request, null for laden and on a single-language site; an `sayamaapps.annai.canonical` hook wins, one returning `null` leaves the rule; with hreflang off (fresh app): the sitemap lists `/en/english` (not `/english`) with the lastmod of `default.en.txt`, `llms.txt` links `/en/english` and an English-only optional page under `/en/…`, `$site->annaiLlms()` in English links the German-only kontakt as `/kontakt`, a home page with English content only is linked as `/en`, Koyomi events in English link a German-only event under its German URL; `contentLanguage()` `en`/null; `llms.txt` has `- [English only](http://example.invalid/en/english): An English intro for this page.` (title and note of the linked English version), the optional page as `- [Questions](…/en/fragen)`, Hofladen keeps its German title and note
- llms: `text/plain`, H1 first line, `## Seiten` with home first, excluded template missing, `llms.optional => ['rechtstext']` adds `## Optional`, `llms.extra` hook changes the summary, language `de` vs default en headings
- hook options without a Closure: `sayamaapps.annai.title`/`description`/`canonical` as strings, `sayamaapps.annai.jsonld` and `sayamaapps.annai.llms.extra` as arrays, `sayamaapps.annai.location => 42` → the page, the home JSON-LD and llms.txt use Annai's rules, the business page is found by template, each option logged once with its value or type (`must be a Closure, not 'Eigener Titel'`, `not array`; `sayamaapps.annai.location must be a page id or a Closure, not 42`), `Check::site()` reports them as `configuration: …`; `null` logs nothing
- Koyomi (symlinked when `ANNAI_KOYOMI_PATH` exists, else SKIP): a calendar with one past and one upcoming event; past event page lastmod ≥ its end; llms `## Termine` lists only the upcoming one with its venue label; multilang (in the multilang bullet above): an event with German content only is linked under its German URL in English; multilang: llms.txt in English names that event with the title of the German version it links, also for a page model without Kirby's language fallback (`RtEventWithoutFallback`: Koyomi's own English title is `''`, precondition checked); an event whose German title is empty is named `breathing` (the slug of the linked version) in English and in German, each language in an App of its own (Koyomi caches its events per App with the URLs of the first language), and llms.txt has `- [breathing](http://example.invalid/events/breathing)` and no `[](`

### 9.10 tests/AgentReadyTest.php (Kirby) — the 41 checks
The 41 checks of the agent-ready run, one to one with these
substitutions: fixture through `annai_site()`/`annai_page()`, requests through
`annai_request()` (no `php -S`), templates print
`<html><head><?php snippet('annai/head') ?></head><body>Fixture</body></html>`,
config `'sayamaapps.annai.active' => true, 'sayamaapps.annai.language' => 'de', 'sayamaapps.noren.active' =>
true, 'noren.language' => 'de', 'sayamaapps.annai.exclude.templates' => ['error',
'weiter'], 'sayamaapps.annai.type' => 'GroceryStore', 'sayamaapps.annai.sameAs' => [...],
'sayamaapps.annai.action' => ['type' => 'OrderAction', 'name' => 'Online bestellen',
'target' => 'https://shop.example/testhof']`; `@id` expectation becomes
`$basis/#business`; `substr_count($html, '</script>') === 1` stays (the head
snippet prints exactly one script); "description complete" expects the rendered
plain text `Bio-Hofladen mit Café x` (the field is no longer raw, so the
`</script>` of the fixture is a stripped tag, and the "x" after it shows nothing was cut). Config changes mid-test are done by
booting a new `annai_kirby()` with other options. The whole file is SKIP
(prints why, `holds(true)`) when Noren is not available. Keep the German
check messages as English sentences.

### 9.11 tests/CheckTest.php (Kirby)
- `page()` on a good page → no problems, `jsonld.types` `['GroceryStore']` on home (with `sayamaapps.annai.type`), `[]` on a subpage
- problems: title > 60, description missing/short/long, `canonical` hook returning another URL, image `http://` → absolute-https problem; `noindex => true` is the one site problem `Check::PREVIEW_PROBLEM`, never a page problem; multilang fixture (de default, en; a page with only `default.en.txt`) checked in German → canonical `/en/english`, no problem, `notes` `['no content in de: canonical points to http://example.invalid/en/english']`, rendered as `  ~ `, summary `2 pages, 0 problems`, exit 0; a translated page has no note
- `notes`: Kirby `url` `/` (the CLI without `--url`) → `[Check::NO_HOST_NOTE]`, exit code unchanged, rendered as `  ~ `; with a host `[]`
- `phones` (§3.2, the input contract): the fixture's `telefon` `030 / 34567000` → `[['field' => 'phone', 'raw' => '030 / 34567000', 'e164' => '+493034567000', 'problem' => null]]`, rendered `  ~ phone: "030 / 34567000" → +493034567000` right after `preview: off`; `telefon` `"(0049) 30\u{A0}34567000"` → `+493034567000` and `fax` `"Fax\x1b folgt"` → `null`, `'characters'`, rendered `  ~ fax: "Fax\u001b folgt" → (none: more than the number)` without ESC (a CSI as `\u009b` in the line and in the problem), one `configuration: field fax holds more than the number …` problem, exit 1, `1 problems`, the `~` lines themselves not counted; `telefon` `34567000` → `'invalid'`, `(none: no valid number)`; an empty `telefon` → `[]`, no line; with Noren (three location pages, B with two areas and `030 34567030`, C with `"folgt\nab Mai"`): `phones` lists the business phone, then `page` `b` with `+493034567030` once and `page` `c` with `raw` `"folgt\nab Mai"` (the stored field, not the one-line display value) and `'characters'`, rendered `  ~ phone (b): "030 34567030" → +493034567030`, `  ~ phone (c): "folgt\nab Mai" → (none: more than the number)`, C a configuration problem; CLI: the phone line in the text report, `phones` in `--json`; a site whose `vendor/autoload.php` leaves out libphonenumber → `(none: libphonenumber is not installed)`, the configuration problem `phone numbers need giggsey/libphonenumber-for-php-lite …`, exit 1, `problem` `library` in `--json`; the same site rendered with Kirby's `debug` on (child process) throws `InvalidArgumentException` with the `library` message (README "Installation")
- `site()` → `robots.warnings` with `robots.agents => ['Claude-User' => false]`; duplicate title detection; `sitemap.count`; `llms.headings`; `Check::LOCATION_PROBLEM` without `sayamaapps.annai.location` and without a business page in the top two levels (the fixture keeps its address in site.txt and sets `sayamaapps.annai.location => fn () => null`); with Noren (sibling fixture, else SKIP) and without `sayamaapps.noren.locations` the problem is reported although the business page is on the top level, with `sayamaapps.noren.locations => ['kontakt']` it is not; that is `Check::NOREN_LOCATIONS_PROBLEM` (`LOCATION_PROBLEM` names no Noren), also with `sayamaapps.annai.location => 'kontakt'` (`searchesIndex()` true), none with both options
- `render()` contains the summary line and `  ! ` prefixes; `exitCode()` 0/1
- `bin/annai-check`: `proc_open` the script with `--root=$tmp --url=http://example.invalid` on an `annai_site()` directory (write `site/config/config.php` with `sayamaapps.annai.active`): exit 0 and output contains `pages`; `--json` parses; without `sayamaapps.annai.active` exit 2; `--json` with an `sayamaapps.annai.llms.extra` section `案内` and `sayamaapps.annai.robots.extra` `# Filialen: Åland` → exit 0, `llms.headings` holds `案内` whole, `robots.text` the line whole; `--page=caf\xE9` → exit 1 and the problem text with U+FFFD, not exit 3

---

## 10. Documentation

- `README.md` (English, modelled on noren's), sections as in the README: Features, Requirements,
  Installation (composer, repositories until Packagist), Usage (config, head snippet, content
  fields), robots.txt and AI agents (the agent table with roles and the policy), Sitemap, llms.txt
  and preview (Sudare contract), Check tool (CLI usage, problems list), Upgrading (coexistence with
  `site/plugins/suchmaschinen`: the old plugin MUST be removed or its routes win, because Kirby
  registers routes in plugin load order; rename table of §4; behaviour changes as in the CHANGELOG
  1.0.0 entry), Options (§4 verbatim), Privacy, License.
- `CHANGELOG.md`: Keep a Changelog, `## [1.0.0] — unreleased`, Added /
  Changed (vs the earlier site copies, with the rename table).
