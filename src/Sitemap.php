<?php

namespace Annai;

/**
 * sitemaps.org XML (SPEC.md §3.4). OWNER: task `formats`.
 */
final class Sitemap
{
    public const MAX_URLS = 50000;   // sitemaps.org limit per file
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const NS_XHTML = 'http://www.w3.org/1999/xhtml';

    private const DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>';

    /**
     * One <urlset>. $entries: list<array{loc: string, lastmod?: ?string, alternates?: array<string,string>}>.
     * An empty list gives a valid empty <urlset>.
     */
    public static function render(array $entries): string
    {
        $hasAlternates = false;
        foreach ($entries as $entry) {
            if (!empty($entry['alternates'])) {
                $hasAlternates = true;
                break;
            }
        }
        $lines = [
            self::DECLARATION,
            '<urlset xmlns="' . self::NS . '"' . ($hasAlternates ? ' xmlns:xhtml="' . self::NS_XHTML . '"' : '') . '>',
        ];
        foreach ($entries as $entry) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . Text::xml((string) $entry['loc']) . '</loc>';
            $lastmod = (string) ($entry['lastmod'] ?? '');
            if ($lastmod !== '') {
                $lines[] = '    <lastmod>' . Text::xml($lastmod) . '</lastmod>';
            }
            foreach ((array) ($entry['alternates'] ?? []) as $code => $href) {
                $lines[] = '    <xhtml:link rel="alternate" hreflang="' . Text::xml((string) $code)
                    . '" href="' . Text::xml((string) $href) . '"/>';
            }
            $lines[] = '  </url>';
        }
        $lines[] = '</urlset>';
        return implode("\n", $lines) . "\n";
    }

    /** Splits into lists of at most $size entries; [] → [[]] (one empty file). */
    public static function chunks(array $entries, int $size = self::MAX_URLS): array
    {
        if ($entries === []) {
            return [[]];
        }
        return array_chunk($entries, max(1, $size));
    }

    /**
     * <sitemapindex> for several files. $sitemaps: list<array{loc: string, lastmod?: ?string}>.
     */
    public static function index(array $sitemaps): string
    {
        $lines = [
            self::DECLARATION,
            '<sitemapindex xmlns="' . self::NS . '">',
        ];
        foreach ($sitemaps as $sitemap) {
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>' . Text::xml((string) $sitemap['loc']) . '</loc>';
            $lastmod = (string) ($sitemap['lastmod'] ?? '');
            if ($lastmod !== '') {
                $lines[] = '    <lastmod>' . Text::xml($lastmod) . '</lastmod>';
            }
            $lines[] = '  </sitemap>';
        }
        $lines[] = '</sitemapindex>';
        return implode("\n", $lines) . "\n";
    }

    /**
     * An absolute http(s) URL a sitemap may carry: valid UTF-8, a host, no
     * whitespace and no control characters.
     */
    public static function validLoc(string $loc): bool
    {
        return mb_check_encoding($loc, 'UTF-8')
            && preg_match('/[\x00-\x20\x7F]/', $loc) !== 1
            && preg_match('#^https?://[^/?\#\s]+(?:[/?\#]\S*)?$#iu', $loc) === 1;
    }

    /**
     * A W3C datetime as sitemaps.org takes it: YYYY, YYYY-MM, YYYY-MM-DD, or a
     * date with time (hh:mm, hh:mm:ss, fractions) and zone (Z or ±hh:mm).
     * "gestern", "2027-13-01" or "2027-01-01\n" is not ($ with /D: no
     * trailing newline, which would end up inside <lastmod>).
     */
    public static function validLastmod(string $lastmod): bool
    {
        if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?:T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d))?)?)?$/D', $lastmod, $m) !== 1) {
            return false;
        }
        $month = isset($m[2]) ? (int) $m[2] : 1;
        $day = isset($m[3]) ? (int) $m[3] : 1;
        return checkdate($month, $day, (int) $m[1]);
    }

    /**
     * W3C datetime (DATE_ATOM) from a unix timestamp or a DateTimeInterface, in $zone.
     * null or 0 → null. Formats the value itself and never uses Kirby's date
     * handler (with the `intl` handler, "Y-m-d" is an ICU pattern).
     */
    public static function lastmod(int|\DateTimeInterface|null $time, string $zone = 'UTC'): ?string
    {
        if ($time === null || $time === 0) {
            return null;
        }
        $zone = new \DateTimeZone($zone);
        if (is_int($time)) {
            $date = (new \DateTimeImmutable('@' . $time))->setTimezone($zone);
        } else {
            $date = \DateTimeImmutable::createFromInterface($time)->setTimezone($zone);
        }
        return $date->format(DATE_ATOM);
    }
}
