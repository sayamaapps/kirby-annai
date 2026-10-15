<?php

namespace Annai;

use Closure;
use Kirby\Cms\App;

/**
 * Reads `annai.*` options. Shared file — owned by the architect (SPEC.md §3.1, §4).
 *
 * `null` counts as "not set", like Kirby's own option(), so `'annai.type' => null`
 * falls back to the default. Both spellings work, flat (`'annai.robots.ai' => …`)
 * and nested (`'annai' => ['robots' => ['ai' => …]]`), because Kirby's option()
 * resolves dotted keys in both forms.
 *
 * A broken value is logged once per process and the default is used, except
 * with Kirby's `debug` option on: then it throws, so a developer sees it on
 * the page instead of in the log.
 *
 * Defaults are given here and at the call sites, never in an `options` block
 * of the plugin (Kirby would file them under sayamaapps.annai.*).
 */
final class Options
{
    public const PREFIX = 'annai.';

    /** Content field names (SPEC.md §6). Each key can be overridden in `annai.fields`. */
    public const FIELDS = [
        'brand'       => 'marke',
        'tagline'     => 'zusatz',
        'description' => 'beschreibung',
        'intro'       => 'einleitung',
        'street'      => 'strasse',
        'postalCode'  => 'plz',
        'city'        => 'ort',
        'phone'       => 'telefon',
        'fax'         => 'fax',
        'email'       => 'email',
        'profiles'    => ['instagram', 'facebook'],
    ];

    public const DEFAULT_TIMEZONE = 'Europe/Berlin';

    /** @var array<string,bool> messages already logged in this process */
    private static array $logged = [];

    public static function get(App $kirby, string $key, mixed $default = null): mixed
    {
        return $kirby->option(self::PREFIX . $key) ?? $default;
    }

    /**
     * Master switch: only a real `true` switches Annai on. Read through flag(),
     * so `'true'` from an env-driven config is logged like every other boolean
     * option (with `debug` on it throws, as the README says) instead of
     * switching Annai off in silence.
     */
    public static function active(App $kirby): bool
    {
        return self::flag($kirby, 'active', false);
    }

    /**
     * A boolean option. Only a real true or false counts: the string 'false'
     * (typical for env-driven config) is not a switch, so it is reported and
     * the default is used.
     */
    public static function flag(App $kirby, string $key, bool $default): bool
    {
        $value = self::get($kirby, $key, $default);
        if (is_bool($value)) {
            return $value;
        }
        self::warn($kirby, 'Annai: annai.' . $key . ' must be true or false — using ' . var_export($default, true) . '.');
        return $default;
    }

    /**
     * A broken option or an unusable value: with Kirby's `debug` option on it
     * throws, so the developer sees it; otherwise it is logged once per
     * process and the caller uses the default.
     */
    public static function warn(App $kirby, string $message): void
    {
        if ($kirby->option('debug') === true) {
            throw new \InvalidArgumentException($message);
        }
        if (isset(self::$logged[$message])) {
            return;
        }
        self::$logged[$message] = true;
        error_log($message);
    }

    /**
     * The messages warn() has logged in this process, in order. Check::site()
     * reads them before and after its run and reports the new ones as site
     * problems, so a broken option shows in the report instead of only in the log.
     *
     * @return list<string>
     */
    public static function logged(): array
    {
        return array_keys(self::$logged);
    }

    /**
     * Calls a hook (a Closure option) with $arguments. A hook that throws must
     * not take the page, the sitemap or llms.txt down: the error goes through
     * warn() (so with Kirby's `debug` on it is thrown, as an
     * InvalidArgumentException naming the hook) and $fallback is returned. The
     * caller checks the type of a result it got.
     */
    public static function call(App $kirby, string $key, Closure $hook, array $arguments, mixed $fallback, string $instead = 'using the default'): mixed
    {
        try {
            return $hook(...$arguments);
        } catch (\Throwable $error) {
            self::warn($kirby, 'Annai: annai.' . $key . ' threw ' . get_class($error) . ': ' . $error->getMessage() . ' — ' . $instead . '.');
            return $fallback;
        }
    }

