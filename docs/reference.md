# Annai reference

Field-level tables linked from the [README](../README.md).

## Routes

| URL | Content type | Contents |
|---|---|---|
| `/robots.txt` | `text/plain` | `*` group with `Allow: /` and the `sayamaapps.annai.disallow` paths, one group per configured AI agent, `sayamaapps.annai.robots.extra` lines, absolute `Sitemap:` line |
| `/sitemap.xml` | `application/xml` | One `<urlset>` of indexable pages, or a `<sitemapindex>` above `sayamaapps.annai.sitemap.chunk` URLs or 52,428,800 bytes |
| `/sitemap-<n>.xml` | `application/xml` | Chunk n, only when the sitemap is split; otherwise 404 |
| `/llms.txt` | `text/plain` | The llms.txt model: H1, summary, sections, pages, optional pages |

Every absolute URL comes from Kirby's base URL, so the sitemap and `Sitemap:` line carry no language prefix. While a preview is on, or the site is hidden, the files are sent with `Cache-Control: no-store`.

## Known AI agents

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

Compiled 2026-10-11 from the vendor pages linked in `src/Robots.php`; check them before you rely on the list. *Training* agents collect content for models, *search* agents feed an AI search index, *user* agents fetch a page because a person asked for it.

## llms.txt sections

Contact (omitted when empty), opening hours (one `###` per area when there are several), upcoming events (Koyomi), free sections (`sayamaapps.annai.llms.extra`), pages (the home page first), optional pages (`sayamaapps.annai.llms.optional`). Headings and day names follow `sayamaapps.annai.language`. The H1 is the brand field, else the site title.

## JSON-LD business node

On the home page, one node built from the fields and Noren:

- `@type` from `sayamaapps.annai.type`; `@id` `<url>/#business` (the fragment is `sayamaapps.annai.entityId`); `name`, `description`, `url`, `image`
- `telephone` (E.164), `faxNumber`, `email`, `address` (`PostalAddress`), `geo` (`GeoCoordinates`)
- `openingHoursSpecification` and `specialOpeningHoursSpecification` of the primary Noren area
- `department` for further areas and for units at another Noren location, each with its own `@id`
- `sameAs` from `sayamaapps.annai.sameAs` and the profile fields; `potentialAction` from `sayamaapps.annai.action`

Empty values are left out. The output is encoded so that a `</script>` typed into a field cannot break out of the script element.

## Check problems

| Problem | Level |
|---|---|
| `title missing` | page |
| `title longer than 60 characters` | page |
| `description missing` | page |
| `description shorter than 50 characters` | page |
| `description longer than N characters` (N = `sayamaapps.annai.descriptionLength` + 5) | page |
| `canonical differs from the page URL` | page |
| `og:image is not an absolute https URL` | page |
| `JSON-LD is not valid JSON` | page |
| `Blocks <agent> (<role>): agents in the user's service and AI search engines should stay allowed` | robots warning |
| `Disallow: / for everyone` | robots warning |
| `Disallow: "<entry>" left out: a path starts with / and holds no space, # or control character, and is UTF-8` | robots warning |
| `preview on: every page carries noindex` | site |
| `duplicate title: <title> (<id>, <id>)` | site |
| `old plugin site/plugins/suchmaschinen is still installed …` | site |
| `static file <robots.txt\|sitemap.xml\|llms.txt> in the web root is served by the web server instead of Annai's route; remove it` | site |
| `no indexable page with id: <id>` | site |
| `name missing: set the brand field (<marke>) or the site title` | site |
| `no business page in the top two page levels: every request searches the whole page index …` | site |
| `Noren runs without noren.locations: …` | site |
| `configuration: <message>` for each broken option logged while the report was built | site |
| `phone: "<stored value>" → <E.164>`, or `→ (none: <reason>)` | note |
| `no content in <code>: canonical points to <url>` | note |

Problems are counted; notes are not. The text report ends with `N pages, M problems`.

## Check report (`--json`)

Keys: `pages`, `robots`, `sitemap`, `llms`, `preview`, `hidden`, `phones`, `notes`, `problems`. A phone entry has `field`, `raw`, `e164` (`null` when nothing is published) and `problem`: `characters` (more than the number), `invalid` (no valid number) or `library` (libphonenumber is not installed). A further Noren location page's phone also has `page`.

## Methods

Annai adds these to Kirby's site and page objects. They return `null`, `[]`, `''` or `false` while Annai is off.

