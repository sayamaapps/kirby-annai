<?php

namespace Sayamaapps\Annai;

/**
 * sitemaps.org XML (docs/SPEC.md §3.4).
 */
final class Sitemap
{
	private const DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>';
	public const MAX_BYTES = 52428800;   // sitemaps.org limit per file, uncompressed
	public const MAX_URLS = 50000;   // sitemaps.org limit per file
	public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	public const NS_XHTML = 'http://www.w3.org/1999/xhtml';

	/**
	 * Splits into lists of at most $size entries whose render() is at most
	 * $bytes long; [] → [[]] (one empty file). An entry longer than $bytes on
	 * its own still gets a file: nothing is dropped.
	 */
	public static function chunks(array $entries, int $size = self::MAX_URLS, int $bytes = self::MAX_BYTES): array
	{
		if ($entries === []) {
			return [[]];
		}
		$size = max(1, $size);
		// The frame as render() writes it, with the xhtml namespace: never short.
		$frame = strlen(self::DECLARATION) + strlen(self::open(true)) + strlen('</urlset>') + 3;
		$parts = [];
		$part = [];
		$used = $frame;
		foreach ($entries as $entry) {
			$length = strlen(implode("\n", self::url($entry))) + 1;
			if ($part !== [] && (count($part) >= $size || $used + $length > $bytes)) {
				$parts[] = $part;
				$part = [];
				$used = $frame;
			}
			$part[] = $entry;
			$used += $length;
		}
		$parts[] = $part;
		return $parts;
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
			$lines[] = '    <loc>' . Text::xml((string)$sitemap['loc']) . '</loc>';
			$lastmod = (string)($sitemap['lastmod'] ?? '');
			if ($lastmod !== '') {
				$lines[] = '    <lastmod>' . Text::xml($lastmod) . '</lastmod>';
			}
			$lines[] = '  </sitemap>';
		}
		$lines[] = '</sitemapindex>';
		return implode("\n", $lines) . "\n";
	}

	/**
	 * W3C datetime (DATE_ATOM) from a unix timestamp or a DateTimeInterface, in $zone.
	 * null or 0 → null. Formats the value itself and never uses Kirby's date
	 * handler (with the `intl` handler, "Y-m-d" is an ICU pattern).
	 */
	public static function lastmod(int|\DateTimeInterface|null $time, string $zone = 'UTC'): string|null
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

	/** The <urlset> start tag; the xhtml namespace only when an entry has alternates. */
	private static function open(bool $alternates): string
	{
		return '<urlset xmlns="' . self::NS . '"' . ($alternates ? ' xmlns:xhtml="' . self::NS_XHTML . '"' : '') . '>';
	}

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
		$lines = [self::DECLARATION, self::open($hasAlternates)];
		foreach ($entries as $entry) {
			array_push($lines, ...self::url($entry));
		}
		$lines[] = '</urlset>';
		return implode("\n", $lines) . "\n";
	}

	/**
	 * The lines of one <url> element.
	 *
	 * @return list<string>
	 */
	private static function url(array $entry): array
	{
		$lines = ['  <url>', '    <loc>' . Text::xml((string)$entry['loc']) . '</loc>'];
		$lastmod = (string)($entry['lastmod'] ?? '');
		if ($lastmod !== '') {
			$lines[] = '    <lastmod>' . Text::xml($lastmod) . '</lastmod>';
		}
		foreach ((array)($entry['alternates'] ?? []) as $code => $href) {
			$lines[] = '    <xhtml:link rel="alternate" hreflang="' . Text::xml((string)$code)
				. '" href="' . Text::xml((string)$href) . '"/>';
		}
		$lines[] = '  </url>';
		return $lines;
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
		$month = isset($m[2]) ? (int)$m[2] : 1;
		$day = isset($m[3]) ? (int)$m[3] : 1;
		return checkdate($month, $day, (int)$m[1]);
	}

	/**
	 * An absolute http(s) URL a sitemap may carry: valid UTF-8, a host, no
	 * whitespace and no control characters.
	 */
	public static function validLoc(string $loc): bool
	{
		return mb_check_encoding($loc, 'UTF-8') &&
			preg_match('/[\x00-\x20\x7F]/', $loc) !== 1 &&
			preg_match('#^https?://[^/?\#\s]+(?:[/?\#]\S*)?$#iu', $loc) === 1;
	}
}