    /**
     * Field names, `annai.fields` merged over the defaults. Unknown keys are
     * ignored; empty or wrongly typed values keep the default. `profiles` is a
     * list of field names (a string is wrapped into a list).
     *
     * @return array{brand:string, tagline:string, description:string, intro:string, street:string,
     *   postalCode:string, city:string, phone:string, fax:string, email:string, profiles:list<string>}
     */
    public static function fields(App $kirby): array
    {
        $fields = self::FIELDS;
        $option = self::get($kirby, 'fields');
        if (!is_array($option)) {
            return $fields;
        }
        foreach ($fields as $key => $default) {
            if (!array_key_exists($key, $option)) {
                continue;
            }
            $value = $option[$key];
            if ($key === 'profiles') {
                $list = is_string($value) ? [$value] : (is_array($value) ? $value : null);
                if ($list !== null) {
                    $fields[$key] = self::stringList($list);
                }
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $fields[$key] = trim($value);
            }
        }
        return $fields;
    }

    /**
     * A hook option (`annai.title`, `annai.canonical`, `annai.jsonld`, …): the
     * Closure when one is set, null when the option is unset. A set value that
     * is no Closure (`'annai.title' => 'Eigener Titel'`, an array of nodes as
     * `annai.jsonld`) is reported once per process and counts as unset; it
     * used to be ignored in silence, although the README promised a log line
     * for a broken value.
     */
    public static function hook(App $kirby, string $key): ?Closure
    {
        $value = self::get($kirby, $key);
        if ($value === null) {
            return null;
        }
        if ($value instanceof Closure) {
            return $value;
        }
        self::warn($kirby, 'Annai: annai.' . $key . ' must be a Closure, not '
            . (is_scalar($value) ? var_export($value, true) : get_debug_type($value)) . ' — ignored.');
        return null;
    }

    /**
     * `annai.location`: a page id string or a Closure returning ?Page; null when
     * unset. Any other value (an integer, an array, a Page object) is reported
     * once and counts as unset (it used to be ignored in silence).
     */
    public static function location(App $kirby): string|Closure|null
    {
        $value = self::get($kirby, 'location');
        if ($value === null) {
            return null;
        }
        if ($value instanceof Closure) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        self::warn($kirby, 'Annai: annai.location must be a page id or a Closure, not '
            . (is_scalar($value) ? var_export($value, true) : get_debug_type($value)) . ' — ignored.');
        return null;
    }

    /**
     * Templates whose first page is the business page when `annai.location` is
     * unset and Noren is not active: `annai.locationTemplate`, string or list.
     *
     * @return list<string>
     */
    public static function locationTemplates(App $kirby): array
    {
        $option = self::get($kirby, 'locationTemplate', 'betrieb');
        $list = self::stringList(is_array($option) ? $option : [$option]);
        return $list !== [] ? $list : ['betrieb'];
    }

