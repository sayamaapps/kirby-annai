<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Sitemap;

/**
 * sitemap.xml and sitemap index (docs/SPEC.md §3.4, §9.3): exact XML, escaping, hreflang
 * alternates, chunking and W3C lastmod values.
 *   vendor/bin/phpunit tests/SitemapTest.php
 */
#[CoversNothing]
final class SitemapTest extends TestCase
{
	public function testSuite(): void
	{
		$decl = '<?xml version="1.0" encoding="UTF-8"?>';
		$ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';
		// Empty and single entries.
		expect(
			"$decl\n<urlset xmlns=\"$ns\">\n</urlset>\n",
			Sitemap::render([]),
			'empty list gives a valid empty urlset'
		);
		expect(
			"$decl\n<urlset xmlns=\"$ns\">\n  <url>\n    <loc>https://example.test/</loc>\n    <lastmod>2026-10-11T01:14:00+02:00</lastmod>\n  </url>\n</urlset>\n",
			Sitemap::render([['loc' => 'https://example.test/', 'lastmod' => '2026-10-11T01:14:00+02:00']]),
			'one entry with lastmod matches the SPEC §3.4 shape'
		);
		expect(
			"$decl\n<urlset xmlns=\"$ns\">\n  <url>\n    <loc>https://example.test/laden</loc>\n  </url>\n</urlset>\n",
			Sitemap::render([['loc' => 'https://example.test/laden', 'lastmod' => null]]),
			'null lastmod is omitted'
		);
		// Escaping of loc.
		$escaped = Sitemap::render([['loc' => "https://example.test/?a=1&b=it's"]]);
		holds(str_contains($escaped, '<loc>https://example.test/?a=1&amp;b=it&apos;s</loc>'), 'loc escapes & and apostrophe');
		// Alternates.
		$withAlt = Sitemap::render([
			['loc' => 'https://example.test/', 'alternates' => ['de' => 'https://example.test/', 'en' => 'https://example.test/en']],
			['loc' => 'https://example.test/laden'],
		]);
		expect(
			"$decl\n<urlset xmlns=\"$ns\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n"
			. "  <url>\n    <loc>https://example.test/</loc>\n"
			. "    <xhtml:link rel=\"alternate\" hreflang=\"de\" href=\"https://example.test/\"/>\n"
			. "    <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"https://example.test/en\"/>\n"
			. "  </url>\n"
			. "  <url>\n    <loc>https://example.test/laden</loc>\n  </url>\n"
			. "</urlset>\n",
			$withAlt,
			'alternates: xmlns:xhtml declared once, one link per code in order'
		);
		expect(1, substr_count($withAlt, 'xmlns:xhtml='), 'xmlns:xhtml declared exactly once');
		expect(
			false,
			str_contains(Sitemap::render([['loc' => 'https://example.test/']]), 'xmlns:xhtml'),
			'no alternates anywhere: no xhtml namespace'
		);
		// Chunks.
		$entries = [['loc' => 'a'], ['loc' => 'b'], ['loc' => 'c']];
		expect([[['loc' => 'a'], ['loc' => 'b']], [['loc' => 'c']]], Sitemap::chunks($entries, 2), 'chunks of 3 with size 2');
		expect([[]], Sitemap::chunks([]), 'no entries give one empty chunk');
		expect([$entries], Sitemap::chunks($entries), 'default size keeps a small list in one chunk');
		expect(Sitemap::MAX_URLS, 50000, 'MAX_URLS is the sitemaps.org limit');
		// Chunks also by bytes: sitemaps.org allows 52,428,800 bytes per file, uncompressed,
		// and many languages with hreflang alternates pass that long before 50,000 URLs.
		expect(52428800, Sitemap::MAX_BYTES, 'MAX_BYTES is the sitemaps.org limit');
		$long = array_map(fn (int $i): array => [
			'loc' => 'https://example.test/' . str_repeat('x', 100) . "/$i",
			'lastmod' => '2027-03-10',
			'alternates' => ['de' => "https://example.test/de/$i", 'en' => "https://example.test/en/$i", 'x-default' => "https://example.test/$i"],
		], range(1, 10));
		$limit = strlen(Sitemap::render(array_slice($long, 0, 3)));
		$parts = Sitemap::chunks($long, 50000, $limit);
		expect([3, 3, 3, 1], array_map('count', $parts), 'byte limit of exactly three entries: files of three');
		holds(array_merge(...$parts) === $long, 'byte chunks keep every entry, in order');
		holds(max(array_map(fn (array $part): int => strlen(Sitemap::render($part)), $parts)) <= $limit, 'no file over the byte limit');
		expect([2, 2, 2, 2, 2], array_map('count', Sitemap::chunks($long, 50000, $limit - 1)), 'one byte less: files of two');
		expect([2, 2, 2, 2, 2], array_map('count', Sitemap::chunks($long, 2, $limit)), 'the URL count still caps a file');
		expect([1, 1], array_map('count', Sitemap::chunks(array_slice($long, 0, 2), 50000, 10)), 'an entry over the limit alone: a file of its own, nothing lost');
		// Sitemap index.
		expect(
			"$decl\n<sitemapindex xmlns=\"$ns\">\n"
			. "  <sitemap>\n    <loc>https://example.test/sitemap-1.xml</loc>\n    <lastmod>2026-10-11T01:14:00+02:00</lastmod>\n  </sitemap>\n"
			. "  <sitemap>\n    <loc>https://example.test/sitemap-2.xml</loc>\n  </sitemap>\n"
			. "</sitemapindex>\n",
			Sitemap::index([
				['loc' => 'https://example.test/sitemap-1.xml', 'lastmod' => '2026-10-11T01:14:00+02:00'],
				['loc' => 'https://example.test/sitemap-2.xml'],
			]),
			'sitemapindex with two files, lastmod only where given'
		);
		// lastmod().
		expect(
			(new DateTimeImmutable('@1791000000'))->setTimezone(new DateTimeZone('Europe/Berlin'))->format(DATE_ATOM),
			Sitemap::lastmod(1791000000, 'Europe/Berlin'),
			'lastmod from a timestamp in the given zone'
		);
		holds(str_ends_with((string)Sitemap::lastmod(1791000000, 'Europe/Berlin'), '+02:00'), 'Berlin in October is +02:00');
		expect(null, Sitemap::lastmod(null), 'lastmod(null) is null');
		expect(null, Sitemap::lastmod(0), 'lastmod(0) is null');
		expect(
			'2027-01-01T12:00:00+00:00',
			Sitemap::lastmod(new DateTimeImmutable('2027-01-01 12:00', new DateTimeZone('UTC')), 'UTC'),
			'lastmod from a DateTimeInterface'
		);
		expect(
			'2027-01-01T13:00:00+01:00',
			Sitemap::lastmod(new DateTimeImmutable('2027-01-01 12:00', new DateTimeZone('UTC')), 'Europe/Berlin'),
			'lastmod converts a DateTimeInterface into the given zone'
		);
		expect('1970-01-01T00:00:01+00:00', Sitemap::lastmod(1, 'UTC'), 'lastmod of a small timestamp is W3C-shaped');
		// Values from annai.sitemap.extra: what may go into <loc> and <lastmod>.
		foreach (['https://x.test/a', 'http://x.test', 'https://x.test/seite?a=1&b=2#f'] as $loc) {
			holds(Sitemap::validLoc($loc), "validLoc: $loc");
		}
		foreach (['/relativ', 'ftp://x.test/a', 'https://', 'https://x.test/a b', "https://x.test/a\x01b", "https://x.test/caf\xE9", 'javascript:alert(1)'] as $loc) {
			holds(!Sitemap::validLoc($loc), 'validLoc rejects ' . json_encode(mb_scrub($loc)));
		}
		foreach (['2027', '2027-01', '2027-01-01', '2027-01-01T08:00Z', '2027-01-01T08:00:00+02:00', '2027-01-01T08:00:00.25-05:00'] as $lastmod) {
			holds(Sitemap::validLastmod($lastmod), "validLastmod: $lastmod");
		}
		foreach (['gestern', '2027-13-01', '2027-02-30', '2027-01-01T08:00', '2027-01-01 08:00:00', '01.01.2027'] as $lastmod) {
			holds(!Sitemap::validLastmod($lastmod), "validLastmod rejects $lastmod");
		}
		expect(false, Sitemap::validLastmod("2027-01-01\n"), 'validLastmod rejects a trailing newline');
		// Whatever reaches render(), the file stays well-formed XML.
		$xml = Sitemap::render([
			['loc' => "https://x.test/a\x01b", 'lastmod' => "2027\x02"],
			['loc' => "https://x.test/caf\xE9"],
			['loc' => 'https://x.test/ok', 'alternates' => ["d\x03e" => "https://x.test/\xFF"]],
		]);
		$previous = libxml_use_internal_errors(true);
		$parsed = simplexml_load_string($xml);
		libxml_use_internal_errors($previous);
		holds($parsed !== false, 'render: control characters and invalid UTF-8 still give well-formed XML');
		holds(
			str_contains($xml, '<loc>https://x.test/ab</loc>') && str_contains($xml, "<loc>https://x.test/caf\u{FFFD}</loc>"),
			'render: control character dropped, invalid byte substituted (never an empty <loc>)'
		);
	}
}
