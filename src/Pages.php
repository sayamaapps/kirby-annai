<?php

namespace Annai;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\Page;
use Kirby\Content\Content;
use Kirby\Content\PlainTextStorage;

/**
 * Which pages count, exclusions, preview state, hreflang, lastmod, Koyomi
 * events (docs/SPEC.md §3.9).
 *
 * Every public list method returns [] when Annai is off (docs/SPEC.md §3.9). The
 * page-level checks (excluded, robots, alternates, lastmod) are plain
 * functions of their input; index.php checks Options::active() before it
 * calls them.
 */
final class Pages
{
    /**
     * Kirby's base URL without a trailing slash: where robots.txt, sitemap.xml
     * and sitemap-<n>.xml live and what the business @id is built from, the
     * same in every language ($site->url() is the current language's URL).
     */
    public static function base(App $kirby): string
    {
        return rtrim($kirby->url(), '/');
    }

    /**
     * Drafts, excluded templates and excluded ids never count (docs/SPEC.md §3.9).
     * An id in `annai.exclude.pages` also excludes everything below it:
     * ['intern'] keeps out /intern/protokoll-vorstand too. A Closure's result
     * counts as truthy or falsy (1 excludes), not only a real true; a Closure
     * that throws excludes (logged).
     */
    public static function excluded(App $kirby, Page $page): bool
    {
        if (self::isDraft($page)) {
            return true;
        }
        if (in_array($page->intendedTemplate()->name(), Options::excludeTemplates($kirby), true)) {
            return true;
        }
        $pages = Options::excludePages($kirby);
        if ($pages instanceof Closure) {
            // Fail closed: a Closure that throws keeps the page out rather than listing what it meant to hide.
            return (bool) Options::call($kirby, 'exclude.pages', $pages, [$page], true, 'the page counts as excluded');
        }
        $id = $page->id();
        foreach ($pages as $excluded) {
            $excluded = trim($excluded, '/');
            if ($id === $excluded || str_starts_with($id, $excluded . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is the page a draft, or below one? A page below a draft is as unpublished as
     * the draft (an anonymous request is 404, only an editor's preview renders it),
     * but Kirby's isDraft() is false for it: it got no robots meta and counted as
     * indexable. Every "draft" test of Annai goes through here.
     */
    public static function isDraft(Page $page): bool
    {
        if ($page->isDraft()) {
            return true;
        }
        foreach ($page->parents() as $parent) {
            if ($parent->isDraft()) {
                return true;
            }
        }
        return false;
    }

    /** $site->index() (no drafts) minus excluded(), in index order. [] when Annai is off. @return list<Page> */
    public static function all(App $kirby): array
    {
        if (!Options::active($kirby)) {
            return [];
        }
        $pages = [];
        foreach ($kirby->site()->index() as $page) {
            if (!self::excluded($kirby, $page)) {
                $pages[] = $page;
            }
        }
        return $pages;
    }

    /**
     * The preview: every page carries the noindex meta (robots()), and the
     * three files answer `Cache-Control: no-store` (Routes::headers()).
     *
     * `annai.noindex`: true or false as set; null asks Sudare through the
     * contract `Sudare\Sudare::active(): bool`. Any other value ('true', 1 from
     * an env-driven config) is reported and counts as null: a typo must not
     * switch the automatic noindex of a protected preview off.
     *
     * On the command line (the check tool, a cron job) no request ran Sudare's
     * guard, so active() is false even on a protected site; there the stateless
     * `Sudare\Sudare::protects($base): bool` answers for the site directory.
     *
     * While the preview is on, the response is kept out of Kirby's page cache:
     * a page rendered during the preview carried its "noindex, nofollow,
     * noarchive" in the cache file and was served with it after the preview had
     * ended, until someone flushed the cache (as Noren does for time-dependent
     * output).
     *
     * A preview is not necessarily closed: behind Sudare the requests that reach
     * robots.txt, sitemap.xml and llms.txt are authenticated already, so they
     * carry the normal content, unless the protection exempts the route
     * (hidden() with the route's path) or `annai.noindex => true` closes them.
     */
    public static function preview(App $kirby): bool
    {
        $preview = self::previewState($kirby);
        if ($preview) {
            $kirby->response()->cache(false);
        }
        return $preview;
    }

    /**
     * Are the files of this route closed? Closed (robots.txt `Disallow: /`
     * without a sitemap line, sitemap.xml an empty urlset, llms.txt its H1 only,
     * the chunk files 404; Routes) when
     *   - `annai.noindex` is `true`: the site must not be indexed, even where
     *     nothing protects it (an open page), or
     *   - a protected preview exempts this route for this request: Sudare lets
     *     the request through without credentials, so it is not behind the lock.
     * Without the path only the explicit setting counts (the check tool's
     * report). A protected preview alone never closes the files: Sudare answers
     * unauthenticated requests with 401 before Kirby renders.
     *
     * @param ?string $path the route's path, e.g. '/robots.txt' or '/sitemap-2.xml'
     */
    public static function hidden(App $kirby, ?string $path = null): bool
    {
        if (Options::get($kirby, 'noindex') === true) {
            return true;
        }
        return $path !== null && self::previewState($kirby) && self::exempt($kirby, $path);
    }

    /**
     * Does Sudare's configuration of this request exempt the path? Sudare 1.0.0:
     * `Sudare\Sudare::config()` is the configuration the guard remembered, null
     * when no guard ran; `isExempt($path, $method, $sent)` reads the rules of
     * that configuration. Without the class or the methods: false.
     */
    public static function exempt(App $kirby, string $path): bool
    {
        if (!class_exists('Sudare\\Sudare') || !method_exists('Sudare\\Sudare', 'config')) {
            return false;
        }
        $config = \Sudare\Sudare::config();
        if (!is_object($config) || !method_exists($config, 'isExempt')) {
            return false;
        }
        return $config->isExempt($path, $kirby->request()->method(), $_SERVER['REQUEST_METHOD'] ?? null) === true;
    }

    /** preview() without the cache side effect. */
    private static function previewState(App $kirby): bool
    {
        $setting = Options::get($kirby, 'noindex');
        if ($setting === true || $setting === false) {
            return $setting;
        }
        if ($setting !== null) {
            Options::warn($kirby, 'Annai: annai.noindex must be true, false or null, not ' . var_export($setting, true) . ' — using null (automatic through Sudare).');
        }
        if (!class_exists('Sudare\\Sudare')) {
            return false;
        }
        if (method_exists('Sudare\\Sudare', 'active') && \Sudare\Sudare::active() === true) {
            return true;
        }
        return PHP_SAPI === 'cli'
            && method_exists('Sudare\\Sudare', 'protects')
            && \Sudare\Sudare::protects($kirby->root('base') ?? dirname((string) $kirby->root('site'))) === true;
    }

    /**
     * The robots meta value: preview → 'noindex, nofollow, noarchive';
     * a draft or a page below one (a preview link) → 'noindex, nofollow',
     * whatever `annai.noindexExcluded` says; excluded and
     * `annai.noindexExcluded` → 'noindex, follow'; else null.
     */
    public static function robots(App $kirby, Page $page): ?string
    {
        if (self::preview($kirby)) {
            return 'noindex, nofollow, noarchive';
        }
        if (self::isDraft($page)) {
            return 'noindex, nofollow';
        }
        if (Options::flag($kirby, 'noindexExcluded', true) && self::excluded($kirby, $page)) {
            return 'noindex, follow';
        }
        return null;
    }

    /**
     * hreflang map code → URL, default language first, x-default last. [] unless
     * the site is multilang and `annai.hreflang` is true. Only languages in
     * which the page has content (hasContent()). x-default is the default
     * language's URL, or the first listed language's when the page has no
     * content in the default language. [] as well for a page whose robots()
     * says noindex (excluded, a draft, the preview): a head must not tell
     * engines not to index a page and in the next line offer them its language
     * versions. The sitemap entries read languageAlternates() instead: the
     * preview is no property of the page.
     *
     * @return array<string,string>
     */
    public static function alternates(App $kirby, Page $page): array
    {
        if (!self::hreflangOn($kirby) || self::robots($kirby, $page) !== null) {
            return [];
        }
        return self::languageAlternates($kirby, $page);
    }

    /** Multilang site with `annai.hreflang` true. */
    private static function hreflangOn(App $kirby): bool
    {
        return $kirby->multilang() && Options::flag($kirby, 'hreflang', false);
    }

    /**
     * alternates() without the robots() rule: the language versions the page
     * has, whatever the preview says. entries() builds the sitemap from it, so
     * the check tool and $site->annaiSitemap() count one entry per language
     * behind a preview too, as the file is served after the go-live. Built
     * from alternates(), which answers [] for every page while the preview is
     * on, the sitemap count behind Sudare used to be one per page. The route
     * serves no entries while hidden() (Routes::sitemap()); drafts and
     * excluded pages never reach entries() (all()).
     *
     * @return array<string,string>
     */
    private static function languageAlternates(App $kirby, Page $page): array
    {
        if (!self::hreflangOn($kirby)) {
            return [];
        }
        $default = $kirby->defaultLanguage();
        if ($default === null) {
            return [];
        }
        $languages = [$default];
        foreach ($kirby->languages() as $language) {
            if ($language->code() !== $default->code()) {
                $languages[] = $language;
            }
        }

        $map = [];
        foreach ($languages as $language) {
            if (self::hasContentIn($page, $language)) {
                $map[$language->code()] = $page->url($language->code());
            }
        }
        if ($map !== []) {
            // The default language comes first: its URL when the page has content in it, else the next language's.
            $map['x-default'] = reset($map);
        }
        return $map;
    }

    /**
     * The URL the canonical of a page points to when the page has no content
     * in the current language: Kirby renders `/en/kontakt` without
     * `kontakt/default.en.txt` with the default language's content, and the
     * head used to give that copy a canonical on itself, no noindex — duplicate
     * content in every language a page is not translated into.
     * The default language's URL when the page has content in it, else the
     * first listed language's with content (the x-default of
     * languageAlternates(), whatever `annai.hreflang` says). null on a
     * single-language site, when the page has content in the current
     * language, or when it has content in no language.
     *
     * Every output that names a page by its URL follows the same rule
     * (`contentUrl() ?? $page->url()`): the sitemap entry without hreflang
     * (entries()), the llms.txt links (Routes::llmsModel(), events()), which
     * used to list the current language's URL although its canonical points
     * elsewhere.
     */
    public static function contentUrl(App $kirby, Page $page): ?string
    {
        $code = self::contentLanguage($kirby, $page);
        return $code === null ? null : $page->url($code);
    }

    /**
     * The language code behind contentUrl(), null in the same cases. entries()
     * reads the lastmod of that language's content file for the sitemap entry.
     */
    public static function contentLanguage(App $kirby, Page $page): ?string
    {
        if (!$kirby->multilang()) {
            return null;
        }
        $current = $kirby->language();
        $default = $kirby->defaultLanguage();
        if ($current === null || $default === null || self::hasContentIn($page, $current)) {
            return null;
        }
        $languages = [$default];
        foreach ($kirby->languages() as $language) {
            if ($language->code() !== $default->code()) {
                $languages[] = $language;
            }
        }
        foreach ($languages as $language) {
            if (self::hasContentIn($page, $language)) {
                return $language->code();
            }
        }
        return null;
    }

    /**
     * The content of the language version contentUrl() links: $code from
     * contentLanguage(), null for the current language's. A text written next
     * to a page link (the llms.txt title and note) comes from the same version
     * as the URL (an earlier version linked `/en/english` with the slug of the
     * empty German version as its title and without its note).
     */
    public static function contentIn(Page $page, ?string $code): Content
    {
        return $code === null ? $page->content() : $page->content($code);
    }

    /** The title of contentIn(), with Kirby's fallback to the slug (of that language). */
    public static function titleIn(Page $page, ?string $code): string
    {
        if ($code === null) {
            return (string) $page->title()->value();
        }
        return (string) $page->content($code)->get('title')->or($page->slug($code))->value();
    }

    /**
     * Does the page have a content file in this language? Kirby 5 counts the
     * default language as existing as soon as the page folder exists
     * (PlainTextStorage::exists()), so a page with only `default.en.txt` got
     * hreflang="de" and an x-default to a German URL without German content.
     * For the default language the file itself is checked; a page without
     * plain-text storage (a virtual page) keeps Kirby's answer.
     *
     * Version::contentFile() is `@internal` in Kirby 5 (tested with 5.6): a
     * Kirby minor may close it or change its signature. Then Kirby's answer
     * stands (hreflang stays, as without the file check) instead of an
     * exception in the head of every page.
     *
     * Public because contentUrl() and the canonical rule read it too.
     */
    public static function hasContentIn(Page $page, Language $language): bool
    {
        if (!$page->translation($language->code())->exists()) {
            return false;
        }
        if (!$language->isDefault() || !$page->storage() instanceof PlainTextStorage) {
            return true;
        }
        try {
            return is_file($page->version('latest')->contentFile($language));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Sitemap lastmod (W3C datetime) from the INTEGER modified() (docs/SPEC.md §3.9).
     * $language: the content file of this language code (hreflang entries);
     * null is the current language. Koyomi rule: an event page whose
     * event is past gets the later of its modified time and the event's end.
     * null when `annai.sitemap.lastmod` is false.
     */
    public static function lastmod(App $kirby, Page $page, ?string $language = null): ?string
    {
        if (!Options::flag($kirby, 'sitemap.lastmod', true)) {
            return null;
        }
        $zone = Options::timezone($kirby);
        $modified = (int) $page->modified(null, null, $language);

        if (self::koyomiOn($kirby) && $page instanceof \Koyomi\Models\EventPage) {
            $event = $page->koyomi();
            if ($event !== null && $event->isPast(koyomi_now())) {
                $modified = max($modified, $event->end->getTimestamp());
            }
        }
        return Sitemap::lastmod($modified, $zone);
    }

    /**
     * Sitemap entries (docs/SPEC.md §3.9): one per page in all(), or one per language
     * with alternates when hreflang is on (languageAlternates(): the same list
     * during a preview, when the head offers none); then `annai.sitemap.extra`.
     *
     * @return list<array{loc: string, lastmod: ?string, alternates: array<string,string>, page: ?Page}>
     */
    public static function entries(App $kirby): array
    {
        if (!Options::active($kirby)) {
            return [];
        }
        $entries = [];
        foreach (self::all($kirby) as $page) {
            $alternates = self::languageAlternates($kirby, $page);
            if ($alternates === []) {
                // The canonical URL, not the current language's: a page without content
                // in it is listed under the language version that has the content, with
                // that file's modified time.
                $code = self::contentLanguage($kirby, $page);
                $entries[] = [
                    'loc'        => $code === null ? $page->url() : $page->url($code),
                    'lastmod'    => self::lastmod($kirby, $page, $code),
                    'alternates' => [],
                    'page'       => $page,
                ];
                continue;
            }
            foreach ($alternates as $code => $url) {
                if ($code === 'x-default') {
                    continue;
                }
                // Each language entry carries the modified time of its own content file.
                $entries[] = ['loc' => $url, 'lastmod' => self::lastmod($kirby, $page, $code), 'alternates' => $alternates, 'page' => $page];
            }
        }

        $extra = Options::get($kirby, 'sitemap.extra');
        if ($extra instanceof Closure) {
            $extra = Options::call($kirby, 'sitemap.extra', $extra, [], [], 'no extra entries');
        }
        if ($extra !== null && !is_array($extra)) {
            Options::warn($kirby, 'Annai: annai.sitemap.extra must be a list of entries, not ' . get_debug_type($extra) . ' — ignored.');
            $extra = [];
        }
        foreach ($extra ?? [] as $item) {
            $loc = is_array($item) ? ($item['loc'] ?? null) : null;
            if (!is_string($loc) || !Sitemap::validLoc($loc)) {
                Options::warn($kirby, 'Annai: annai.sitemap.extra entry without an absolute http(s) loc skipped.');
                continue;
            }
            $lastmod = $item['lastmod'] ?? null;
            if ($lastmod !== null && $lastmod !== '' && (!is_string($lastmod) || !Sitemap::validLastmod($lastmod))) {
                Options::warn($kirby, 'Annai: annai.sitemap.extra lastmod of ' . $loc . ' is not a W3C datetime (2027-01-01T00:00:00+00:00) — left out.');
                $lastmod = null;
            }
            $entries[] = [
                'loc'        => $loc,
                'lastmod'    => is_string($lastmod) && $lastmod !== '' ? $lastmod : null,
                'alternates' => [],
                'page'       => null,
            ];
        }
        return $entries;
    }

    /**
     * Listed top-level pages minus excluded() — the "Pages" list of llms.txt.
     * The home page is never in it: Routes::llmsModel() writes it first under
     * its own label, and a listed home folder (content/0_home) made it appear
     * a second time under its title.
     * @return list<Page>
     */
    public static function menu(App $kirby): array
    {
        if (!Options::active($kirby)) {
            return [];
        }
        $pages = [];
        foreach ($kirby->site()->children()->listed() as $page) {
            if (!$page->isHomePage() && !self::excluded($kirby, $page)) {
                $pages[] = $page;
            }
        }
        return $pages;
    }

    /**
     * Pages (any status but draft) whose template is in `annai.llms.optional`,
     * minus excluded(), minus menu().
     * @return list<Page>
     */
    public static function optional(App $kirby): array
    {
        if (!Options::active($kirby)) {
            return [];
        }
        $templates = Options::llmsOptional($kirby);
        if ($templates === []) {
            return [];
        }
        $menuIds = array_map(fn (Page $page): string => $page->id(), self::menu($kirby));
        $pages = [];
        foreach ($kirby->site()->index() as $page) {
            if (!in_array($page->intendedTemplate()->name(), $templates, true)) {
                continue;
            }
            if (self::excluded($kirby, $page) || in_array($page->id(), $menuIds, true)) {
                continue;
            }
            $pages[] = $page;
        }
        return $pages;
    }

    /**
     * Upcoming Koyomi events for llms.txt, [] unless Koyomi is installed, active
     * and `annai.koyomi` is true. Events with an empty url and events whose page
     * is excluded() are skipped.
     *
     * @return list<array{title: string, url: string, when: string, venue: ?string, cancelled: bool, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public static function events(App $kirby): array
    {
        if (!Options::active($kirby) || !self::koyomiOn($kirby)) {
            return [];
        }
        $calendar = \Koyomi\Repository::calendar($kirby);
        if ($calendar === null) {
            return [];
        }
        $upcoming = \Koyomi\Repository::upcoming($calendar);
        koyomi_i18n();

        // An event page kept out by `annai.exclude.*` stays out of llms.txt too,
        // like every other page. Koyomi's events carry the page URL, not the page.
        // Koyomi's url is the current language's; the link follows the canonical
        // rule like every other page link (contentUrl()), and the title comes
        // from the same language version as the link: titleIn(), so an empty
        // title becomes the slug of that version, as for every page link — in
        // the current language too (an earlier version kept Koyomi's '' in both
        // cases, and llms.txt wrote "- [](…)"). Kirby 5 fills a language
        // without a content file from the default language's fields, so Koyomi's
        // title is that version's already; this keeps it so when Kirby stops
        // doing that (Version::content(), marked @todo there).
        $hidden = [];
        $canonical = [];
        $titles = [];
        foreach (\Koyomi\Repository::eventPages($calendar) as $page) {
            if (self::excluded($kirby, $page)) {
                $hidden[$page->url()] = true;
            }
            $code = self::contentLanguage($kirby, $page);
            if ($code !== null) {
                $canonical[$page->url()] = $page->url($code);
            }
            $titles[$page->url()] = self::titleIn($page, $code);
        }

        $events = [];
        foreach ($upcoming as $event) {
            if ($event->url === '' || isset($hidden[$event->url])) {
                continue;
            }
            $venue = $event->physicalVenue()?->label() ?? '';
            $events[] = [
                'title'     => $titles[$event->url] ?? $event->title,
                'url'       => $canonical[$event->url] ?? $event->url,
                'when'      => \Koyomi\Format::when($event),
                'venue'     => $venue !== '' ? $venue : null,
                'cancelled' => $event->isCancelled(),
                'start'     => $event->start,
                'end'       => $event->end,
            ];
        }
        return $events;
    }

    /** Koyomi installed, active and `annai.koyomi` true (docs/SPEC.md §7). */
    private static function koyomiOn(App $kirby): bool
    {
        return function_exists('koyomi_active')
            && koyomi_active()
            && Options::flag($kirby, 'koyomi', true);
    }
}