| Method | Returns |
|---|---|
| `annai_option($key, $default)`, `annai_active()` | Reads `sayamaapps.annai.<key>`, then `annai.<key>`; is Annai on? |
| `$site->annaiFacts()` | Business facts (below) |
| `$site->annaiUnits()` | Opening-hour units from Noren |
| `$site->annaiRobots()`, `$site->annaiLlms()` | robots.txt and llms.txt text |
| `$site->annaiSitemap()` | Entries: `loc`, `lastmod`, `alternates`, `page` |
| `$site->annaiEvents()` | Upcoming Koyomi events for llms.txt |
| `$site->annaiPreview()` | Is the preview protection active? |
| `$page->annaiHead()` | Head model (array or null) |
| `$page->annaiJsonLd()` | JSON-LD document (array or null) |
| `$page->annaiIndexable()` | In sitemap, llms.txt and the check? |
| `$page->annaiRobots()` | Robots meta value (`noindex, follow` …) or null |
| `$page->annaiAlternates()` | hreflang map, code → URL, incl. `x-default` |
| `$page->annaiLastmod()` | Sitemap `<lastmod>` of the current language |
| `$page->annaiCheck()` | What engines and agents see on this page |
| `Annai\Pages::contentUrl($kirby, $page)`, `contentLanguage(...)` | Canonical language version on multilingual sites |

## Facts and units

```php
[
    'name' => 'Gasthaus Muster', 'tagline' => 'Regionale Küche', 'description' => '…', 'url' => 'https://example.test',
    'image' => 'https://example.test/assets/teilen.jpg' | null,
    'phone' => '030 / 34567000', 'phoneE164' => '+493034567000', 'fax' => '', 'faxE164' => '', 'email' => '…',
    'street' => 'Musterweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt', 'country' => 'DE',
    'geo' => ['lat' => 52.52, 'lng' => 13.41] | null,
    'sameAs' => ['https://www.instagram.com/…'],
    'action' => ['type' => 'OrderAction', 'name' => '…', 'target' => 'https://…'] | null,
    'type' => 'GroceryStore' | false, 'entityId' => 'business',
    'units' => [/* see below */],
]
```

Each unit (`$site->annaiUnits()`, also `facts['units']`):

```php
[
    'area' => 'kueche' | null, 'location' => 'kontakt' | null,   // page id of the unit's Noren location
    'place' => null | ['street' => …, 'postalCode' => …, 'city' => …, 'phone' => …, 'phoneE164' => …],
    'atBusiness' => bool,   // true: the unit has no address of its own
    'name' => 'Küche', 'primary' => true,
    'days' => [0 => ['blocks' => [[480, 720], [840, 1080]], 'note' => 'nur Verkauf' | null], …],   // 0 = Monday, minutes from midnight
    'holidays' => false,
    'closures' => [['from' => '2027-08-02', 'to' => '2027-08-15', 'label' => 'Betriebsferien', 'hours' => null, 'note' => 'Sommerpause']],
    'schema' => ['openingHoursSpecification' => […], 'specialOpeningHoursSpecification' => […]],
]
```

## Rename table

For sites that ran the predecessor plugin, with its `strukturdaten` snippet and `kopf.php` head lines.

| Before | Now |
|---|---|
| `suchmaschinen.ausgeschlossen` | `sayamaapps.annai.exclude.templates` |
| `suchmaschinen.gesperrt` | `sayamaapps.annai.disallow` |
| `strukturdaten.typ` | `sayamaapps.annai.type` (`false` = no business node) |
| `strukturdaten.bereich` | `sayamaapps.annai.area` |
| `strukturdaten.sameAs` | `sayamaapps.annai.sameAs` |
| `strukturdaten.geo` with `breite` / `laenge` | `sayamaapps.annai.geo` (legacy keys still read) |
| `strukturdaten.aktion` with `typ` / `ziel` | `sayamaapps.annai.action` (`type` / `target`; legacy keys still read) |
| `teilbild` | `sayamaapps.annai.image` |
| `praxis.nichtIndexieren` | `sayamaapps.annai.exclude.templates` |
| snippet `strukturdaten` | `snippet('annai/head')` (JSON-LD is part of it) |
| `kopf.php` head lines (title, description, canonical, og:*) | `snippet('annai/head')` |
| `kopf.sticky`, `themenfarbe`, `favicon`, `schriften` | stay with the site |

Also new: `sayamaapps.annai.language`, `sayamaapps.annai.fields`, `sayamaapps.annai.robots.*`, `sayamaapps.annai.sitemap.*`, `sayamaapps.annai.llms.*`, `sayamaapps.annai.jsonld`, `sayamaapps.annai.canonical`, `sayamaapps.annai.title`, `sayamaapps.annai.description`. The old `suchmaschinen.*` keys are no longer read.
