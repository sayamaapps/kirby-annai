# Annai

Search-engine and AI-agent output for [Kirby CMS](https://getkirby.com) 5: `robots.txt`, `sitemap.xml`, `llms.txt`, JSON-LD and meta tags.

**Versions:** Kirby `^5.0` · PHP 8.2 to 8.5 · package 2.x. The changes of each release are in [CHANGELOG.md](CHANGELOG.md); the names that still work but will be removed are in [Deprecations](#deprecations).

Annai builds these from the site's own content, so they cannot drift from the pages. The files are served at runtime and name the current domain. The plugin has no styling and processes no visitor data.

## Features

- JSON-LD business node from the site's fields and Noren's opening hours.
- AI agents: user and search agents stay allowed; training crawlers are your choice.
- Upcoming [Koyomi](https://github.com/sayamaapps/kirby-koyomi) events in `llms.txt`.
- Respects [Sudare](https://github.com/sayamaapps/kirby-sudare) preview protection.
- `annai-check` shows what engines and agents see, page by page.

## Requirements

- PHP 8.2 or newer with `ext-mbstring` and `ext-json`
- Kirby 5 (`^5.0`), the site at the host root
- Multilingual sites with language prefixes on one domain are supported. Languages on their own domains in one installation are not.
- Optional: Noren, Koyomi and Sudare, detected and never required. With Noren, set `sayamaapps.noren.locations` (also when `sayamaapps.annai.location` is set), or Noren walks the whole page index on every page view that shows hours.

## Installation

With Composer (recommended), from the site's root:

```sh
composer require sayamaapps/kirby-annai
```

Composer installs to `site/plugins/annai/`. Add that path to `.gitignore` and deploy it like `vendor/`.

Without Composer, download the release ZIP from [GitHub](https://github.com/sayamaapps/kirby-annai/releases), unpack it to `site/plugins/annai/`. Or add the repository as a Git submodule:

```sh
git submodule add https://github.com/sayamaapps/kirby-annai.git site/plugins/annai
```

Without Composer's `giggsey/libphonenumber-for-php-lite`, Annai publishes no phone numbers (the check reports it). Everything else works.

## Usage

```php
// site/config/config.php
return [
    'sayamaapps.annai.active'   => true,           // off until true
    'sayamaapps.annai.language' => 'de',           // texts of llms.txt, English by default
    'sayamaapps.annai.type'     => 'GroceryStore', // schema.org type, LocalBusiness by default
];
```

A German site without a Kirby `locale` needs `sayamaapps.annai.language`, or `llms.txt` says "Contact".

In the layout's `<head>`, replace the hand-written meta tags with:

```php
<?php snippet('annai/head') ?>
```

It prints the title, meta description, robots, canonical, hreflang, Open Graph and JSON-LD. Use `snippet('annai/head', ['title' => false])` if the site prints its own title. To change the output, copy the snippet to `site/snippets/annai/head.php`.

**Content fields.** Annai reads these from the site, or from the business page (`sayamaapps.annai.location`, Noren's location, else the first `betrieb` page; an empty field falls back to the site): `marke` (brand), `zusatz` (tagline), `beschreibung` (description), `einleitung` (intro), `strasse`, `plz`, `ort`, `telefon`, `fax`, `email`, and `profiles` (`instagram`, `facebook`, added to `sameAs`). Rename them with `sayamaapps.annai.fields`.

A phone field holds only the number, such as `030 / 34567000`, which is published as E.164 (`+493034567000`). A label or note in it publishes nothing, and the check reports it.

**Hooks.** Closure options (see [Options](#options)) can replace Annai's values. A throwing hook is logged and Annai's own value is used; a throwing `exclude.pages` hook excludes the page. For example:

```php
'sayamaapps.annai.exclude.pages' => fn (Kirby\Cms\Page $page): bool => $page->template()->name() === 'intern',
```

## robots.txt and AI agents

`sayamaapps.annai.robots.ai` is a preset: `null` (default, no AI rules), `'blockTraining'` (training crawlers get `Disallow: /`) or `'allowAll'`. Presets never block user and search agents. Override single agents with `sayamaapps.annai.robots.agents`. The known agents and their roles are in [docs/reference.md](docs/reference.md#known-ai-agents).

A `/` in `sayamaapps.annai.disallow` closes the site for everyone.

## Sitemap, llms.txt and preview

- `/sitemap.xml` lists indexable pages with `<lastmod>`, and each language version when `sayamaapps.annai.hreflang` is on. Above 50,000 URLs (`sayamaapps.annai.sitemap.chunk`) or 52,428,800 bytes it becomes a sitemap index. Each sitemap request builds all entries in memory: a multilingual site with more than about 40,000 URLs needs `memory_limit` 256 MB.
- `/llms.txt` follows the [llmstxt.org](https://llmstxt.org) format. Add own text with `sayamaapps.annai.llms.extra`. Routes and content types: [docs/reference.md](docs/reference.md#routes).
- While Sudare protects the site, every page carries `noindex, nofollow, noarchive`. `sayamaapps.annai.noindex => true` also closes the files: robots.txt says `Disallow: /`, the sitemap is empty, and llms.txt holds only its name.
- Clear the page cache when a preview switches on and at go-live: cached pages are served before Annai runs.

## Check tool

Run it before go-live. It reports each page, the robots, sitemap, llms.txt and phone findings, and makes no HTTP requests:

```sh
vendor/bin/annai-check --root=/path/to/site --url=https://example.test
```

Pass the production `--url`; options are `--page=ID` and `--json`. Exit codes: `0` no problems, `1` problems or robots warnings, `2` usage error or Annai off, `3` Kirby could not boot. The problem list is in [docs/reference.md](docs/reference.md#check-problems).

## Upgrading

Coming from the predecessor plugin (its folder under `site/plugins/`), in one deploy: remove that plugin and any static `robots.txt`, `sitemap.xml` or `llms.txt` in `public/`, and set `sayamaapps.annai.active`. Replace `snippet('strukturdaten')` and the head lines of your layout with `snippet('annai/head')`. A live site keeps its business `@id` with `'sayamaapps.annai.entityId' => 'betrieb'`. Renamed options: see the [rename table](docs/reference.md#rename-table).

## Options

Every option is written as `sayamaapps.annai.<key>`, for example `'sayamaapps.annai.robots.ai'`. The 2.x name `annai.<key>` still works and is reported as deprecated in debug mode; see [Deprecations](#deprecations). Write options flat (`'sayamaapps.annai.robots.ai'`) or nested under `'sayamaapps'`. `null` means unset. Broken values are logged and the default is used; with Kirby's `debug` option on, they throw.

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | Master switch (only a real `true`) |
| `language` | site language | `en` or `de` for `llms.txt` |
| `fields` | German names | Rename content fields |
| `location` | `null` | Business page id, or `Closure(): ?Page` |
| `locationTemplate` | `'betrieb'` | Business page template, without `location` and Noren |
| `noren` | `true` | Use Noren's opening hours |
| `koyomi` | `true` | Use Koyomi's upcoming events |
| `type` | `'LocalBusiness'` | schema.org type; `false` for none |
| `entityId` | `'business'` | Business `@id` fragment |
| `area` | `null` | Noren area with the main hours |
| `sameAs` | `[]` | Profile URLs (`http(s)`), or `Closure(): list` |
| `geo` | `null` | `['lat' => …, 'lng' => …]` |
| `action` | `null` | `['type', 'name', 'target']`, or `Closure(): ?array` |
| `labels` | `[]` | Own plugin texts by key |
| `image` | `null` | Share image: path, URL, array, or `Closure(Page)` |
| `country` | `'DE'` | `addressCountry` |
| `phoneCountry` | `'49'` | Country code for national numbers |
| `title` | `null` | `Closure(Page, array $facts): string` |
| `description` | `null` | `Closure(Page, array $facts): string` |
| `descriptionLength` | `155` | Meta description length, 20 to 320 |
| `descriptionFallback` | `false` | Fall back to page and site description when the intro is short |
| `canonical` | `null` | `Closure(Page): string` |
| `locale` | `null` | `og:locale`, e.g. `de_DE` |
| `twitterCard` | `null` | `'summary'` or `'summary_large_image'` |
| `noindex` | `null` | `null` automatic (Sudare), `true` hides, `false` never |
| `noindexExcluded` | `true` | `noindex, follow` for pages left out of the sitemap |
| `hreflang` | `false` | hreflang links and sitemap alternates |
| `exclude.templates` | `['error']` | Templates left out of sitemap, llms.txt and check |
| `exclude.pages` | `[]` | Page ids with subpages, or `Closure(Page): bool` |
| `disallow` | `['/panel']` | `Disallow:` paths in the `*` group; `/` closes the site |
| `robots.ai` | `null` | AI preset: `'blockTraining'` or `'allowAll'` |
| `robots.agents` | `[]` | Per-agent overrides, e.g. `['GPTBot' => false]` |
| `robots.extra` | `[]` | Raw robots.txt lines |
| `sitemap.lastmod` | `true` | Emit `<lastmod>` |
| `sitemap.chunk` | `50000` | URLs per sitemap file, 1 to 50000 |
| `sitemap.extra` | `null` | Extra URLs, or `Closure(): list` |
| `jsonld` | `null` | `Closure(Page, array $nodes, array $facts): array` |
| `llms.optional` | `[]` | Templates listed under "Optional" |
| `llms.extra` | `null` | `Closure(array $model, array $facts): array` |
| `timezone` | `'Europe/Berlin'` | Time zone of `<lastmod>` offsets |

## Deprecations

Still working in 2.x, removed in 3.0:

| Old | Use instead | Note |
|---|---|---|
| Options `annai.<key>` | `sayamaapps.annai.<key>` | Read as fallback; reported in debug mode (`option-prefix`) |
| Options `noren.locations`, `noren.active` | `sayamaapps.noren.locations`, `sayamaapps.noren.active` | Read as fallback; reported in debug mode |
| `annai_option($key, $default)` | `Sayamaapps\Annai\Options::get(App::instance(), $key, $default)` | Reported in debug mode (`annai-function`) |
| `annai_active()` | `Sayamaapps\Annai\Options::active(App::instance())` | Reported in debug mode (`annai-function`) |
| Classes `Annai\Text`, `Annai\Pages`, … | `Sayamaapps\Annai\Text`, `Sayamaapps\Annai\Pages`, … | Class aliases in `legacy.php` |

Rename the options before 3.0. From 3.0 the old names are ignored and the defaults apply.

## Privacy

Annai sets no cookies, uses no browser storage and makes no request to third parties. robots.txt, sitemap.xml and llms.txt are public: put nothing into `disallow`, `robots.extra` or `sitemap.extra` that you would not publish.

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE.md).
