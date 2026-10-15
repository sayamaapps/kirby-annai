# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to the option names, the content field contract** (the German
  field names the Panel writes) **or to the default output** (what robots.txt,
  the sitemap, llms.txt and the head show without configuration) is a **major**
  version: a site would publish other data after a routine update.
- **A change to the privacy text** (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its privacy
  policy.

## [Unreleased]

### Changed

- `annai.descriptionFallback` (opt-in) reads the page's own description field
  (`beschreibung`) as a second step: intro → page `beschreibung` → site
  `beschreibung`. Each step gives way to the next only while it is shorter than
  50 characters and the next one is longer. Off, nothing changes.

## [2.0.0] — 2026-10-15

Major under the rule above: the default output of llms.txt changes (the phone
line). Everything else is additive or affects a protected preview only.

### Added

- `annai.descriptionFallback` (boolean, default `false`): an intro shorter than
  the check's 50 characters gives way to the site description (`beschreibung`)
  when that is longer, so the meta description is not a stub. The
  `annai.description` hook is not affected. Off, nothing changes.

### Changed

- **Default output of llms.txt:** the phone line shows the number as a `tel:`
  URI, `- Telefon: 030 / 23125000 (tel:+493023125000)` (was `(+493023125000)`),
  in the contact block and under unit and location headings. Agents get the dialable
  target. Under the versioning rule above this is a **major** change: a site
  updating its llms.txt sees the new form.
- While the Sudare preview protection is on, robots.txt, sitemap.xml and
  llms.txt are the normal files (`Cache-Control: no-store` stays), not
  `Disallow: /`, an empty urlset and the name only. Sudare answers a request
  without credentials with 401 before Kirby renders, so these requests are
  authenticated. A route that Sudare's configuration exempts for the request
  stays closed: `Sudare\Sudare::config()->isExempt()` is asked per route
  (`/robots.txt`, `/sitemap.xml`, `/sitemap-<n>.xml`, `/llms.txt`). Every page
  keeps the noindex meta.
- The explicit `annai.noindex => true` closes the three files as well
  (`Pages::hidden()`): robots.txt `Disallow: /` without a sitemap line, an
  empty sitemap.xml, llms.txt with its H1 only, chunk files 404. This is the
  closed state of 1.0.0, now separate from the protected preview.
- The check tool (`annai-check`) reports `preview: on (noindex, files as live)`
  for a protected preview and `preview: on (everything closed)` for the hidden
  state; the JSON report has a `hidden` key next to `preview` (the explicit
  setting only).

### Documentation

- README (Preview: Hidden, How the protection is detected), SPEC §7 and the
  preview wording of `PRIVACY.md` and `DATENSCHUTZ.md`. The statement in the
  privacy texts is unchanged (pages carry noindex, the files sit behind the
  protection, an exempted route stays closed), so they carry no
  `[privacy text]` marker.

## [1.0.0] — 2026-10-14

First release as a Composer package. Before 1.0.0 the same job lived as copies
in each site: `site/plugins/suchmaschinen` (the three routes), the snippet
`site/snippets/strukturdaten.php` (JSON-LD) and the head part of
`site/snippets/kopf.php` in kirby-starter, with variants in older site copies
(`llms` snippet, `kanonisch.php`), another copy (`@graph`, noindex), a third
(`WebSite`, `Person`) and a fourth (own llms route). The changes below are
measured against kirby-starter's copy.

### Changed — renamed and restructured (breaking)

Options, snippets and plugin names are Annai's. There are no fallbacks for the
old names: a site switching from the copy updates its config and snippets once,
using the table below.

| Before (kirby-starter copy) | Now (Annai) |
|---|---|
| plugin `site/plugins/suchmaschinen` (routes `robots.txt`, `sitemap.xml`, `llms.txt`) | plugin `sayamaapps/annai`, routes of the same paths, `GET\|HEAD` |
| `suchmaschinen.ausgeschlossen` | `annai.exclude.templates` |
| `suchmaschinen.gesperrt` | `annai.disallow` |
| `strukturdaten.typ` | `annai.type` |
| `strukturdaten.bereich` | `annai.area` |
| `strukturdaten.sameAs` | `annai.sameAs` (profile fields are read from `annai.fields`) |
| `strukturdaten.geo` (`breite`, `laenge`) | `annai.geo` (`lat`, `lng`; legacy keys still accepted) |
| `strukturdaten.aktion` (`typ`, `ziel`) | `annai.action` (`type`, `target`; legacy keys still accepted) |
| `teilbild` | `annai.image` |
| `praxis.nichtIndexieren` | `annai.exclude.templates` |
| snippet `strukturdaten` | `snippet('annai/head')` (JSON-LD is part of it) |
| `kopf.php` head lines (title, description, canonical, og:title, og:description, og:type, og:url, og:image) | `snippet('annai/head')` |
| `kopf.sticky`, `themenfarbe`, `favicon`, `schriften` | unchanged, stay with the site |

### Changed — behaviour (starter output)

- **D5** The business `@id` ends in `#business`; the starter printed `#betrieb`.
  Option `annai.entityId`; a live site keeps `#betrieb` with
  `'annai.entityId' => 'betrieb'` (a changed `@id` is a new entity for Google).
- **D6** The llms.txt texts follow `annai.language` (then the site language,
  the default language, Kirby's `locale`, English). A German site without
  `locale` needs `'annai.language' => 'de'`.
- **D7** `og:site_name` and `og:locale` are printed whenever they are known.
- **D8** Excluded templates (`annai.exclude.templates`, default `error`) get
  `noindex, follow` (`annai.noindexExcluded`).
- **D9** `teilbild` is not read; the share image is `annai.image`.
- **D14** robots.txt keeps `Allow: /` in the `*` group, except when `/` is in
  `annai.disallow`: then the group is `Disallow: /` alone (RFC 9309 §2.2.2 lets
  an `Allow: /` of equal length win, so the site stayed open); an explicitly
  allowed agent keeps `Allow: /` and the other paths.
- Excerpts (meta description, llms.txt notes) end in `…` instead of ` …`, and
  are never longer than the limit, ellipsis included, also for a text without
  a space.
- The profile fields `instagram` and `facebook` are appended to `sameAs` by
  default (`annai.fields.profiles`); `faxNumber` is new in the business node.
- **D2** While the Sudare preview protection is on, every page is noindex,
  robots.txt is `Disallow: /`, sitemap.xml is an empty urlset and llms.txt holds
  only the name. On the command line Annai asks `Sudare\Sudare::protects()`, so
  the check tool sees the preview too. A non-boolean `annai.noindex` is logged
  and counts as automatic. While the preview is on, the response is kept out
  of Kirby's page cache, so a page rendered during the preview is not served
  with its noindex after the preview has ended (clear the cache at the
  go-live anyway, and when the preview is switched on: a page cached while
  public is served from the cache without noindex until then, see the
  README), and robots.txt, sitemap.xml and llms.txt are sent with
  `Cache-Control: no-store` (the chunk files `sitemap-<n>.xml` answer 404
  while the preview is on), so a CDN or proxy in front of an exempted route
  does not keep the closed file past the go-live.
- Drafts (preview links) get `noindex, nofollow`; the starter printed nothing.
  A page below a draft (Kirby's `isDraft()` is false for it, but only an editor
  can open it) is treated as the draft: `noindex, nofollow`, not indexable, no
  hreflang, never the business page.
- A page with a `noindex` robots meta (excluded, a draft, the preview) offers
  no hreflang alternates in the head. The sitemap data (`Check::site()`,
  `$site->annaiSitemap()`) keeps one entry per language while the preview is
  on, as the file is served after the go-live; the route itself serves an
  empty urlset during the preview.
- `sameAs` publishes `http(s)` URLs only. A handle (`@testhof`) or a host
  without a scheme in `annai.sameAs` or a profile field is logged and left out.
- **Phone fields hold only the number: an input contract, read by Google's
  libphonenumber (decision 2026-10-13).** The starter dialled every
  digit of the field (`030 23125000 (Mo–Fr 9–12)` → `+493023125000912`, two
  numbers → one 22-digit `telephone`, `Tel. 23125` → `+4923125`) and dropped
  `(0)` only after `+49`/`0049`. Annai lets a field through a small character
  gate — digits, spaces (also U+00A0 and U+202F), a `+` as the first
  character, `/`, `-` and parentheses; no `.` — and has
  `giggsey/libphonenumber-for-php-lite` (new Composer requirement) read it:
  `parse()` with the region of `annai.phoneCountry` (`49` → DE),
  `isValidNumber()`, E.164 (`+49 (0)30 23125000`, `(030) 23125000`,
  `0049 30 23125000` → `+493023125000`; `+43 (0)1 234 56` → `+43123456`). A
  field with any other character — a label, a note, a Kirbytag, HTML, a date,
  a dot, a line break — or without a valid number publishes no `telephone` or
  `faxNumber`, logs `field telefon holds more than the number (…)` or
  `… holds no valid phone number (…)` and is a problem in the check; an empty
  field says nothing. `annai.phoneCountry` `'0043'` or `'+43'` is read as
  `43`. **Annai does not look for a number in free text:** a phone field
  holds a bare number on every site, and reading text for a number cannot be
  done right — libphonenumber itself turns `0351 / 12.12.2010` into
  `+4935112122010`. Without Composer (a ZIP copy) the library is missing:
  Annai then publishes no phone number and logs why; with Kirby's `debug` on,
  every page throws that message instead, as for a broken option. The check
  says so on a site whose Kirby comes from Composer; next to a ZIP Kirby it
  needs a `vendor/autoload.php` and does not run.
- Without a business page (`'annai.location' => fn () => null`) a Noren page
  with an address of its own is compared with the address in `site.txt`, as
  the business node publishes it: with another address it is a further
  location with its own address and phone, in the JSON-LD and in llms.txt,
  not a department carrying the main address.
- With hours from more than one location page an area is named with Noren's
  label `<location> · <area>` in the llms.txt headings and the department
  names, so two locations with the same area name no longer give two
  identical `### Praxis` headings.
- An `annai.area` that matches no area with hours is logged; it used to fall
  back to the first area in silence.
- The hreflang check for the default language's content file reads Kirby's
  `@internal` `Version::contentFile()`; should a Kirby release close it,
  Kirby's own answer stands (hreflang stays) instead of an exception in the
  head of every page. The README names the dependency.
- A site without a brand field and without a site title: the llms.txt H1 is
  the host (it used to be `# ` alone; llmstxt.org requires a name), and the
  check reports the cause once as `name missing: set the brand field (marke)
  or the site title`.
- robots.txt stays UTF-8 (RFC 9309 §2.2): an `annai.disallow` entry that is
  not UTF-8 is logged and left out like any other invalid path, a byte that is
  not UTF-8 in `annai.robots.extra` becomes U+FFFD. Lines are split at CR, LF
  and CRLF only, never inside a multibyte character, so `Disallow: /Åland/`
  stays one rule.
- On a multi-language site a page without content in the current language
  (Kirby renders `/en/kontakt` without `kontakt/default.en.txt` with the
  default language's content) gets a canonical — and `og:url` — on the
  language version that has the content: the default language's URL, else the
  first language's with content, with or without `annai.hreflang`. It used to
  point to itself, indexable: duplicate content in every language a page was
  not translated into. `annai.canonical` keeps its precedence. The check
  reports such a page with the note `no content in <code>: canonical points
  to <url>` (printed, not counted).
- Control characters are removed from every head text: from `<title>`, the
  meta description and the `og:*` tags (an escape sequence in `marke`, a title
  or `einleitung` used to reach the head raw: invalid HTML, an escape sequence
  for whoever curls the page), from `og:image:alt` (usually a Panel field,
  `$page->image()->alt()`) and from the URL and canonical an `annai.image` or
  `annai.canonical` hook returns. A `sameAs` URL (option or profile field)
  with a space or a control character in it is logged and left out. The log
  line shows such an entry with every control character escaped (a CSI as
  `\u009b`), as the phone log lines do; so do the log lines for an
  `annai.disallow` entry and an `annai.robots.agents` name and the robots
  warning of the check.
- The sitemap and `llms.txt` name a page by its canonical URL. A page without
  content in the current language (`/english` with only `default.en.txt`) is
  listed without `annai.hreflang` as `/en/english`, with the modified time of
  the English file, and `llms.txt` (home, pages, optional pages, Koyomi
  events) links the same URL, so neither lists a URL whose canonical points
  elsewhere.
- `llms.txt` takes a page's title and note from the language version it links:
  `- [English only](…/en/english): An English intro …` instead of the slug of
  the empty German version without a note (menu pages, optional pages, Koyomi
  events). An empty title is the slug of that language version, for a Koyomi
  event as for every page link; any `llms.txt` link with an empty title —
  also from a hook — takes its URL as the label, never `- [](…)`.
- Languages on their own domains (one Kirby installation, a language with its
  own `url` in `site/languages/*.php`) are **not supported**; the README says so.
  Annai does not filter the sitemap per host: that made such a site worse
  (with a fixed `url` a page fell out of every sitemap). A multilingual site
  uses one domain with language prefixes (`/en/`, `/fr/`) — fully supported.
- A draft named in `noren.locations` (Noren finds drafts) is left out of the
  hours and logged: its address, phone and hours stay out of llms.txt and the
  home page's JSON-LD departments, as the page itself is in no sitemap.
- llms.txt lists the home page only when `annai.exclude.pages` does not keep it
  out; it used to come first under its label whatever the exclusion said.
- The sitemap `<lastmod>` is formatted by Annai from the integer modification
  time, not with Kirby's date handler (the `intl` handler turns `modified('c')`
  into an ICU pattern).
- llms.txt is served as `text/plain` (as before); the link targets are absolute.
- The business page (template `betrieb`) is looked up on the top level, then
  on the second level, and only then in the whole page index, once per
  request: the starter built `$site->index()` on every page view (seven to
  ten times the time of a page view with 2,000 blog posts). A top-level business page wins over
  one on the second level even where the index lists that one first. Without
  a business page in the top two levels the check reports it ("set
  annai.location"); `'annai.location' => fn () => null` says there is none.
- On a multi-language site the robots.txt `Sitemap:` line, the sitemap index and
  the business `@id`/`url` use Kirby's base URL (`/sitemap.xml`, `/#business`),
  not the current language's URL (`/de/sitemap.xml` did not exist).

### Added

- Package `sayamaapps/kirby-annai`, Kirby plugin id `sayamaapps/annai`, PHP
  namespace `Annai\`, installed to `site/plugins/annai/`.
- **Head snippet** `snippets/annai/head.php` for title, description, robots,
  canonical, hreflang, Open Graph, Twitter card and JSON-LD, and the page and
  site methods behind it (`annaiHead()`, `annaiJsonLd()`, `annaiRobots()`,
  `annaiAlternates()`, `annaiLastmod()`, `annaiIndexable()`, `annaiCheck()`,
  `annaiFacts()`, `annaiUnits()`, `annaiSitemap()`, `annaiLlms()`, `annaiEvents()`).
- **robots.txt for AI agents**: presets `annai.robots.ai` (`blockTraining`,
  `allowAll`), overrides per agent (`annai.robots.agents`) and raw lines
  (`annai.robots.extra`; an entry with line feeds inside is read line by line,
  so a group written into one string, `"# reason\nUser-agent: Googlebot\nDisallow: /"`,
  is a group of its own instead of landing inside the `*` group and in every
  allowed agent group). Known agents with their role (training, search, user)
  are listed in `Robots::AI_AGENTS`; the check warns when a user or search
  agent is blocked. Agent names compare case-insensitively (`'gptbot'` sets
  `GPTBot`); a name that is not a user agent token (letters, digits, `_`, `-`,
  `.`) is logged and left out, so a line feed cannot add a rule. An
  `annai.disallow` entry that is no path (no leading `/`, a space, a `#`, a
  control character, a byte that is not UTF-8) is logged, left out and named
  by the check, which names the UTF-8 rule too (`… no space, # or control
  character, and is UTF-8`).
- **Sitemap**: `<lastmod>` from the page's modification time, sitemap index
  with chunks above `annai.sitemap.chunk` (each with the newest `<lastmod>` of
  its file; a year alone counts as its 1 January; a chunk size that is not a
  whole number from 1 to 50,000 is logged and gives 50,000), extra entries
  (`annai.sitemap.extra`), hreflang alternates (`annai.hreflang`; only languages
  with a content file, also for the default language, which Kirby 5 counts as
  present whenever the page folder exists; each language entry with the
  `<lastmod>` of its own content file), and the
  Koyomi rule that a past event page's lastmod is its end when that is later.
  A chunk answers under its canonical number only (`sitemap-1.xml`;
  `sitemap-01.xml` is 404, not a duplicate of chunk 1, and so is
  `sitemap-1Xxml`: the dot in the route pattern is escaped).
- **llms.txt** in the llmstxt.org format: contact, opening hours per area from
  Noren (an area at another location with its own address and phone; an area
  without a name gets no empty `###` heading), upcoming
  events from Koyomi, sections, pages (the home page once, under its own
  label, also when its folder is listed), optional pages; hook `annai.llms.extra`.
  Lines from a site's own hooks (`sections`, `intro`, `contact.extra`) follow
  one rule: one line, whitespace collapsed and trimmed, nothing escaped.
  Panel text loses tag-shaped markup only: `Kinder <6 Jahre` stays, as
  `Kinder \<6 Jahre`. The summary's link pattern runs in linear time (up to
  three levels of parentheses in a target); should PCRE give up on it anyway,
  the summary keeps its text without links instead of disappearing. The tag
  strip is linear too, also for a run of `<` with nothing tag-like after it
  (or `&lt;` × 50,000, decoded), in every Panel text and the preview H1.
- **JSON-LD** for the business (`annai.type`, `annai.area`, `annai.geo`,
  `annai.sameAs`, `annai.action` with a real target), departments for further
  areas and further Noren locations (each with its own hours and address; the
  primary area, whose hours the business node carries, is always one of the
  business page). A department of a Noren page that carries hours only (the
  address on `annai.location`, in site.txt, or none with `fn () => null`) keeps
  the bare `#<area>` as its `@id`, like one on the business page; only a page
  with an address of its own takes its page id into the fragment, so two pages
  with the same area key never share an `@id`. Hook `annai.jsonld` for
  `@graph` sites. Encoded with
  `JSON_HEX_TAG | JSON_HEX_AMP`; a byte that is not UTF-8 in a field becomes
  U+FFFD (`JSON_INVALID_UTF8_SUBSTITUTE`) instead of an error page.
- With Noren installed, only the pages that show hours ask Noren for them:
  the home page (its business node) and every page when an `annai.jsonld`
  hook is set. Every Noren call takes the response out of Kirby's page cache,
  so the other pages stay cacheable; the `annai.title` and
  `annai.description` hooks see `units` as `[]` there.
- **Meta description** from the page intro or the site description, rendered
  with `kirbytext()` like the starter and excerpted at a word boundary
  (`annai.descriptionLength`, a whole number from 20 to 320; anything else is
  logged and gives 155); hooks `annai.title`, `annai.description`,
  `annai.canonical`. The `annai.description` hook delivers plain text: it is
  excerpted, not rendered, so `Kinder <6 Jahre` keeps its text and an `&amp;`
  the hook writes stays as written. The llms.txt page notes are rendered the
  same way, and so are the JSON-LD `description` and the llms.txt summary: the
  site description as plain text (tags stripped, entities decoded, whitespace
  collapsed), not shortened — the starter put `**Bio**` and `(link: …)` in as
  written. A byte that is not UTF-8 becomes U+FFFD before `kirbytext()`, which
  would return an empty paragraph.
- **Open Graph** `og:image` with width, height and alt (`annai.image`), `og:locale`
  (`annai.locale`), Twitter card (`annai.twitterCard`).
- **Preview noindex** through `\Sudare\Sudare::active()` and, on the CLI, `\Sudare\Sudare::protects()` (`annai.noindex`).
  The contract is spelled out in the README and SPEC §7 and verified against
  Sudare 1.0.0: `Sudare::active()` is true for every request the guard ran on
  with a switched-on configuration, also for a path the site exempts
  (`robots.txt`, `sitemap.xml`, `llms.txt` opened for a crawler), so the
  preview answers of those routes do reach an exempted crawler.
- **Hooks** that throw or return the wrong type are logged and Annai's own
  result is used (with `debug` on they throw); a throwing `exclude.pages`
  Closure excludes the page. A `jsonld` result that cannot be encoded (`NAN`,
  `INF`, too deep) is logged and left out, the page renders. A hook option
  that holds anything but a Closure (`'annai.title' => 'Eigener Titel'`, an
  array of nodes as `annai.jsonld`, a string as `annai.llms.extra`, `42` as
  `annai.location`) is logged once per process (`annai.title must be a
  Closure, not 'Eigener Titel' — ignored.`), counts as unset and is a
  `configuration: …` problem in the check.
- `annai.active` with anything but a real `true` or `false` (`'true'` from an
  environment variable) is logged like every other boolean option.
- **Open Graph** `og:image:width`, `og:image:height` and `og:image:alt` are
  printed only together with an `og:image` URL.
- **Field names** are overridable (`annai.fields`), so a site with other field
  names needs no content change.
- **Phone numbers** as E.164 (`annai.phoneCountry`), read by Google's
  libphonenumber (`giggsey/libphonenumber-for-php-lite`, Apache-2.0, a
  Composer requirement); a field that holds only a prefix (`0`, `(0)`, `+49`)
  publishes nothing instead of `+49` or `+`.
- **Check tool** `vendor/bin/annai-check` with `--root`, `--url`, `--page`
  (repeatable) and `--json`, and the problem list of the README. Behind a
  preview it reports the preview once as a site problem (not one noindex
  problem per page) and the robots warnings of the configuration that applies
  after the preview, so a blocked user agent shows before the go-live. A static
  `robots.txt`, `sitemap.xml` or `llms.txt` in the web root, which the web
  server serves instead of the route, is a site problem. Without `--url` the
  report notes once that the URLs have no host (`  ~ no host in the site URL:
  pass --url=…`), printed, not counted. The description limit follows
  `annai.descriptionLength` (length + 5, so 160 by default). The check boots
  Kirby with `debug` off: a broken option does not end it at the first one
  with "check failed" (exit 3) but is reported as a site problem
  (`configuration: …`) in a complete report, exit 1. `--json` writes a byte
  that is not UTF-8 (a `--page` id in Latin-1, a folder name from an import)
  as U+FFFD, as the JSON-LD does.
- The check shows every phone and fax field with its stored value and its
  result (`  ~ phone: "030 / 23125000" → +493023125000`,
  `  ~ fax: "Fax folgt" → (none: more than the number)`; `phones` in `--json`
  with `raw`, `e164` and `problem`), as a hint that is not counted, so a
  person sees each field before the go-live. The phone of every further Noren
  location page is shown too, with its page (`  ~ phone (b): …`, `page` in
  `--json`).
- With Noren in use and without `noren.locations`, Noren finds its location
  pages by walking the whole page index on every page view that asks for the
  business page (about twenty times the head time with 2,000 posts). The check
  reports it with its own text (`Noren runs without noren.locations: … set
  noren.locations (annai.location does not stop it)`), also when
  `annai.location` is set: that spares Annai's lookup, not Noren's walk for the
  hours of the home page, `llms.txt` and an `annai.jsonld` hook. README and
  SPEC recommend `noren.locations`.
- The README names the memory a sitemap request needs on a large page tree
  (measured: 50,000 entries with three alternates, 111 MB peak; chunking splits
  the output, not the build) and the host-root requirement shared with Sudare
  (`Disallow:` paths are host-root paths).
- Translations (`en`, `de`) registered as Kirby translations `annai.*`.
- Noren, Koyomi and Sudare are suggested, never required.

### [privacy text]

- `DATENSCHUTZ.md` (German text block for a site's privacy policy) and
  `PRIVACY.md` (English summary) are new. They state what the plugin does not
  do: no cookies, no browser storage, no requests to third parties, no visitor
  data. robots.txt, sitemap.xml and llms.txt are public documents: they list
  the site's pages and the business's address, phone number and opening hours,
  and the `Disallow:` paths are public too.
