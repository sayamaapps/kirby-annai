# Annai

Everything search engines and AI agents read from a [Kirby CMS](https://getkirby.com) 5 site: `robots.txt`, `sitemap.xml`, `llms.txt`, schema.org JSON-LD for a local business, meta description, canonical, Open Graph, `noindex` and hreflang, in one head snippet. No styling.

*Annai* (案内) is the word for guidance, the one who shows the way. The plugin shows search engines and agents where things are: the pages, the address, the phone number, the opening hours and the one action that matters, with the real target URL.

- **Served at runtime.** `robots.txt`, `sitemap.xml` and `llms.txt` are routes, not files in `public/`, so they stay behind whatever protects the site before Kirby boots (for example a preview lock) and always name the current domain.
- **JSON-LD from the same content.** The business node is built from the site's own fields and the opening hours of [Noren](https://github.com/simsimmal/kirby-noren), so the markup cannot drift from the page. Hooks add or replace nodes for multi-location or multi-entity sites.
- **AI agents on purpose.** Agents that act for a person (`Claude-User`, `ChatGPT-User`, `Perplexity-User`) and AI search engines stay allowed. Training crawlers are a per-site decision with two presets and per-bot overrides.
- **Events from Koyomi.** When [Koyomi](https://github.com/simsimmal/kirby-koyomi) is installed and active, upcoming events appear in `llms.txt`, and past event pages carry a sensible `lastmod`.
- **A check tool.** `vendor/bin/annai-check` shows per page what engines and agents see and lists the problems (title, description, canonical, og:image, JSON-LD, noindex, robots warnings).
- **Off by default.** Without `'annai.active' => true` every route falls through to Kirby and every method returns nothing.
- **No cookies, no browser storage, no requests to third parties.** See [Privacy](#privacy).

## Requirements

- PHP 8.2 or newer with `ext-mbstring` and `ext-json`
- Composer: Annai requires [`giggsey/libphonenumber-for-php-lite`](https://github.com/giggsey/libphonenumber-for-php-lite) (Google's libphonenumber, Apache-2.0) for the phone numbers, and Composer installs it (see [Installation](#installation))
- Kirby 5 (`^5.0`, tested with 5.6). One place reads a Kirby internal: the hreflang check for the default language's content file uses `Version::contentFile()`, marked `@internal`. Should a Kirby release close it, Annai falls back to Kirby's own answer (hreflang stays), and the sibling test in `tests/RoutesTest.php` (`default.en.txt` only) shows it
- The site at the **host root**: Kirby answers at `https://host/`, as with Sudare. Crawlers read `robots.txt` at the host root only, and every `Disallow:` path is a host-root path: in a sub-directory installation (`https://host/blog/`) the route `/blog/robots.txt` is never read, and `annai.disallow` entries such as `/panel` would have to be written as `/blog/panel`. Annai does not add the prefix.
- Optional: Noren (opening hours), Koyomi (events), Sudare (preview protection). Annai detects them and never requires them; all three are in composer's `suggest`.
- **Multilingual: one domain.** A multilingual site with language prefixes on one domain (`/`, `/en/`, `/fr/`) is fully supported — sitemap, hreflang, canonical, llms.txt. **Languages on their own domains** in one installation (a language with its own `url` in `site/languages/*.php`, e.g. German on `.de` and English on `.com`) are **not supported**: the sitemap and robots.txt are built for one host. Point the other domain at the language prefix with a 301 instead (`example.com` → `example.de/en/`), or run two sites.

## Installation

```sh
composer require sayamaapps/kirby-annai
```

Composer installs the plugin into `site/plugins/annai/` (via `getkirby/composer-installer`). Add `/site/plugins/annai/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-annai" }]
```

**Composer is the supported installation.** It also installs `giggsey/libphonenumber-for-php-lite`, which reads the phone numbers, into the site's `vendor/`; Kirby and `annai-check` load it through the site's `vendor/autoload.php`. A copy of the plugin without Composer (a ZIP or a download into `site/plugins/annai/`) runs, but publishes no `telephone` and no `faxNumber`: it logs `Annai: phone numbers need giggsey/libphonenumber-for-php-lite, which is not installed — nothing is published as E.164; install Annai with Composer (README, Installation).`; with Kirby's `debug` option on, that message is thrown on every page instead, as for a broken option ([Options](#options)). The check reports it on a site whose Kirby comes from Composer; next to a ZIP Kirby the check needs a `vendor/autoload.php` and does not run. A site whose Kirby comes from Composer but whose plugins are copied by hand adds the library with `composer require giggsey/libphonenumber-for-php-lite`. Not next to a ZIP Kirby: once a `vendor/autoload.php` exists in the site root, `kirby/bootstrap.php` loads that one instead of Kirby's own.

## Quick start

1. Switch it on in `site/config/config.php`:

   ```php
   return [
       'annai.active'   => true,
       'annai.language' => 'de',              // German texts, see the note below
       'annai.type'     => 'GroceryStore',    // schema.org type of the business; default LocalBusiness
   ];
   ```

   > **Language:** the texts of `llms.txt` are English unless Annai learns otherwise. It follows `annai.language`, then the current language of a multi-language site, then its default language, then Kirby's `locale` option. **A German single-language site without a `locale` must set `'annai.language' => 'de'`**, or its `llms.txt` reads "## Contact" instead of "## Kontakt".

2. Put the head snippet into the layout's `<head>`, in place of the meta tags you wrote by hand:

   ```php
   <head>
     <meta charset="utf-8">
     <meta name="viewport" content="width=device-width, initial-scale=1">
     <?php snippet('annai/head') ?>
   </head>
   ```

   The snippet prints `<title>`, meta description, robots (when needed), canonical, hreflang, Open Graph, the Twitter card and the JSON-LD script. It prints nothing while Annai is off. Site-specific tags (favicon, theme colour, fonts, stylesheets) stay in your layout.

3. Fill in the site's content fields (names in [Content field contract](#content-field-contract)): brand, description, address, phone, email. Optionally add the opening hours with Noren and the profiles (`instagram`, `facebook`) to `sameAs`.

4. Check the result:

   ```sh
   vendor/bin/annai-check --root=/path/to/site --url=https://example.test
   ```

The routes work at once: `/robots.txt`, `/sitemap.xml` and `/llms.txt` answer on `GET` and `HEAD`.

## What is served

| URL | Content type | Contents |
|---|---|---|
| `/robots.txt` | `text/plain` | `User-agent: *` group with `Allow: /` and the `annai.disallow` paths (`Disallow: /` alone when `/` is among them), one group per configured AI agent, the `annai.robots.extra` lines (in the `*` group), and an absolute `Sitemap:` line |
| `/sitemap.xml` | `application/xml` | One `<urlset>` of all indexable pages, with `<lastmod>`, the `annai.sitemap.extra` entries and, with hreflang on, `xhtml:link` alternates. Above `annai.sitemap.chunk` URLs (default 50000) it is a `<sitemapindex>` |
| `/sitemap-<n>.xml` | `application/xml` | The n-th chunk, only when the sitemap is split and `n` is the number as the index writes it (`sitemap-1.xml`, not `sitemap-01.xml`). Otherwise 404 |
| `/llms.txt` | `text/plain` | The [llms.txt](https://llmstxt.org) format: H1 with the name, a quote with the summary, contact, opening hours, events, pages, optional pages |

All four are `GET|HEAD`. Every absolute URL comes from Kirby, never from a hard-coded host: page links from the page, and the `Sitemap:` line, the sitemap index and the business `@id` from Kirby's base URL, so on a multi-language site they do not carry a language prefix (`/sitemap.xml`, not `/de/sitemap.xml`) and the `@id` is the same in every language.

## Options

All read as `annai.<name>`; `null` counts as "not set". Boolean options take a real `true` or `false`; anything else is logged and the default is used. A broken value is logged once per process; with Kirby's `debug` option on it throws, so you see it on the page while you develop. Messages about the content work the same way: a phone or fax field that holds more than the number or no valid number, a `sameAs` profile field that is no http(s) URL or holds a space or a control character, and the missing phone library (see [Installation](#installation)) are logged once per process and thrown with `debug` on.

**Hooks** (the Closure options: `title`, `description`, `canonical`, `image`, `location`, `sameAs`, `jsonld`, `sitemap.extra`, `llms.extra`, `exclude.pages`) cannot take a page down. A hook that throws, or returns a value of the wrong type (an array as a title), is logged like a broken option, and Annai's own result is used; with `debug` on it throws. A hook option that holds anything but a Closure (`'annai.title' => 'Eigener Titel'`, an array of nodes as `annai.jsonld`, `42` as `annai.location`) is logged the same way (`annai.title must be a Closure, not 'Eigener Titel' — ignored.`) and counts as unset; `image`, `sameAs`, `sitemap.extra` and `exclude.pages` take a plain value as well, see the table. `title`, `description` and `canonical` may return `null` for Annai's rule. The one exception is `exclude.pages`: a Closure that throws keeps the page **out** (fail closed), so a broken rule never lists what it was meant to hide. A `jsonld` result that cannot be encoded (a node with `NAN` or `INF`, or nested deeper than 512 levels) is logged the same way and the page renders without JSON-LD; the check tool reports it as `JSON-LD is not valid JSON`.

Options can be written flat (`'annai.robots.ai' => 'blockTraining'`) or nested (`'annai' => ['robots' => ['ai' => 'blockTraining']]`). Defaults are passed at each read, never in an `options` block of the plugin.

| Option | Default | Reader | Meaning |
|---|---|---|---|
| `active` | `false` | `active()` | Master switch; only a real `true` switches Annai on, anything else is logged and counts as `false` |
| `language` | site language | `language()` | `en`/`de` for llms.txt texts; order: option, current language, default language, `locale`, `en` |
| `fields` | §6 | `fields()` | Field name map, only the keys you change |
| `location` | `null` | `location()` | Page id or `Closure(): ?Page` of the business page; anything else is logged and counts as unset |
| `locationTemplate` | `'betrieb'` | `locationTemplates()` | Template(s) of the business page when `location` is unset and Noren is not active |
| `noren` | `true` | `flag()` | Use Noren's hours when Noren is active |
| `koyomi` | `true` | `flag()` | Use Koyomi's events when Koyomi is active |
| `type` | `'LocalBusiness'` | `type()` | schema.org type of the business node; `false` = no business node |
| `entityId` | `'business'` | `entityId()` | Fragment of the node `@id` (`url/#business`) |
| `area` | `null` | `area()` | Noren area key whose hours are the main hours |
| `sameAs` | `[]` | `sameAs()` | List of profile URLs, or `Closure(): list`. Only `http://`/`https://` values without a space or a control character are published; a handle or a host without a scheme (`@testhof`, `facebook.com/testhof`) or a URL with an escape sequence in it is logged and left out, also from the profile fields |
| `geo` | `null` | `geo()` | `['lat' => 52.52, 'lng' => 13.41]`; also `[52.52, 13.41]` and legacy `['breite' => …, 'laenge' => …]`; → `['lat' => float, 'lng' => float]` or null |
| `action` | `null` | `action()` | `['type' => 'ReserveAction', 'name' => 'Termin buchen', 'target' => 'https://…']`; legacy keys `typ`, `ziel` accepted; → normalised array or null (no target = null) |
| `image` | `null` | `get()` | Share image: path (`'assets/teilen.jpg'`), absolute URL, `['url','width','height','alt']`, or `Closure(Page): string\|array\|null`. Without a `url` no `og:image:*` tag is printed |
| `country` | `'DE'` | `country()` | `addressCountry` |
| `phoneCountry` | `'49'` | `phoneCountry()` | Country code for national numbers, digits only: `'43'`; `'+43'` and `'0043'` are read as 43 (the international prefix is no part of the code). libphonenumber reads a national number for its region (`49` → Germany) |
| `title` | `null` | `hook()` | `Closure(Page $page, array $facts): string` replaces the title rule; a value that is no Closure is logged and ignored |
| `description` | `null` | `hook()` | `Closure(Page $page, array $facts): string` replaces the description source: plain text, excerpted, not rendered (a `<` or `&amp;` in it stays as written) |
| `descriptionLength` | `155` | `descriptionLength()` | Excerpt length of the meta description, ellipsis included: a whole number from 20 to 320. Anything else (`'abc'`, `'200'`) is logged and gives 155; a number outside the range is logged and clamped. The check tool's limit follows it (length + 5) |
| `descriptionFallback` | `false` | `descriptionFallback()` | `true`: the chain is intro → the page's description field (`beschreibung`) → the site description (`beschreibung`). Each step gives way to the next only while it is shorter than the check's 50 characters and the next one is longer. The `annai.description` hook is not affected |
| `canonical` | `null` | `hook()` | `Closure(Page): string` |
| `locale` | `null` | `locale()` | `og:locale`; null → multilang current language locale (`LC_ALL` or first), else Kirby `locale` option (`de_DE.utf8` → `de_DE`), else null (omitted). Normalised to `xx_YY`; invalid → null |
| `twitterCard` | `null` | `twitterCard()` | `'summary'` or `'summary_large_image'`; anything else → null |
| `noindex` | `null` | `get()` | Preview: `null` auto (Sudare), `true` force and hidden (closed robots.txt, empty sitemap, H1-only llms.txt), `false` never; anything else is logged and counts as `null`. See [Preview](#preview) |
| `noindexExcluded` | `true` | `flag()` | `noindex, follow` on pages excluded from the sitemap (drafts get `noindex, nofollow` regardless) |
| `hreflang` | `false` | `flag()` | hreflang links and sitemap alternates on a multilang site |
| `exclude.templates` | `['error']` | `excludeTemplates()` | Templates kept out of sitemap, llms.txt, check |
| `exclude.pages` | `[]` | `excludePages()` | Page ids, each with everything below it (`['intern']` also keeps out `intern/protokoll`), or `Closure(Page): bool` (a truthy result excludes) |
| `disallow` | `['/panel']` | `disallow()` | Paths for `Disallow:` in the `*` group. Each starts with `/`, holds no space, no `#` and no control character, and is UTF-8 (RFC 9309: the file must be UTF-8, `panel` matches nothing, a space ends a path, `#` starts a comment, a line feed would write a rule of its own); any other entry is logged, left out and reported by the check. `/` closes the site: the group is then `Disallow: /` alone, see [robots.txt and AI agents](#robotstxt-and-ai-agents) |
| `robots.ai` | `null` | `robotsAi()` | `null`, `'blockTraining'`, `'allowAll'`. See [robots.txt and AI agents](#robotstxt-and-ai-agents) |
| `robots.agents` | `[]` | `robotsAgents()` | `['GPTBot' => false, 'CCBot' => true]` overrides; names `[A-Za-z0-9_.-]+` only |
| `robots.extra` | `[]` | `robotsExtra()` | Raw lines for the `*` group (repeated for allowed agents); from a `User-agent:` line on, own groups. An entry with line feeds inside is read line by line; a byte that is not UTF-8 becomes U+FFFD (robots.txt must be UTF-8) |
| `sitemap.lastmod` | `true` | `flag()` | Emit `<lastmod>` |
| `sitemap.chunk` | `50000` | `sitemapChunk()` | URLs per file: a whole number from 1 to 50000; anything else is logged and gives 50000, a larger number is logged and capped |
| `sitemap.extra` | `null` | `get()` | List or `Closure(): list` of extra entries |
| `jsonld` | `null` | `hook()` | `Closure(Page $page, array $nodes, array $facts): array` |
| `llms.optional` | `[]` | `llmsOptional()` | Templates listed under "Optional" (e.g. `['rechtstext']`) |
| `llms.extra` | `null` | `hook()` | `Closure(array $model, array $facts): array` |
| `timezone` | `'Europe/Berlin'` | `timezone()` | Zone of `<lastmod>` offsets; invalid → logged, default |

A complete example with the options most sites change:

```php
// site/config/config.php
return [
    'annai.active'           => true,
    'annai.language'         => 'de',
    'annai.type'             => 'GroceryStore',
    'annai.area'             => 'laden',
    'annai.sameAs'           => ['https://g.page/example'],
    'annai.geo'              => ['lat' => 52.52, 'lng' => 13.41],
    'annai.action'           => [
        'type'   => 'OrderAction',
        'name'   => 'Online bestellen',
        'target' => 'https://shop.example/testhof',
    ],
    'annai.image'            => ['url' => 'https://example.test/assets/teilen.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Der Hofladen'],
    'annai.twitterCard'      => 'summary_large_image',
    'annai.exclude.templates' => ['error', 'weiter'],
    'annai.llms.optional'    => ['rechtstext'],
    'annai.robots.ai'        => 'blockTraining',
    'annai.robots.agents'    => ['CCBot' => true],
    'annai.disallow'         => ['/panel', '/anruf'],
];
```

## Content field contract

Field names are read through `annai.fields`. The defaults are the German names of the kirby-starter, so existing content works unchanged. Each key can be overridden with `'annai.fields' => ['phone' => 'tel']`.

| Key | Default field | Where | Used for |
|---|---|---|---|
| `brand` | `marke` | site | name (JSON-LD, llms H1, titles, og:site_name); fallback `$site->title()` |
| `tagline` | `zusatz` | site | home title suffix |
| `description` | `beschreibung` | site | JSON-LD description and llms summary (rendered, plain text, whole), fallback meta description (rendered, excerpted) |
| `intro` | `einleitung` | page | meta description, llms page note |
| `street` | `strasse` | location page, else site | PostalAddress |
| `postalCode` | `plz` | location page, else site | PostalAddress |
| `city` | `ort` | location page, else site | PostalAddress |
| `phone` | `telefon` | location page, else site | telephone (E.164), llms |
| `fax` | `fax` | location page, else site | faxNumber |
| `email` | `email` | location page, else site | email |
| `profiles` | `['instagram', 'facebook']` | location page, else site | appended to sameAs (list of field names); a value that is no `http(s)` URL (`@testhof`) or holds a space or a control character is logged and left out |

The location page is `annai.location`, else Noren's location, else the first page with template `betrieb` (`annai.locationTemplate`). Annai looks for it on the top level, then on the second level, and only then in the whole page index, once per request. **On a large site, set `annai.location`**: without a business page in the top two levels every page view builds the index of all pages (seven to ten times the time of a page view with 2,000 blog posts). A site that keeps its address in `site.txt` and has no business page says so with `'annai.location' => fn () => null`. With Noren, set `noren.locations` as well — `annai.location` does not replace it: without it Noren finds its location pages by walking the whole index, on every page view that asks for the business page or shows the hours (see [Noren](#noren-and-koyomi)). The check tool reports both index searches, each with its own text. A field that is empty on the location page falls back to the site field of the same name, so a site that keeps address and phone in `site.txt` needs no change.

Opening hours are Noren's contract (`bereiche`, `zeiten`, `schliessungen`). Annai never reads them directly.

Phone numbers are shown as written and published as E.164: `030 / 23125000` becomes `+493023125000` with `annai.phoneCountry` = `'49'`.

**Only the number goes into the phone field** (`telefon`, `fax`, also on further location pages). Annai does not look for a number in text. A field may hold digits, spaces (a no-break space counts as one), a `+` as its first character, `/`, `-` and parentheses — `01512 3456789`, `030 / 23125000`, `+49 30 23125040`, `+49 (0)30 23125000`, `(030) 23125000`, `0171-1234567`, `0049 30 23125000`. Google's libphonenumber then reads it with the country of `annai.phoneCountry` (`'49'` → Germany) and checks that it is a valid number; a number with `+` is read for its own country (`+43 1 234 56` → `+43123456`), and so is one with `00` where `00` is the international prefix (Germany, Austria, Switzerland, Italy …; not with `annai.phoneCountry` `'1'`). `.` is not allowed: dates and times are digits and dots, and libphonenumber would turn `0351 / 12.12.2010` into a valid number — write `030 23125000`, not `030.23125000`.

**Every other field publishes no number and is reported** — a label (`Tel. 030 23125000`), a note, opening hours, a second number on its own line, a Kirbytag (`(tel: …)`), HTML, a dot: the log says `Annai: field telefon holds more than the number ("Tel. 030 23125000") — nothing is published as E.164; write only the number: digits, spaces, a leading +, "/", "-" and parentheses. Notes go into another field.` A field of allowed characters that is no valid number (`23125000` without its area code, `0351` alone, `+49`) logs `holds no valid phone number (…)`. Both are `configuration: …` problems in the check. An empty field publishes nothing and says nothing. JSON-LD gets `telephone` and `faxNumber` only for a number; llms.txt shows the field as written, with ` (tel:+49…)` when there is a number. The display string stays as written (on one line).

**Check every phone field before the go-live.** `annai-check` prints each one with its stored value and its result (`  ~ phone: "030 / 23125000" → +493023125000`, `  ~ fax: "Fax folgt" → (none: more than the number)`). Digits written only with allowed characters are read as the number, also when they are a note: `030 23125000 (12)` publishes `+49302312500012`. Keep notes in another field, and read the check's line.

## Noren and Koyomi

**Noren** supplies the opening hours. Annai uses it when `function_exists('noren_option')`, `noren.active` is true and `annai.noren` is not false. **Set `noren.locations`**, also when `annai.location` is set: without it Noren looks for its location pages by walking `$site->index()`. Without `annai.location` Annai asks Noren for the business page on every page view, so every page view builds the index of all pages (about twenty times the head time of a page with 2,000 posts); with it, every page that shows the hours still does (the home page, `llms.txt`, every page with an `annai.jsonld` hook). The check tool reports it (`Noren runs without noren.locations …`). From Noren it takes, per area, the weekly blocks, notes, holidays and all upcoming closures, and turns them into `openingHoursSpecification` and `specialOpeningHoursSpecification` (JSON-LD) and into the hours section of `llms.txt`. The primary area is `annai.area`; without it the first area with hours. Further areas become `department` nodes in the JSON-LD and `###` sections in `llms.txt`. With several Noren locations, each unit takes its hours from its own location page; a unit at another location than the business page is a `department` with that page's address and phone and the `@id` `#<page-id>-<area>` (`#<page-id>` for a location without areas), and in `llms.txt` its address and phone stand under its `###` heading. A Noren page that carries only hours belongs to the business (its address is the business's, on `annai.location` or in `site.txt`, also with `'annai.location' => fn () => null`): its further areas are departments with the bare `#<area>`, as on the business page itself. Only a Noren page with an address of its own, even the same one, keeps its page id in the fragment, so two pages with the same area key never share an `@id`. The primary area is always one of the business page: its hours go into the business node next to the business address. An `annai.area` that names only an area of another location is logged, and that area stays a department with its own address and hours; an `annai.area` that matches no area with hours at all (a typo) is logged too, and the first area at the business is used. "At the business" is decided against the address Annai publishes for the business: the business page's fields, else the site's — so also without a business page (`'annai.location' => fn () => null`) a Noren page with an address of its own that differs from the one in `site.txt` is another location with its own address and phone, not a department carrying the main address. As soon as the hours come from more than one location page, an area is named with Noren's label `<location> · <area>` (`Praxis Nord · Praxis`) in the `###` headings of `llms.txt` and in the department names, so two locations with the same area name do not give two identical headings. A location page that is a draft (or below one) is left out and logged, whatever `noren.locations` says: Noren finds drafts, Annai publishes none, and the check reports it as `configuration: …`. Noren switches its page cache off by itself when it computes time-dependent data; Annai asks it only for the pages that show hours (see "Kirby's page cache" under Preview noindex).

Without Noren the business has no hours in its output. Nothing is guessed.

**Koyomi** supplies events. When `koyomi_active()` is true and `annai.koyomi` is not false:

- `llms.txt` lists the **upcoming** events (title, link, when, venue, cancelled); an event without content in the current language is linked under the URL of the language version that has it, with that version's title (an empty title is that version's slug, as for every page link; a link with an empty title from a hook is labelled with its URL). Past events are left out: an agent does not need them. An event page kept out by `annai.exclude.templates` or `annai.exclude.pages` is left out of `llms.txt` and the sitemap, like any other page.
- The sitemap `<lastmod>` of a past event page is the later of its modified time and the event's end. A later edit still wins.
- Koyomi prints its own event JSON-LD in its layout. Annai does not duplicate it. A site with its own layout can add `Koyomi\JsonLd::event()` through `annai.jsonld`.

## robots.txt and AI agents

Annai writes one `*` group and, for each AI agent with a rule, its own group. Under RFC 9309 a crawler follows only the most specific group, so an allowed agent group repeats the `*` disallows:

```
User-agent: *
Allow: /
Disallow: /panel

User-agent: GPTBot
Disallow: /

User-agent: ChatGPT-User
Allow: /
Disallow: /panel

Sitemap: https://example.test/sitemap.xml
```

The known agents and their role. The list was compiled on 2026-10-11 from the vendor documentation linked in `src/Robots.php`; check it against the live pages before you rely on it.

| Agent | Vendor | Role |
|---|---|---|
| `GPTBot` | OpenAI | training |
| `ChatGPT-User` | OpenAI | user |
| `OAI-SearchBot` | OpenAI | search |
| `ClaudeBot` | Anthropic | training |
| `Claude-User` | Anthropic | user |
| `Claude-SearchBot` | Anthropic | search |
| `Google-Extended` | Google | training (token) |
| `Applebot-Extended` | Apple | training |
| `CCBot` | Common Crawl | training |
| `PerplexityBot` | Perplexity | search |
| `Perplexity-User` | Perplexity | user |
| `meta-externalagent` | Meta | training |
| `meta-externalfetcher` | Meta | user |
| `Amazonbot` | Amazon | training |
| `MistralAI-User` | Mistral | user |
| `DuckAssistBot` | DuckDuckGo | search |
| `AI2Bot` | Allen AI | training |
| `Bytespider`, `cohere-ai`, `Diffbot`, `omgili`, `Timpibot`, `PanguBot`, `img2dataset` | community list | training |
| `YouBot` | community list | search |

**Roles.** *training* agents collect content for models. *search* agents feed an AI search index. *user* agents fetch a page because a person asked for it right now.

**The policy** (kirby-starter `docs/agent-ready.md`): never block *user* and *search* agents. An agent that answers a person's question with the business's hours should get them. Training crawlers are a deliberate decision per project; the default is allowed. Blocking a *user* or *search* agent is possible, and the check tool warns about it.

**Presets.** `annai.robots.ai`:

- `null` (default): no AI groups, the file stays as it is today.
- `'blockTraining'`: every *training* agent gets `Disallow: /`. *user* and *search* agents are untouched.
- `'allowAll'`: every known agent gets its own explicit `Allow: /` group.

`annai.robots.agents` overrides single agents on top of a preset: `['GPTBot' => true, 'CCBot' => false]`. Names are compared without regard to case, as crawlers do (RFC 9309): `'gptbot' => false` sets the listed `GPTBot`. Unknown names are written as given, so a bot the list does not know yet can still be set. A name must be a user agent token (letters, digits, `_`, `-`, `.`); any other (`"Claude Bot"`, `"Fo#o"`, a line feed) is logged and left out, so it cannot write a rule line of its own.

`annai.robots.extra` adds raw lines, for example a comment that gives the reason for a block. Under RFC 9309 a blank line does not end a group, so lines written after the last agent group would apply to that agent only. Annai therefore puts them into the `*` group as soon as agent groups exist, and repeats their rules (not the comments) in every allowed agent group, as it does with the `annai.disallow` paths. Without agent groups they stay a block of their own after the `*` group, which crawlers read as part of it. From the first `User-agent:` line on, the extra lines are groups of their own and come after the agent groups:

```php
'annai.robots.extra' => ['# Training is blocked by decision of 2026-10-11, see DATENSCHUTZ.md'],
```

An entry may hold several lines (`"# reason\nUser-agent: Googlebot\nDisallow: /"`); it is read line by line, so its `User-agent:` line starts a group of its own like a separate entry would.

**Closing the site.** A `/` in `annai.disallow` closes it for everyone: the `*` group is then `User-agent: *` with `Disallow: /` alone, without the `Allow: /` line. Next to `Allow: /` the block would not work: the two rules are of equal length, and RFC 9309 §2.2.2 (Google alike) lets the Allow win. The other paths are covered by the block and left out; an agent you allow explicitly (`'ChatGPT-User' => true`) keeps its `Allow: /` group and repeats the other paths only. The check still warns `Disallow: / for everyone`, because that is what the file now does.

A bot filter at the host or in a firewall can still refuse an agent even when robots.txt allows it. Test with `curl -A "Claude-User/1.0" -o /dev/null -w '%{http_code}' https://example.test/`.

## JSON-LD

The home page carries one business node, built from the facts of §6 and Noren:

- `@type` from `annai.type` (`LocalBusiness` by default, `GroceryStore` for a shop), `@id` = `https://example.test/#business`, `name`, `description` (the site's `beschreibung` rendered with `kirbytext()` and reduced to plain text, like the meta description but not shortened), `url`, `image`
- `telephone` in E.164, `faxNumber`, `email`
- `address` as `PostalAddress`, `geo` as `GeoCoordinates` when `annai.geo` is set
- `openingHoursSpecification` and `specialOpeningHoursSpecification` of the primary area
- `department` for every further area (own hours, own `@id` with the area key) and for every unit of a further Noren location (own hours, address and phone, `@id` with the location's page id)
- `sameAs` from `annai.sameAs` and the profile fields; `http(s)` URLs without a space or a control character only, anything else (`@testhof`, a URL with an escape sequence) is logged and left out
- `potentialAction` from `annai.action`, with the real target URL

Empty values are left out, lists stay lists. The output is encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`, so a `</script>` typed into a field cannot break out of the script element, and a byte that is not UTF-8 (a Latin-1 `é` left over from an import) becomes `�` instead of breaking the page.

`annai.action` takes any schema.org action with a target. A phone call is a `tel:` target:

```php
'annai.action' => ['type' => 'CommunicateAction', 'name' => 'Anrufen', 'target' => 'tel:+493023125000'],
```

Use the real target, never the URL of a redirect (such as `/anruf`) that robots.txt blocks: an agent acting for a person does not follow blocked links.

### Hooks

`annai.jsonld` receives the nodes of the page, Annai's facts, and returns the nodes to print. It runs on every page, and its facts carry the Noren units on every page — with Noren that puts every page out of Kirby's page cache, as the home page is. Without the hook only the home page reads the hours; `annai.title` and `annai.description` then see `units` as `[]` on the other pages. Return `[]` for no JSON-LD on a page.

**Several locations in one `@graph`** (one `MedicalClinic` per location, the business node switched off):

```php
'annai.type' => false,
'annai.jsonld' => function (Kirby\Cms\Page $page, array $nodes, array $facts): array {
    if (!$page->isHomePage()) {
        return $nodes;
    }
    $standorte = [   // from your own structure or config; the values are placeholders
        ['slug' => 'nord', 'name' => 'Praxis Nord', 'strasse' => 'Musterweg 1', 'telefon' => '+49 30 000000'],
        ['slug' => 'sued', 'name' => 'Praxis Süd', 'strasse' => 'Bahnhofstraße 2', 'telefon' => '+49 30 000001'],
    ];
    foreach ($standorte as $standort) {
        $nodes[] = [
            '@type'     => 'MedicalClinic',
            '@id'       => $facts['url'] . '/#' . $standort['slug'],
            'name'      => $standort['name'],
            'address'   => ['@type' => 'PostalAddress', 'streetAddress' => $standort['strasse']],
            'telephone' => $standort['telefon'],
        ];
    }
    return $nodes;
},
```

**Website and person nodes next to the business** (a site that presents one person behind the business):

```php
'annai.jsonld' => function (Kirby\Cms\Page $page, array $nodes, array $facts): array {
    if ($page->isHomePage()) {
        $nodes[] = ['@type' => 'WebSite', '@id' => $facts['url'] . '/#website', 'url' => $facts['url'], 'name' => $facts['name'], 'inLanguage' => 'de'];
        $nodes[] = ['@type' => 'Person', '@id' => $facts['url'] . '/#person', 'name' => 'Vorname Nachname', 'url' => $facts['url'] . '/ueber-mich'];
    }
    return $nodes;
},
```

**An identifier on the business node** (a registration number that is not a Panel field):

```php
'annai.jsonld' => function (Kirby\Cms\Page $page, array $nodes, array $facts): array {
    foreach ($nodes as $i => $node) {
        if (($node['@id'] ?? '') === $facts['url'] . '/#business') {
            $nodes[$i]['identifier'] = 'DE-ÖKO-000';   // placeholder
        }
    }
    return $nodes;
},
```

## llms.txt

`llms.txt` is generated on every request from the same fields as the page:

```markdown
# Testhof

> Bio-Hofladen mit Café

## Kontakt

- Adresse: Musterweg 1, 12345 Musterstadt
- Telefon: 030 / 23125000 (+493023125000)
- E-Mail: hof@example.test
- Online bestellen: https://shop.example/testhof

## Öffnungszeiten

- Montag: 8:00–12:00, 14:00–18:00 Uhr
- Dienstag: geschlossen
- 2027-12-24 (Heiligabend): nur 8:30–12:00 Uhr

## Termine

- [Retreat](https://example.test/retreat): Fr, 12.3.2027, 18:00 Uhr, Beispielhof

## Seiten

- [Startseite](https://example.test)
- [Hofladen](https://example.test/laden): Gemüse vom eigenen Feld.

## Optional

- [Impressum](https://example.test/impressum)
```

The sections, in this order: contact (omitted when empty), opening hours (one `###` per named area when there are several), events (upcoming, from Koyomi), free sections, pages (the home page first under its own label — unless `annai.exclude.pages` keeps it out, then it is in no list, as in the sitemap —, then the listed top-level pages that are not excluded — the home page not again, even when its folder is listed — with the page's intro as note, rendered with `kirbytext()` and excerpted to 160 characters), optional pages (templates in `annai.llms.optional`). Every link is absolute.

The language of the headings and day names follows `annai.language`. The texts are in the plugin's translations (`translations/en.php`, `translations/de.php`). They are also registered as Kirby translations (`t('annai.llms.contact')` in a template), but Annai itself always reads its own tables, so a missing or changed site translation cannot turn a German llms.txt English. To change a heading, change the model in `annai.llms.extra`.

The H1 is the business name: the brand field (`marke`), else the site title. Without both, the host of the site URL stands in (llmstxt.org requires an H1 with a name) and the check reports `name missing`. Text from the Panel never carries markup into the file. Entities are decoded, control characters removed and tags (a `<` followed by a letter, `/`, `!` or `?`, up to the next `>`) stripped until nothing changes (so `&lt;img …&gt;` cannot come back as a tag); an e-mail address in angle brackets stays as the bare address, an unclosed `<` before a word loses only the `<` (`Kinder <zehn Jahre` → `Kinder zehn Jahre`), any other `<` is text and stays (`Kinder <6 Jahre`), and a leftover `<`, `>` or `\` is backslash-escaped, and in titles, notes and names `[` and `]` become `(` and `)`, so they form no link. The summary is the site's `beschreibung` rendered with `kirbytext()` and reduced to plain text (tags removed, entities decoded, whitespace collapsed), not shortened: the same text as the JSON-LD `description`, so `**Bio**` and `(link: …)` do not appear as written. A summary a site sets through `annai.llms.extra` is written as Markdown, but a link in it keeps its target only when the target, decoded, lower-cased and without whitespace, starts with `http://`, `https://`, `mailto:`, `tel:`, `/` or `#`; any other link (`javascript:`, `data:`, `file:` …) keeps only its label. A bare target may hold up to three levels of parentheses (CommonMark's minimum); a deeper one is no link. Should PCRE give up on the summary all the same, its text stays and none of its links does. Link targets (also from `annai.llms.extra`) have spaces, parentheses and angle brackets percent-encoded, so a `)` cannot end a link early. Lines from the site's own hooks (`sections`, `intro`, `contact.extra`) follow one rule: each becomes one line with its whitespace collapsed and trimmed (`Text::line()`), an empty one is dropped, and nothing in it is escaped — the hook writes Markdown, and what it writes is what the file holds.

`llms.txt` is served as `text/plain`, the form the llmstxt.org examples use.

Add to it with `annai.llms.extra`, which gets the whole model and the facts and returns the model:

```php
'annai.llms.extra' => function (array $model, array $facts): array {
    $model['summary'] = 'Bio-Hofladen mit Café in Musterdorf. Gemüse von eigenen Feldern, Eier vom Hof.';
    $model['sections'][] = ['title' => 'Anfahrt', 'lines' => ['- Parkplätze direkt am Hof']];
    return $model;
},
```

## Sitemap

`sitemap.xml` lists every page that is not a draft (nor below one) and not excluded (`annai.exclude.templates`, `annai.exclude.pages`; an excluded page id excludes its subpages too). A multilang site without hreflang lists each page once, in the current language — or, for a page without content in it, under its canonical URL in the language that has the content (see Canonical below). With `annai.hreflang` on, each language in which a page has a content file is its own entry, with `xhtml:link` alternates (sitemaps.org "localized versions") and the `<lastmod>` of that language's content file. A page with only English content (`default.en.txt`) gets no German entry, and its `x-default` is the English URL. A page that carries a `noindex` robots meta (excluded, a draft, the preview) offers no hreflang alternates, in the head as in the sitemap: a head must not tell engines not to index a page and offer them its language versions in the next line.

`<lastmod>` comes from the page's modified time and is formatted by Annai itself (`2026-10-11T01:14:00+02:00`). It does not use Kirby's date handler, which turns Kirby's `modified('c')` into an ICU pattern under the `intl` handler.

Extra entries (for example URLs of a shop that Kirby does not know):

```php
'annai.sitemap.extra' => [
    ['loc' => 'https://shop.example/testhof', 'lastmod' => null, 'alternates' => []],
],
```

An entry whose `loc` is not an absolute `http(s)` URL (with a host, valid UTF-8, no spaces or control characters) is skipped and logged. A `lastmod` must be a W3C datetime (`2027-01-01` or `2027-01-01T08:00:00+01:00`); anything else (`'gestern'`) is left out and logged, the entry stays. Every value is escaped for XML, characters XML 1.0 does not allow are removed, so the file stays well-formed. A `Closure(): list` works too.

When a site has more than `annai.sitemap.chunk` URLs (default 50000, the sitemaps.org limit), `sitemap.xml` becomes a `<sitemapindex>` of `sitemap-1.xml`, `sitemap-2.xml` and so on. Each file's entry in the index carries the newest `<lastmod>` of that file; an extra entry's `2000` counts as 1 January 2000 and `2000-05` as 1 May 2000.

**Large page trees.** Every sitemap request (`sitemap.xml`, each `sitemap-<n>.xml`) first builds the complete entry list in memory: one Kirby page object per page plus its URL, `<lastmod>` and, with hreflang on, one URL per language. Measured on 2026-10-12: 50,000 entries with three alternates each render in about 120 ms to 19.8 MB of XML with a peak of 111 MB of memory. Chunking splits the output, not the build: a request for `sitemap-7.xml` still assembles all 50,000 entries and then serves the seventh slice. That is about 2 KB per entry with three alternates, Kirby's own footprint included; a single-language entry is smaller. As a guideline, a `memory_limit` of 128 MB carries the measured 50,000 entries with little headroom, so a multi-language site that lists more than about 40,000 URLs should raise the limit to 256 MB or keep the sitemap smaller with `annai.exclude.pages`. `$site->index()` is read on every request as well, so a very large site should also set `annai.location` (see [Content field contract](#content-field-contract)).

## Head markup and own markup

`snippet('annai/head')` covers most sites. Every text in it (title, description, `og:*` including `og:image:alt`, the site name, and what the `annai.image` and `annai.canonical` hooks return) is one line with its whitespace collapsed; control characters that are not whitespace (an escape sequence pasted into a Panel field) are removed, as in every other output. A site with its own markup uses the page and site methods instead:

```php
<?php
$head = $page->annaiHead();     // array (the head model) or null
$jsonld = $page->annaiJsonLd(); // array (the JSON-LD document) or null
?>
```

To change the snippet's output, create `site/snippets/annai/head.php`; Kirby uses it before the plugin's copy.

Snippet calls (the comments describe the call; they are not part of the template):

```php
<?php snippet('annai/head') ?>                       // uses $page->annaiHead()
<?php snippet('annai/head', ['title' => false]) ?>   // the site prints its own <title>
<?php snippet('annai/head', ['head' => $model]) ?>   // explicit model (tests, own data)
```

Title and description rules:

- **Title.** Home page: brand, then ` · ` and the tagline when set. Other pages: page title, ` · `, brand. A brand that is empty falls back to the site title. `annai.title` as a Closure replaces the rule.
- **Description.** The page's `einleitung` (intro), else the site's `beschreibung`, rendered with `kirbytext()` (Markdown and Kirbytags become plain text, as with the starter's `$field->excerpt()`) and excerpted to `annai.descriptionLength` (155 characters, at a word boundary, with `…`; the ellipsis counts, so the result is never longer than the limit). `annai.description` as a Closure replaces the source; its text is plain text and is excerpted, not rendered: `Kinder <6 Jahre` stays as it is, and so does an `&amp;` the hook writes (it is not decoded either, because nothing was rendered). With `annai.descriptionFallback` on, the chain is intro → the page's own `beschreibung` → the site's `beschreibung`: a step shorter than 50 characters (the check's minimum) gives way to the next one when that is longer; without the option only the intro (else the site's `beschreibung`) is used, as before.
- **Canonical.** The page URL without query parameters. On a multi-language site a page that has no content in the current language (Kirby renders `/en/kontakt` without `kontakt/default.en.txt` with the default language's content) points to the language version that has the content: the default language's URL, else the first language's with content (the `x-default` of the hreflang map, also when `annai.hreflang` is off). `og:url` follows, and so do the sitemap and `llms.txt`: they never list such a URL. With `annai.hreflang` the sitemap has one entry per language version with content; without it the page's one entry is its canonical URL (`/en/english` for a page with English content only), with the modified time of that language's content file, and `llms.txt` links the same URL, with the title and note of that language version. `annai.canonical` as a Closure replaces the whole rule; a Closure that returns `null` leaves it in place. The check tool says `no content in <code>: canonical points to <url>` for such a page, as a note, not as a problem.

### Site and page methods

Annai adds these to Kirby's site and page objects. All of them return the empty value (`null`, `[]`, `''`, `false`) while Annai is off.

| Method | Returns | Meaning |
|---|---|---|
| `annai_option($key, $default)` | mixed | Reads `annai.<key>` |
| `annai_active()` | bool | Is `annai.active` a real `true`? For site snippets that print their own markup only while Annai is off |
| `$site->annaiFacts()` | array | The business facts (see below) |
| `$site->annaiUnits()` | list | Opening-hour units from Noren |
| `$site->annaiRobots()` | string | The robots.txt text |
| `$site->annaiSitemap()` | list of entries | `loc`, `lastmod`, `alternates`, `page` |
| `$site->annaiLlms()` | string | The llms.txt text |
| `$site->annaiEvents()` | list | Upcoming Koyomi events for llms.txt |
| `$site->annaiPreview()` | bool | Is the preview protection active? |
| `$page->annaiHead()` | ?array | The head model, see below |
| `$page->annaiJsonLd()` | ?array | The JSON-LD document of the page |
| `$page->annaiIndexable()` | bool | In sitemap, llms.txt and the check? `false` for a draft and for every page below one |
| `$page->annaiRobots()` | ?string | The robots meta value (`noindex, follow` …) or null; `noindex, nofollow` for a draft and for every page below one |
| `$page->annaiAlternates()` | array | hreflang map, code → URL, incl. `x-default`; `[]` when `annaiRobots()` is not null |
| `Annai\Pages::contentUrl($kirby, $page)` | ?string | On a multi-language site the URL of the language version that has the page's content when the current language has none (the canonical of such a page); `null` otherwise |
| `Annai\Pages::contentLanguage($kirby, $page)` | ?string | The language code behind `contentUrl()`; `null` in the same cases |
| `Annai\Pages::contentIn($page, $code)` / `titleIn($page, $code)` | Content / string | The content and title of that language version (`null`: the current language's); the llms.txt title and note come from them |
| `$page->annaiLastmod()` | ?string | Sitemap `<lastmod>` of the current language's content file |
| `$page->annaiCheck()` | ?array | What engines and agents see on this page |

The facts (`$site->annaiFacts()`), with the names of the keys:

```php
[
    'name' => 'Testhof', 'tagline' => 'Bio-Hofladen', 'description' => '…', 'url' => 'https://example.test',
    'image' => 'https://example.test/assets/teilen.jpg' | null,
    'phone' => '030 / 23125000', 'phoneE164' => '+493023125000', 'fax' => '', 'faxE164' => '', 'email' => '…',
    'street' => 'Musterweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE',
    'geo' => ['lat' => 52.52, 'lng' => 13.41] | null,
    'sameAs' => ['https://www.instagram.com/…'],
    'action' => ['type' => 'OrderAction', 'name' => '…', 'target' => 'https://…'] | null,
    'type' => 'GroceryStore' | false, 'entityId' => 'business',
    'units' => [/* see below */],
]
```

Each unit (`$site->annaiUnits()`, also `facts['units']`) is:

```php
[
    'area' => 'laden' | null, 'location' => 'kontakt' | null,   // page id of the unit's Noren location
    'place' => null | ['street' => …, 'postalCode' => …, 'city' => …, 'phone' => …, 'phoneE164' => …],   // only for a unit at another location than the business page (own address, different from the business address incl. site fields)
    'atBusiness' => bool,   // no address of its own (the business page itself, Noren's only schedule, or a page with hours only): its JSON-LD @id fragment is the bare area key
    'name' => 'Hofladen', 'primary' => true,
    'days' => [0 => ['blocks' => [[480, 720], [840, 1080]], 'note' => 'nur Verkauf' | null], …, 6 => […]],   // 0 = Monday, minutes from midnight
    'holidays' => false,
    'closures' => [['from' => '2027-08-02', 'to' => '2027-08-15', 'label' => 'Betriebsferien', 'hours' => null, 'note' => 'Sommerpause']],
    'schema' => ['openingHoursSpecification' => […], 'specialOpeningHoursSpecification' => […]],
]
```

## Preview

While the site is in a preview protected by the [Sudare](https://github.com/simsimmal/kirby-sudare) package, Annai answers the protection's status:

- every page gets `<meta name="robots" content="noindex, nofollow, noarchive">`;
- `robots.txt`, `sitemap.xml` and `llms.txt` are the normal files, as they are after the go-live. Sudare answers a request without credentials with 401 before Kirby renders, so these requests are authenticated. A route that Sudare's configuration exempts for the request stays closed (see below);
- `robots.txt`, `sitemap.xml` and `llms.txt` are sent with `Cache-Control: no-store`, so a CDN or proxy in front of an exempted route does not keep the file past the go-live until its TTL runs out.

### Hidden

`annai.noindex => true` hides the site explicitly, also where nothing protects it (an open page that must not be indexed). An exempted route is closed the same way while the protection is on. Closed means: `robots.txt` is `User-agent: *` with `Disallow: /` and no sitemap line, `sitemap.xml` is an empty `<urlset>`, `llms.txt` is only its `# Name` line (the host when the site has no name, see [llms.txt](#llmstxt)), and the chunk files `sitemap-<n>.xml` answer 404. The three files are still sent with `Cache-Control: no-store`, and every page carries the noindex meta as in a protected preview.

### How the protection is detected

Annai detects the protection through Sudare's static methods. In a web request it asks `\Sudare\Sudare::active(): bool`, which is `true` once Sudare's guard has run in this request with a valid, switched-on configuration — **also for a request the guard lets through because its path is exempt** (`exempt` in `sudare.php`). Sudare 1.0.0 remembers the configuration before it decides (`Sudare::handle()`: `self::$config = $config;` then `Guard::handle(…)`), and `active()` answers `self::$config?->protects() ?? false`, the state of the configuration, not the outcome for this path. So Annai asks the configuration of the request for each route: `\Sudare\Sudare::config()->isExempt($path, $method, $sent)`. An exempted `robots.txt`, `sitemap.xml`, `llms.txt` or chunk file (`sitemap-<n>.xml`) stays closed during the preview, exactly as with `annai.noindex => true`. Without a guard run (`config()` is `null`) nothing is exempt. On the command line (the check tool, a cron job) no request ran the guard, so Annai asks `\Sudare\Sudare::protects($base): bool` instead, for the site directory (the parent of `site/`): would the guard protect this site? Without the class, without the method or with any answer but `true`, the preview counts as off. `annai.noindex` overrides the detection: `true` forces the hidden state, `false` never sets the preview. Any other value (`1`, `'true'` or `'auto'` from an environment variable) is logged and counts as `null`, the automatic detection, so a typo cannot switch the noindex of a protected preview off, and it cannot close the files either.

**Kirby's page cache.** While the preview is on, Annai keeps every response out of Kirby's page cache (`$kirby->response()->cache(false)`, as Noren does for time-dependent output). Otherwise a page rendered during the preview would sit in the cache with its `noindex, nofollow, noarchive` and be served with it after the preview had ended, until someone cleared the cache. Pages rendered after the preview are cached as usual. **Clear the page cache whenever the preview switches — at the go-live, and when it is switched on** (`site/cache/`, or the cache directory of your setup). The other direction has no flag at all: a page cached while the site was public is served by Kirby straight from the cache, before Annai runs, so after `annai.noindex => true` or a newly protected preview the cached copy keeps coming **without** noindex until the cache is cleared. A cache written before Annai was installed, or by another plugin, is not Annai's to know about either. Caches in front of Kirby (a reverse proxy, a CDN) do not see this flag; purge them at both switches too.

With Noren installed, only the pages that show hours are time-dependent: the home page (its business node carries the hours and the upcoming closures) and every page when an `annai.jsonld` hook is set (it gets the facts with the units on every page). Noren switches the page cache off for those responses. Every other page leaves Noren alone and is cached as usual.

Drafts, which Kirby shows only through a preview link, always get `noindex, nofollow`, also with `annai.noindexExcluded` off. A page below a draft is as unpublished as the draft (Kirby answers 404 to anyone but an editor), so it is treated the same, although Kirby's `isDraft()` is `false` for it: `noindex, nofollow`, not indexable, never a business page.

## Check tool

`vendor/bin/annai-check` boots Kirby on a site directory and prints what search engines and agents see, page by page. It never fetches a URL over HTTP: it reads the same methods the site uses for its output.

```sh
vendor/bin/annai-check [--root=DIR] [--url=https://example.test] [--page=ID ...] [--json]
```

- `--root` defaults to the current directory. The directory needs `vendor/autoload.php` (composer install) and a `site/` directory.
- `--url` sets the site URL. Absolute URLs need a host on the command line; give the production URL. Without it the canonical, `og:url` and the `Sitemap:` line come out as paths (`/laden`), and the report says so once in a note (`  ~ no host in the site URL: pass --url=…`); a note is printed, not counted, so the exit code does not change.
- `--page` checks only the given page id. Repeat it for several pages. An id that is not an indexable page is reported as a site problem.
- `--json` prints the report as JSON (`pages`, `robots`, `sitemap`, `llms`, `preview`, `hidden`, `phones`, `notes`, `problems`); `phones` lists phone and fax as `{"field": "phone", "raw": "030 / 23125000", "e164": "+493023125000", "problem": null}` — `raw` the stored value, `e164` `null` when nothing is published, then `problem` says why: `characters` (more than the number), `invalid` (no valid number), `library` (libphonenumber is not installed); the phone of a further Noren location page carries its page id, `{"field": "phone", "page": "b", "raw": …, "e164": …, "problem": …}`. Control characters U+0080–U+009F are written as `\u009b` escapes, and a byte that is not UTF-8 (a `--page` id in Latin-1) as `�`, as in the JSON-LD; neither ends the check.

The text report prints robots warnings, the sitemap count, the llms.txt sections, whether the preview is on, every phone and fax field with its stored value (as JSON, so a line break shows as `\n`) and its result (`  ~ phone: "030 / 23125000" → +493023125000`, `  ~ fax: "Fax folgt" → (none: more than the number)`, `(none: no valid number)`, `(none: libphonenumber is not installed)` — so every field shows before the go-live, not only in the JSON-LD), also the phone of every further Noren location page with its page id (`  ~ phone (b): "030 23125030" → +493023125030`) and the notes, then one block per page with title, description, canonical, og:image, robots meta, hreflang count and JSON-LD types, its problems and its notes (`no content in <code>: canonical points to <url>` for a page without content in the checked language, see [Head markup](#head-markup-and-own-markup)). Problems are prefixed `  ! `, notes `  ~ ` and are not counted. The last line counts the problems: `4 pages, 2 problems`.

On a site behind Sudare the check sees the preview through `Sudare\Sudare::protects()` (see [Preview](#preview)): it prints `preview: on (noindex, files as live)`, and the sitemap count, the llms.txt sections and the robots warnings as they apply once the preview ends, so a blocked `Claude-User` is visible before the go-live, not after it. With `annai.noindex => true` it prints `preview: on (everything closed)` and robots.txt as served (`Disallow: /`). The JSON report has a `hidden` key next to `preview`. The preview itself is one site problem, `preview on: every page carries noindex`, not one problem per page; the page blocks still show the robots meta.

A static `robots.txt`, `sitemap.xml` or `llms.txt` in the web root (the `index` root, usually `public/`) is reported as a site problem: the web server serves an existing file before Kirby runs (`.htaccess` `!-f`), so such a file silently replaces Annai's route.

Problems the check reports (fixed wording, English):

| Problem | Level |
|---|---|
| `title missing` | page |
| `title longer than 60 characters` | page |
| `description missing` | page |
| `description shorter than 50 characters` | page |
| `description longer than <N> characters` (N = `annai.descriptionLength` + 5, so 160 by default) | page |
| `canonical differs from the page URL` (not for a page without content in the checked language, whose canonical points to the language version with the content: that is the note `no content in <code>: canonical points to <url>`, printed, not counted) | page |
| `og:image is not an absolute https URL` | page |
| `JSON-LD is not valid JSON` | page |
| `Blocks <agent> (<role>): agents in the user's service and AI search engines should stay allowed` | robots warning |
| `Disallow: / for everyone` | robots warning (a `/` in `annai.disallow`; the preview is reported as the site problem below) |
| `Disallow: "<entry>" left out: a path starts with / and holds no space, # or control character, and is UTF-8` | robots warning |
| `preview on: every page carries noindex` | site |
| `duplicate title: <title> (<id>, <id>)` | site |
| `old plugin site/plugins/suchmaschinen is still installed …` | site |
| `static file <robots.txt\|sitemap.xml\|llms.txt> in the web root is served by the web server instead of Annai's route; remove it` | site |
| `no indexable page with id: <id>` | site |
| `name missing: set the brand field (<marke>) or the site title` (no business name: llms.txt has no name for its H1, the home page no JSON-LD and no `<title>`) | site |
| `no business page in the top two page levels: every request searches the whole page index for one; set annai.location …` | site |
| `Noren runs without noren.locations: every page that shows the opening hours or asks for the business page makes Noren walk the whole page index for its location pages; set noren.locations (annai.location does not stop it)` | site |
| `configuration: <message>` for every broken option Annai logged while the report was built (`annai.disallow entry "panel" is not a path …`, `unknown annai.timezone …`) | site |
| `phone: "<stored value>" → <E.164>` and `fax: "<stored value>" → <E.164>`, `→ (none: <reason>)` when the field yields no number (a hint, printed with `  ~ `, not counted; a field that is not empty and publishes nothing — more than the number, no valid number, libphonenumber not installed — is also the `configuration: …` problem above) | note |

Exit codes: `0` no problems, `1` at least one problem or robots warning, `2` usage error, or Annai is off (`annai.active`), `3` Kirby could not boot or the check itself threw. A broken Annai option is not that: the check boots Kirby with `debug` off (whatever `config.php` says), so a broken option is logged, reported as a site problem (`configuration: …`) and gives exit `1` with a complete report; on a site with `debug` on, the same option throws on the page instead. Messages go to STDERR, the report to STDOUT. Run it in your deploy check, next to the tests.

The check tool is English only.

## Upgrading from site/plugins/suchmaschinen

This section is for sites that run the kirby-starter copy `site/plugins/suchmaschinen`. Annai replaces it, together with the JSON-LD snippet `strukturdaten` and the head lines of `kopf.php`. Nothing is aliased: the new names are the only names.

### Why

The starter had three routes in one plugin (`robots.txt`, `sitemap.xml`, `llms.txt`), a JSON-LD snippet, and a block of meta tags in its head. Every site copy changed the same files in slightly different ways: One older copy had its own llms.txt snippet, another its own `@graph`, a third its own `WebSite` and `Person`, a fourth its own llms route. The differences that are improvements (Open Graph site name and locale, `noindex` for excluded templates, a correct `<lastmod>`) are now in one place; the site-specific parts are hooks.

### Coexistence

**The old plugin must be removed.** Both plugins register routes for the same paths (`robots.txt`, `sitemap.xml`, `sitemap-<n>.xml`, `llms.txt`). Kirby takes the first matching route in plugin load order, so with both installed one of the two silently answers and the other is dead code. The check tool reports the old plugin as a site problem.

Do the switch in one deploy: remove `site/plugins/suchmaschinen/` and turn on `annai.active` together. A live site must never run both.

### Steps

1. Install Annai, and in `site/config/config.php` set `annai.active` and the options that replace the old keys (rename table below). A German site without a Kirby `locale` also sets `'annai.language' => 'de'`. A live site keeps its business `@id`: it sets `'annai.entityId' => 'betrieb'` (D5 below).
2. Delete `site/plugins/suchmaschinen/`. Its routes are gone with it. Also delete any static `robots.txt`, `sitemap.xml` or `llms.txt` in the web root (`public/`): the web server serves an existing file before Kirby runs (`.htaccess` `!-f`), so a left-over file silently replaces Annai's route. The check tool reports such a file.
3. Replace the JSON-LD snippet: in `site/snippets/kopf.php` remove the line `<?php snippet('strukturdaten') ?>` (it sits in the head part of the starter's copy), then delete `site/snippets/strukturdaten.php`. Annai's `snippet('annai/head')` prints the JSON-LD itself. Any other template that calls `snippet('strukturdaten')` gets the same change.
4. In `site/snippets/kopf.php`, replace the head lines (`<title>`, `description`, `canonical`, `og:title`, `og:description`, `og:type`, `og:url`, `og:image` from `teilbild`) with `<?php snippet('annai/head') ?>`. Keep the lines that are not search markup (`charset`, `viewport`, `theme-color`, icons, fonts, stylesheets). If the header still prints its own `<title>`, use `snippet('annai/head', ['title' => false])`.
5. Run `vendor/bin/annai-check --url=https://…` and fix what it lists. Then check `curl -sI https://…/robots.txt`, `…/sitemap.xml` and `…/llms.txt` for 200 with `HEAD`.

### Behaviour changes

The output is not byte-identical with the starter in all cases. The changes, each listed in the [CHANGELOG](CHANGELOG.md):

- **D5 Business `@id`** ends in `#business`, the starter's `#betrieb` no longer. A live site keeps its old identifier: **a live site sets `'annai.entityId' => 'betrieb'`** when it switches, because a changed `@id` makes Google see a new entity. New sites take the default `#business`.
- **D6 llms.txt language** follows `annai.language`, then the site language, then English. The starter's German texts need `'annai.language' => 'de'`, see Quick start.
- **D7 `og:site_name` and `og:locale`** are printed whenever they are known. The starter printed them nowhere; several older site copies printed them by hand.
- **D8 Excluded templates get `noindex, follow`** (`annai.noindexExcluded`). The starter printed no robots meta at all; the error page and other excluded templates now say so.
- **D9 `teilbild` is not read.** The share image is `annai.image`. Move the value with one line of config.
- **D14 The `Allow: /` line stays** in the `*` group. RFC 9309 does not require it; the starter's tests and other sites' tests expect it. The one exception: with `/` in `annai.disallow` the group is `Disallow: /` alone, because next to `Allow: /` the Allow wins the tie (RFC 9309 §2.2.2) and the site would stay open.
- **Excerpts end in `…`**, not in ` …` (Kirby's `excerpt()` puts a space before the ellipsis). Meta description and llms.txt notes are otherwise the same text.
- **`sameAs` gets the profile fields** `instagram` and `facebook` by default (`annai.fields.profiles`); the starter used `strukturdaten.sameAs` only. Set `'annai.fields' => ['profiles' => []]` to keep the old list.
- **`faxNumber`** is new in the business node when the `fax` field is filled.
- **Preview** (D2): while the Sudare preview protection is on, every page carries the noindex meta, and robots.txt, sitemap.xml and llms.txt are the normal files (Sudare answers the requests before Kirby renders). Only `annai.noindex => true` or an exemption of the route closes the files: robots.txt `Disallow: /`, empty sitemap, llms.txt with its name only. The starter's copy has only the `X-Robots-Tag` header.
- **Drafts** opened through a preview link get `noindex, nofollow`. The starter printed no robots meta.

### Rename table

| Before (suchmaschinen, strukturdaten, kopf) | Now (Annai) |
|---|---|
| `suchmaschinen.ausgeschlossen` | `annai.exclude.templates` |
| `suchmaschinen.gesperrt` | `annai.disallow` |
| `strukturdaten.typ` | `annai.type` (`false` = no business node) |
| `strukturdaten.bereich` | `annai.area` |
| `strukturdaten.sameAs` | `annai.sameAs` |
| `strukturdaten.geo` with `breite` / `laenge` | `annai.geo` (legacy keys still accepted: `['breite' => …, 'laenge' => …]`) |
| `strukturdaten.aktion` with `typ` / `ziel` | `annai.action` (`type` / `target`; `typ` / `ziel` still accepted) |
| `teilbild` | `annai.image` |
| `praxis.nichtIndexieren` | `annai.exclude.templates` |
| snippet `strukturdaten` | `snippet('annai/head')` (JSON-LD is part of it) |
| `kopf.php` head lines (title, description, canonical, og:*) | `snippet('annai/head')` |
| `kopf.sticky`, `themenfarbe`, `favicon`, `schriften` | stay with the site, unchanged |

Also new: `annai.language`, `annai.fields` (for renamed content fields), `annai.robots.ai`, `annai.robots.agents`, `annai.sitemap.*`, `annai.llms.*`, `annai.jsonld`, `annai.canonical`, `annai.title`, `annai.description`.

The starter's `suchmaschinen.*` keys in the site's `config.php` are simply not read. The check tool shows the result of the switch.

## Privacy

Annai sets no cookies, uses no browser storage, makes no request to a third party and processes no visitor data. The head, the JSON-LD, robots.txt, the sitemap and llms.txt are computed on your server from the site's own content. [PRIVACY.md](PRIVACY.md) describes the implementation in English, and [DATENSCHUTZ.md](DATENSCHUTZ.md) holds the German text block for a site's privacy policy. Both describe the implementation and are not legal advice.

robots.txt, sitemap.xml and llms.txt are **public documents**. They list the site's pages and the business's address, phone number and opening hours. Do not put anything into the `Disallow:` paths, the extra robots lines or the extra sitemap entries that you would not publish: a `Disallow:` path is public too.

## Development

```sh
composer install --ignore-platform-req=ext-gd   # Kirby is a dev dependency, used by the Kirby tests
composer test                                   # or: sh tests/run.sh
ASDF_PHP_VERSION=8.5.3 php tests/CheckTest.php  # one file
```

The tests are plain PHP scripts: each `tests/*Test.php` is a standalone script, exit code 0 means pass. The Kirby-free tests load the source classes directly (`TextTest` also Composer's autoloader, for libphonenumber); the Kirby tests boot a real Kirby instance from the development dependency and load the plugin through a throwaway site. None needs a network connection. The Noren and Koyomi tests look for the sibling packages (`ANNAI_NOREN_PATH`, `ANNAI_KOYOMI_PATH`, or `../kirby-noren`, `../kirby-koyomi`) and print `SKIP` when they are not there.

## Maintenance

This module is open source under the MIT license and is maintained for the author's own projects. Issues and pull requests are welcome, but there is no support promise and no release schedule. Report security problems privately through GitHub's private vulnerability reporting (the "Report a vulnerability" button on the Security tab).

## License

MIT, see [LICENSE](LICENSE).