    /** `annai.type`: schema.org type (string), or false for no business node. Default 'LocalBusiness'. */
    public static function type(App $kirby): string|false
    {
        $value = self::get($kirby, 'type', 'LocalBusiness');
        if ($value === false) {
            return false;
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        self::warn($kirby, 'Annai: annai.type must be a schema.org type name or false — using LocalBusiness.');
        return 'LocalBusiness';
    }

    /** Fragment of the business node's @id (`url/#business`). */
    public static function entityId(App $kirby): string
    {
        $value = self::get($kirby, 'entityId', 'business');
        return is_string($value) && trim($value) !== '' ? trim($value) : 'business';
    }

    /** Noren area key of the main hours, or null. */
    public static function area(App $kirby): ?string
    {
        $value = self::get($kirby, 'area');
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * `annai.sameAs`: list of URLs or a Closure returning one. '' and non-strings
     * are dropped, duplicates removed, order kept.
     *
     * @return list<string>
     */
    public static function sameAs(App $kirby): array
    {
        $value = self::get($kirby, 'sameAs', []);
        if ($value instanceof Closure) {
            $value = self::call($kirby, 'sameAs', $value, [], []);
        }
        if (is_string($value)) {
            $value = [$value];
        }
        return is_array($value) ? array_values(array_unique(self::stringList($value))) : [];
    }

    /**
     * `annai.geo` → ['lat' => float, 'lng' => float] or null. Accepts
     * ['lat' => …, 'lng' => …], the legacy ['breite' => …, 'laenge' => …] and
     * the list form [lat, lng]. Anything else is warned and gives null.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function geo(App $kirby): ?array
    {
        $value = self::get($kirby, 'geo');
        if ($value === null || $value === false) {
            return null;
        }
        $lat = $lng = null;
        if (is_array($value)) {
            $lat = $value['lat'] ?? $value['latitude'] ?? $value['breite'] ?? $value[0] ?? null;
            $lng = $value['lng'] ?? $value['longitude'] ?? $value['laenge'] ?? $value[1] ?? null;
        }
        if (is_numeric($lat) && is_numeric($lng)) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }
        self::warn($kirby, "Annai: annai.geo must be ['lat' => …, 'lng' => …] — ignored.");
        return null;
    }

    /**
     * `annai.action` → ['type' => string, 'name' => string, 'target' => string] or null.
     * Legacy keys `typ` and `ziel` are accepted. Without a non-empty target → null.
     * Type defaults to 'Action', name to ''.
     *
     * @return array{type: string, name: string, target: string}|null
     */
    public static function action(App $kirby): ?array
    {
        $value = self::get($kirby, 'action');
        if (!is_array($value)) {
            return null;
        }
        $target = $value['target'] ?? $value['ziel'] ?? null;
        if (!is_string($target) || trim($target) === '') {
            return null;
        }
        $type = $value['type'] ?? $value['typ'] ?? 'Action';
        $name = $value['name'] ?? '';
        return [
            'type'   => is_string($type) && trim($type) !== '' ? trim($type) : 'Action',
            'name'   => is_string($name) ? trim($name) : '',
            'target' => trim($target),
        ];
    }

    /** `addressCountry`, default 'DE'. */
    public static function country(App $kirby): string
    {
        $value = self::get($kirby, 'country', 'DE');
        return is_string($value) && trim($value) !== '' ? trim($value) : 'DE';
    }

    /**
     * Country calling code (digits only, no leading zeros) for national phone
     * numbers, default '49'. "+43", "0043" and " 43 " all mean 43: the international
     * prefix is no part of the code (`'0043'` used to make "+00431234").
     */
    public static function phoneCountry(App $kirby): string
    {
        $value = self::get($kirby, 'phoneCountry', '49');
        $digits = ltrim((string) preg_replace('/\D+/', '', (string) $value), '0');
        return $digits !== '' ? $digits : '49';
    }

    public const DESCRIPTION_LENGTH = 155;
    public const DESCRIPTION_LENGTH_MIN = 20;
    public const DESCRIPTION_LENGTH_MAX = 320;

    /**
     * Excerpt length of the meta description: a whole number from 20 to 320,
     * default 155. Anything else ('abc', '200' from an env-driven config) used
     * to become (int) 0 → 20 without a word: it is reported and the default is
     * used; a number outside the range is reported and clamped.
     */
    public static function descriptionLength(App $kirby): int
    {
        $value = self::get($kirby, 'descriptionLength', self::DESCRIPTION_LENGTH);
        if (!is_int($value)) {
            self::warn($kirby, 'Annai: annai.descriptionLength must be a whole number from ' . self::DESCRIPTION_LENGTH_MIN . ' to ' . self::DESCRIPTION_LENGTH_MAX . ', not '
                . (is_scalar($value) ? var_export($value, true) : get_debug_type($value)) . ' — using ' . self::DESCRIPTION_LENGTH . '.');
            return self::DESCRIPTION_LENGTH;
        }
        if ($value < self::DESCRIPTION_LENGTH_MIN || $value > self::DESCRIPTION_LENGTH_MAX) {
            $clamped = max(self::DESCRIPTION_LENGTH_MIN, min(self::DESCRIPTION_LENGTH_MAX, $value));
            self::warn($kirby, 'Annai: annai.descriptionLength ' . $value . ' is outside ' . self::DESCRIPTION_LENGTH_MIN . '…' . self::DESCRIPTION_LENGTH_MAX . ' — using ' . $clamped . '.');
            return $clamped;
        }
        return $value;
    }

    /**
     * `annai.descriptionFallback`: when the page's intro is shorter than the
     * check's minimum (Check::DESCRIPTION_MIN), the meta description is taken from
     * the site description instead, if that is longer. Off by default.
     */
    public static function descriptionFallback(App $kirby): bool
    {
        return self::flag($kirby, 'descriptionFallback', false);
    }

    /**
     * `og:locale` in `xx_YY` form: the option, else the current language's
     * locale on a multilang site (LC_ALL or the first entry), else Kirby's
     * `locale` option ("de_DE.utf8" → "de_DE"), else null.
     */
    public static function locale(App $kirby): ?string
    {
        $candidates = [self::get($kirby, 'locale')];
        try {
            $language = $kirby->language();
            if ($language !== null) {
                $locale = $language->locale();
                if (is_array($locale)) {
                    $locale = $locale[LC_ALL] ?? reset($locale);
                }
                $candidates[] = $locale;
            }
        } catch (\Throwable) {
            // No languages configured.
        }
        $locale = $kirby->option('locale');
        if (is_array($locale)) {
            $locale = $locale[LC_ALL] ?? reset($locale);
        }
        $candidates[] = $locale;

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if (preg_match('/^([a-z]{2,3})[_-]([A-Za-z]{2})(?:[.@].*)?$/', trim($candidate), $m) === 1) {
                return strtolower($m[1]) . '_' . strtoupper($m[2]);
            }
        }
        return null;
    }

    /** `annai.twitterCard`: 'summary' or 'summary_large_image', else null. */
    public static function twitterCard(App $kirby): ?string
    {
        $value = self::get($kirby, 'twitterCard');
        return in_array($value, ['summary', 'summary_large_image'], true) ? $value : null;
    }

    /** Templates excluded from sitemap, llms.txt and the check. Default ['error']. @return list<string> */
    public static function excludeTemplates(App $kirby): array
    {
        $option = self::get($kirby, 'exclude.templates', ['error']);
        return self::stringList(is_array($option) ? $option : [$option]);
    }

    /** `annai.exclude.pages`: a list of page ids, or a Closure(Page): bool. @return list<string>|Closure */
    public static function excludePages(App $kirby): array|Closure
    {
        $option = self::get($kirby, 'exclude.pages', []);
        if ($option instanceof Closure) {
            return $option;
        }
        return self::stringList(is_array($option) ? $option : [$option]);
    }

    /**
     * `Disallow:` paths of the `*` group. Default ['/panel']. An entry that is no
     * path for RFC 9309 (Robots::validPath(): starts with "/", no space, no "#",
     * no control character, UTF-8) is reported here; Robots::render() leaves it
     * out and Robots::warnings() names it, so the check shows it too. The text
     * names the UTF-8 rule as well: for "/b\xE4ckerei" none of the other
     * reasons was true.
     *
     * @return list<string>
     */
    public static function disallow(App $kirby): array
    {
        $option = self::get($kirby, 'disallow', ['/panel']);
        $paths = self::stringList(is_array($option) ? $option : [$option]);
        foreach ($paths as $path) {
            if (!Robots::validPath($path)) {
                self::warn($kirby, 'Annai: annai.disallow entry ' . Text::shown($path)
                    . ' is not a path (starts with /, no space, # or control character, and is UTF-8) — left out of robots.txt.');
            }
        }
        return $paths;
    }

    /** `annai.robots.ai`: null, 'blockTraining' or 'allowAll'; anything else is warned and null. */
    public static function robotsAi(App $kirby): ?string
    {
        $value = self::get($kirby, 'robots.ai');
        if ($value === null || $value === false) {
            return null;
        }
        if (in_array($value, ['blockTraining', 'allowAll'], true)) {
            return $value;
        }
        self::warn($kirby, "Annai: annai.robots.ai must be null, 'blockTraining' or 'allowAll' — ignored.");
        return null;
    }

    /**
     * `annai.robots.agents`: agent name → bool. Non-bool values are dropped. A
     * name is a product token (RFC 9309 §2.2.1: letters, digits, "_", "-", and
     * "." as in the names of Robots::AI_AGENTS); anything else is reported and
     * dropped: "x\nDisallow: /" would write a line of its own into robots.txt.
     *
     * @return array<string,bool>
     */
    public static function robotsAgents(App $kirby): array
    {
        $option = self::get($kirby, 'robots.agents', []);
        $agents = [];
        if (is_array($option)) {
            foreach ($option as $name => $allowed) {
                if (is_string($name) && trim($name) === '*') {
                    // A second "User-agent: *" group merges with the first (RFC 9309), and Allow wins.
                    self::warn($kirby, "Annai: annai.robots.agents cannot hold '*' — use annai.disallow for rules for everyone. Ignored.");
                    continue;
                }
                if (!is_string($name) || trim($name) === '' || !is_bool($allowed)) {
                    continue;
                }
                if (preg_match('/^[A-Za-z0-9_.-]+$/D', trim($name)) !== 1) {
                    self::warn($kirby, 'Annai: annai.robots.agents name ' . Text::shown($name)
                        . ' is not a user agent token (letters, digits, "_", "-", ".") — ignored.');
                    continue;
                }
                $agents[trim($name)] = $allowed;
            }
        }
        return $agents;
    }

    /**
     * Raw lines appended to robots.txt, one list entry per line: an element with
     * line feeds inside ("# reason\nUser-agent: Googlebot\nDisallow: /") is split
     * first, so Robots::render() sees its User-agent line and makes a group of it
     * (as one "line" it landed inside the * group and its rules were repeated for
     * every allowed agent). A byte that is not UTF-8 becomes U+FFFD
     * (Text::utf8()): robots.txt must be UTF-8 (RFC 9309 §2.2), and the raw
     * line used to go into the file as written. The split is at
     * CR, LF and CRLF only (RFC 9309's EOL), never with `\R` in byte mode:
     * there `\R` is also NEL = 0x85, the second byte of "Å" and of many CJK
     * characters, and "Disallow: /Åland/" came out as two broken lines.
     *
     * @return list<string>
     */
    public static function robotsExtra(App $kirby): array
    {
        $option = self::get($kirby, 'robots.extra', []);
        $lines = [];
        foreach (is_array($option) ? $option : [$option] as $element) {
            if (!is_string($element)) {
                continue;
            }
            foreach (preg_split(Text::EOL, Text::utf8($element)) ?: [] as $line) {
                $lines[] = rtrim($line);
            }
        }
        return $lines;
    }

    /**
     * URLs per sitemap file: a whole number from 1, default and at most
     * Sitemap::MAX_URLS (50000, the sitemaps.org limit per file). Anything else
     * (false, 'abc', 0, '100') used to become 1 URL per file: it is reported and
     * the default is used. A larger number is reported and capped.
     */
    public static function sitemapChunk(App $kirby): int
    {
        $value = self::get($kirby, 'sitemap.chunk', Sitemap::MAX_URLS);
        if (!is_int($value) || $value < 1) {
            self::warn($kirby, 'Annai: annai.sitemap.chunk must be a whole number from 1 to ' . Sitemap::MAX_URLS . ', not '
                . (is_scalar($value) ? var_export($value, true) : get_debug_type($value)) . ' — using ' . Sitemap::MAX_URLS . '.');
            return Sitemap::MAX_URLS;
        }
        if ($value > Sitemap::MAX_URLS) {
            self::warn($kirby, 'Annai: annai.sitemap.chunk ' . $value . ' is above the sitemaps.org limit of ' . Sitemap::MAX_URLS . ' URLs per file — using ' . Sitemap::MAX_URLS . '.');
            return Sitemap::MAX_URLS;
        }
        return $value;
    }

    /** Templates listed under "Optional" in llms.txt. Default []. @return list<string> */
    public static function llmsOptional(App $kirby): array
    {
        $option = self::get($kirby, 'llms.optional', []);
        return self::stringList(is_array($option) ? $option : [$option]);
    }

    /** `annai.timezone` for <lastmod> offsets, default Europe/Berlin; invalid → warned, default. */
    public static function timezone(App $kirby): string
    {
        $zone = self::get($kirby, 'timezone', self::DEFAULT_TIMEZONE);
        if (is_string($zone)) {
            try {
                new \DateTimeZone($zone);
                return $zone;
            } catch (\Exception) {
                // Reported below.
            }
        }
        self::warn($kirby, 'Annai: unknown annai.timezone ' . var_export($zone, true) . ' — using ' . self::DEFAULT_TIMEZONE . '.');
        return self::DEFAULT_TIMEZONE;
    }

    /**
     * Language of the plugin's text: `annai.language`, otherwise the current
     * language of a multi-language site, otherwise its default language,
     * otherwise the first two letters of Kirby's `locale` option, otherwise
     * English. Only languages Annai ships (I18n::LANGUAGES) are returned.
     */
    public static function language(App $kirby): string
    {
        $candidates = [self::get($kirby, 'language')];
        try {
            $candidates[] = $kirby->language()?->code();
            $candidates[] = $kirby->defaultLanguage()?->code();
        } catch (\Throwable) {
            // No languages configured.
        }
        $locale = $kirby->option('locale');
        if (is_array($locale)) {
            $locale = $locale[LC_ALL] ?? reset($locale);
        }
        $candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
                return strtolower($candidate);
            }
        }
        return 'en';
    }

    /** Sets I18n's language for this request from language(). Called before any text is produced. */
    public static function applyLanguage(App $kirby): void
    {
        I18n::setLanguage(self::language($kirby));
    }

    /** Non-empty trimmed strings of a list, re-indexed. @return list<string> */
    private static function stringList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }
        return $list;
    }
}
