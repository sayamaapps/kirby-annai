<?php

namespace Sayamaapps\Annai;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;

/**
 * What search engines and agents see, per page and per site (docs/SPEC.md §3.11).
 *
 * Never fetches over HTTP: everything is read from the same methods the
 * site uses for its own output, so the report cannot disagree with the
 * page. Problem wording is fixed (tests check it).
 *
 * @package   Kirby Annai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-annai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Check
{
	/** Site-level problem (sprintf with the message) for an option Options::warn() reported during the run. */
	public const CONFIGURATION_PROBLEM = 'configuration: %s';
	public const DESCRIPTION_MIN = 50;

	/**
	 * The description limit is `annai.descriptionLength` plus this tolerance:
	 * 155 + 5 = 160 by default. The tolerance is added to the configured limit,
	 * so a length of 200 accepts up to 205 characters.
	 */
	public const DESCRIPTION_TOLERANCE = 5;

	/**
	 * Site-level problem when the business page is looked up in the whole page
	 * index (Business::searchesIndex()): no business page in the top two levels.
	 */
	public const LOCATION_PROBLEM = 'no business page in the top two page levels: every request searches the whole page index for one; '
		. 'set annai.location (a page id, or fn () => null for none)';

	/**
	 * Site-level problem (sprintf with the brand field name) when the business
	 * has no name: no brand field, no site title. Then llms.txt has no name for
	 * its H1 (the host stands in), the home page no JSON-LD and no <title>; the
	 * page reports alone would say only "title missing", not why.
	 */
	public const NAME_PROBLEM = 'name missing: set the brand field (%s) or the site title';

	/**
	 * Page-level note (sprintf with the language code and the canonical;
	 * printed, not counted) when the page has no content in the current
	 * language and its canonical points to the language version that has it
	 * (Pages::contentUrl()): not 'canonical differs from the page URL', which
	 * is the rule working, not a problem.
	 */
	public const NO_CONTENT_NOTE = 'no content in %s: canonical points to %s';

	/**
	 * Site-level note (printed, not counted) when Kirby's URL has no host: on the
	 * command line without --url every URL in the report is a path ("/laden").
	 */
	public const NO_HOST_NOTE = 'no host in the site URL: pass --url=https://example.test (canonical, og:url and the Sitemap: line are paths here)';

	/**
	 * Site-level problem when Noren runs without `noren.locations`
	 * (Business::norenSearchesIndex()): Noren walks the whole page index for
	 * its location pages, also with `annai.location` set — that
	 * spares Annai's lookup, not Noren's. Reported instead of
	 * LOCATION_PROBLEM, whose advice does not help here.
	 */
	public const NOREN_LOCATIONS_PROBLEM = 'Noren runs without noren.locations: every page that shows the opening hours or asks for the business page '
		. 'makes Noren walk the whole page index for its location pages; '
		. 'set noren.locations (annai.location does not stop it)';

	/** Site-level problem when the previous plugin is still installed next to Annai. */
	public const OLD_PLUGIN_PROBLEM = 'old plugin site/plugins/suchmaschinen is still installed: it and Annai both register robots.txt, sitemap.xml and llms.txt; remove it';

	/** The reasons of Text::phoneProblem() as render() prints them: "→ (none: more than the number)". */
	public const PHONE_PROBLEMS = [
		'characters' => 'more than the number',
		'invalid'    => 'no valid number',
		'library'    => 'libphonenumber is not installed',
	];

	/** Site-level problem while the preview protection is on: said once, not once per page. */
	public const PREVIEW_PROBLEM = 'preview on: every page carries noindex';

	/** Site-level problem (sprintf with the file name) when a static file in the web root shadows a route. */
	public const STATIC_FILE_PROBLEM = 'static file %s in the web root is served by the web server instead of Annai\'s route; remove it';
	public const TITLE_MAX = 60;

	/** The description limit: `annai.descriptionLength` + DESCRIPTION_TOLERANCE (160 by default). */
	public static function descriptionMax(App $kirby): int
	{
		return Options::descriptionLength($kirby) + self::DESCRIPTION_TOLERANCE;
	}

	/** The problem text for a description above descriptionMax(): 'description longer than 160 characters'. */
	public static function descriptionProblem(App $kirby): string
	{
		return 'description longer than ' . self::descriptionMax($kirby) . ' characters';
	}

	/** 1 when any page problem, site problem or robots warning exists, else 0. */
	public static function exitCode(array $report): int
	{
		return self::problemCount($report) > 0 ? 1 : 0;
	}

	/**
	 * The `## ` headings of llms.txt. Text::EOL, not `\R`: in byte mode `\R` cut
	 * a heading "## 案内" inside the "内".
	 *
	 * @return list<string>
	 */
	private static function headings(string $llms): array
	{
		$headings = [];
		foreach (preg_split(Text::EOL, $llms) ?: [] as $line) {
			if (str_starts_with($line, '## ')) {
				$headings[] = trim(substr($line, 3));
			}
		}
		return $headings;
	}

	/**
	 * JSON-LD facts of one page: the schema.org types (from @type, or from
	 * every @graph node) and whether the document encodes as valid JSON.
	 * A page without JSON-LD is valid with no types.
	 *
	 * @return array{types: list<string>, valid: bool}
	 */
	private static function jsonld(array|null $document): array
	{
		if ($document === null) {
			return ['types' => [], 'valid' => true];
		}
		try {
			json_decode(JsonLd::encode($document), true, 512, JSON_THROW_ON_ERROR);
			$valid = true;
		} catch (\JsonException) {
			$valid = false;
		}

		$nodes = isset($document['@graph']) && is_array($document['@graph']) ? $document['@graph'] : [$document];
		$types = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			foreach ((array)($node['@type'] ?? []) as $type) {
				if (is_string($type) && $type !== '') {
					$types[] = $type;
				}
			}
		}
		return ['types' => array_values(array_unique($types)), 'valid' => $valid];
	}

	/** "text (N characters)", or "none" for an empty text. */
	private static function measured(string $text, int $length): string
	{
		return $text === '' ? 'none' : '"' . $text . '" (' . $length . ' characters)';
	}

	/**
	 * Notes that are printed but not counted. On the command line without --url
	 * Kirby's URL is "/", and canonical, og:url and the Sitemap: line come out as
	 * paths: the site is not wrong, the call is incomplete.
	 *
	 * @return list<string>
	 */
	private static function notes(App $kirby): array
	{
		$notes = [];
		if (preg_match('#^https?://[^/]+#i', Pages::base($kirby)) !== 1) {
			$notes[] = self::NO_HOST_NOTE;
		}
		return $notes;
	}

	/**
	 * What engines and agents see on one page: url, id, template, indexable,
	 * robots meta, title and description with lengths, canonical, og:image,
	 * number of hreflang alternates, JSON-LD types and validity, problems and
	 * notes (NO_CONTENT_NOTE; printed, not counted).
	 *
	 * @return array{url: string, id: string, template: string, indexable: bool, robots: ?string,
	 *   title: array{text: string, length: int}, description: array{text: string, length: int},
	 *   canonical: string, image: ?string, alternates: int,
	 *   jsonld: array{types: list<string>, valid: bool}, problems: list<string>, notes: list<string>}
	 */
	public static function page(App $kirby, Page $page): array
	{
		// JSON-LD that cannot be encoded is left out of the head (Business::head()
		// logs it); the raw document from annaiJsonLd() below still reports it.
		/** @psalm-suppress UndefinedMagicMethod annaiHead() is registered by this plugin (pageMethods) */
		$head = $page->annaiHead() ?? [];
		$title = (string)($head['title'] ?? '');
		$description = (string)($head['description'] ?? '');
		$canonical = (string)($head['canonical'] ?? '');
		$image = $head['og']['og:image'] ?? null;
		$image = is_string($image) ? $image : null;
		$alternates = is_array($head['alternates'] ?? null) ? count($head['alternates']) : 0;
		$indexable = !Pages::excluded($kirby, $page);
		// The robots meta is reported, never a problem: an indexable page carries
		// noindex only while the preview is on, and site() says that once
		// (PREVIEW_PROBLEM); drafts and excluded pages are not indexable.
		$robots = Pages::robots($kirby, $page);
		/** @psalm-suppress UndefinedMagicMethod annaiJsonLd() is registered by this plugin (pageMethods) */
		$jsonld = self::jsonld($page->annaiJsonLd());
		$descriptionLength = mb_strlen($description);

		$problems = [];
		if ($title === '') {
			$problems[] = 'title missing';
		} elseif (mb_strlen($title) > self::TITLE_MAX) {
			$problems[] = 'title longer than 60 characters';
		}
		if ($description === '') {
			$problems[] = 'description missing';
		} elseif ($descriptionLength < self::DESCRIPTION_MIN) {
			$problems[] = 'description shorter than 50 characters';
		} elseif ($descriptionLength > self::descriptionMax($kirby)) {
			$problems[] = self::descriptionProblem($kirby);
		}
		// A page without content in the current language points to the language
		// version that has it: that is the rule, said as a note, not a problem.
		$notes = [];
		$content = Pages::contentUrl($kirby, $page);
		if ($content !== null && $canonical === $content) {
			$notes[] = sprintf(self::NO_CONTENT_NOTE, (string)$kirby->language()?->code(), $canonical);
		} elseif ($canonical !== $page->url()) {
			$problems[] = 'canonical differs from the page URL';
		}
		if ($image !== null && preg_match('#^https://#i', $image) !== 1) {
			$problems[] = 'og:image is not an absolute https URL';
		}
		if ($jsonld['valid'] === false) {
			$problems[] = 'JSON-LD is not valid JSON';
		}

		return [
			'url'         => $page->url(),
			'id'          => $page->id(),
			'template'    => $page->intendedTemplate()->name(),
			'indexable'   => $indexable,
			'robots'      => $robots,
			'title'       => ['text' => $title, 'length' => mb_strlen($title)],
			'description' => ['text' => $description, 'length' => $descriptionLength],
			'canonical'   => $canonical,
			'image'       => $image,
			'alternates'  => $alternates,
			'jsonld'      => $jsonld,
			'problems'    => $problems,
			'notes'       => $notes,
		];
	}

	/**
	 * Problems across the pages: the preview, ids that name no indexable page,
	 * and titles that more than one page uses.
	 *
	 * @param list<string> $only
	 * @param list<string> $ids
	 * @param list<array<string,mixed>> $pages
	 * @return list<string>
	 */
	private static function pageProblems(array $only, array $ids, array $pages, bool $preview): array
	{
		$problems = [];
		if ($preview) {
			$problems[] = self::PREVIEW_PROBLEM;
		}
		foreach ($only as $id) {
			if (!in_array($id, $ids, true)) {
				$problems[] = 'no indexable page with id: ' . $id;
			}
		}

		$byTitle = [];
		foreach ($pages as $page) {
			if ($page['title']['text'] !== '') {
				$byTitle[$page['title']['text']][] = $page['id'];
			}
		}
		foreach ($byTitle as $title => $list) {
			if (count($list) > 1) {
				$problems[] = 'duplicate title: ' . $title . ' (' . implode(', ', $list) . ')';
			}
		}
		return $problems;
	}

	/** annaiCheck(): what engines and agents see on this page, null when Annai is off. */
	public static function pageWhenOn(App $kirby, Page $page): array|null
	{
		return Options::active($kirby) ? self::page($kirby, $page) : null;
	}

	/**
	 * One line of `phones`: the raw field value, the published E.164 number (null when none)
	 * and, without one, the reason of Text::phoneProblem() (null for a number).
	 *
	 * @param array{field: string, page?: string} $where
	 * @return array{field: string, page?: string, raw: string, e164: ?string, problem: ?string}
	 */
	private static function phone(array $where, string $raw, string $e164, string $country): array
	{
		return $where + [
			'raw'     => $raw,
			'e164'    => $e164 !== '' ? $e164 : null,
			'problem' => $e164 !== '' ? null : Text::phoneProblem($raw, $country),
		];
	}

	/**
	 * Every phone and fax field with its raw value and its result (SPEC §3.2, the
	 * input contract): the E.164 number Annai publishes, or why it publishes none,
	 * so a person sees each field before the go-live. Printed, not counted; a field
	 * that gives no number is logged as well and so is a configuration problem.
	 * Also the phone of every further location page: it goes into the JSON-LD
	 * departments and llms.txt as well, read from Business::units(), one line per
	 * page; the raw value from that page's own field, as Business::place() reads it.
	 *
	 * @param array<string,mixed> $facts
	 * @return list<array<string,mixed>>
	 */
	private static function phoneReports(App $kirby, array $facts): array
	{
		$country = Options::phoneCountry($kirby);
		$phones = [];
		foreach (Options::active($kirby) ? ['phone', 'fax'] : [] as $key) {
			$raw = Business::field($kirby, $key);
			if ($raw !== '') {
				$phones[] = self::phone(['field' => $key], $raw, (string)($facts[$key . 'E164'] ?? ''), $country);
			}
		}
		$placePages = [];
		$phoneField = (string)Options::fields($kirby)['phone'];
		foreach (Options::active($kirby) ? Business::units($kirby) : [] as $unit) {
			$place = $unit['place'] ?? null;
			$page = (string)($unit['location'] ?? '');
			if ($place === null || $place['phone'] === '' || isset($placePages[$page])) {
				continue;
			}
			$placePages[$page] = true;
			$field = $kirby->page($page)?->content()->get($phoneField);
			$raw = $field instanceof Field ? (string)$field : '';
			$phones[] = self::phone(['field' => 'phone', 'page' => $page], $raw, (string)$place['phoneE164'], $country);
		}
		return $phones;
	}

	/** Page problems + site problems + robots warnings. */
	private static function problemCount(array $report): int
	{
		$count = count($report['robots']['warnings'] ?? []) + count($report['problems'] ?? []);
		foreach ($report['pages'] ?? [] as $page) {
			$count += count($page['problems'] ?? []);
		}
		return $count;
	}

	/**
	 * Human-readable text: one block per page, problems prefixed "  ! ",
	 * robots warnings prefixed "  ! " too, phone and fax with the raw value as
	 * JSON ("  ~ phone: \"030 / 34567000\" → +493034567000", "→ (none: more than
	 * the number)" when nothing is published, PHONE_PROBLEMS; a further
	 * location's phone with its page id, "  ~ phone (b): …") and notes
	 * (site and page) prefixed "  ~ " (not counted), and a summary line
	 * "N pages, M problems" at the end. Control characters are removed and
	 * line feeds inside a value become spaces.
	 */
	public static function render(array $report): string
	{
		$robots = $report['robots'] ?? [];
		$sitemap = $report['sitemap'] ?? [];
		$llms = $report['llms'] ?? [];
		$pages = $report['pages'] ?? [];

		$lines = [];
		$lines[] = 'robots.txt: ' . substr_count((string)($robots['text'] ?? ''), "\n") . ' lines';
		foreach ($robots['warnings'] ?? [] as $warning) {
			$lines[] = '  ! ' . $warning;
		}

		$lines[] = sprintf(
			'sitemap.xml: %d URLs in %d file(s), lastmod %s',
			(int)($sitemap['count'] ?? 0),
			(int)($sitemap['chunks'] ?? 1),
			!empty($sitemap['lastmod']) ? 'yes' : 'no'
		);

		$headings = implode(', ', $llms['headings'] ?? []);
		$lines[] = sprintf(
			'llms.txt: %d characters, sections: %s',
			(int)($llms['length'] ?? 0),
			$headings !== '' ? $headings : 'none'
		);
		$lines[] = 'preview: ' . (!empty($report['hidden']) ? 'on (everything closed)' : (!empty($report['preview']) ? 'on (noindex, files as live)' : 'off'));
		foreach ($report['phones'] ?? [] as $phone) {
			$problem = $phone['problem'] ?? null;
			$lines[] = '  ~ ' . $phone['field'] . (isset($phone['page']) ? ' (' . $phone['page'] . ')' : '') . ': '
				. Text::shown((string)($phone['raw'] ?? ''))
				. ' → ' . ($phone['e164'] ?? '(none' . ($problem !== null ? ': ' . (self::PHONE_PROBLEMS[$problem] ?? $problem) : '') . ')');
		}
		foreach ($report['notes'] ?? [] as $note) {
			$lines[] = '  ~ ' . $note;
		}
		$lines[] = '';

		foreach ($pages as $page) {
			$lines[] = $page['url'] . ' (' . ($page['template'] !== '' ? $page['template'] : 'no template') . ', id ' . $page['id'] . ')';
			$lines[] = '  title: ' . self::measured($page['title']['text'] ?? '', (int)($page['title']['length'] ?? 0));
			$lines[] = '  description: ' . self::measured($page['description']['text'] ?? '', (int)($page['description']['length'] ?? 0));
			$lines[] = '  canonical: ' . ($page['canonical'] !== '' ? $page['canonical'] : 'none');
			$lines[] = '  og:image: ' . ($page['image'] ?? 'none');
			$lines[] = '  robots: ' . ($page['robots'] ?? 'none');
			$lines[] = '  hreflang alternates: ' . (int)($page['alternates'] ?? 0);
			$types = implode(', ', $page['jsonld']['types'] ?? []);
			$lines[] = '  JSON-LD: ' . ($types !== '' ? $types : 'none')
				. (($page['jsonld']['valid'] ?? true) ? '' : ' (invalid)');
			foreach ($page['problems'] ?? [] as $problem) {
				$lines[] = '  ! ' . $problem;
			}
			foreach ($page['notes'] ?? [] as $note) {
				$lines[] = '  ~ ' . $note;
			}
			$lines[] = '';
		}

		if (($report['problems'] ?? []) !== []) {
			$lines[] = 'site';
			foreach ($report['problems'] as $problem) {
				$lines[] = '  ! ' . $problem;
			}
			$lines[] = '';
		}

		$lines[] = count($pages) . ' pages, ' . self::problemCount($report) . ' problems';
		// Titles, descriptions, ids and headings come from the Panel: no
		// control characters, and a line feed inside a value must not start
		// a line of its own (a fake "  ! " problem or summary line).
		$lines = array_map(fn (string $line): string => str_replace("\n", ' ', I18n::printable($line)), $lines);
		return implode("\n", $lines) . "\n";
	}

	/**
	 * robots.txt as it will be served and its warnings. The warnings are those of
	 * the configuration that applies once the preview ends: with blockAll (an
	 * explicit annai.noindex => true), warnings() would say only "Disallow: / for
	 * everyone" and hide a blocked user agent until the go-live.
	 *
	 * @return array{text: string, warnings: list<string>}
	 */
	private static function robotsReport(App $kirby): array
	{
		$robotsConfig = Routes::robotsConfig($kirby);
		return [
			'text'     => Robots::render($robotsConfig),
			'warnings' => Robots::warnings(array_merge($robotsConfig, ['blockAll' => false])),
		];
	}

	/**
	 * Problems of the installation and the configuration: the 2.x plugin next to
	 * this one, static files in the web root, the location pages, and a missing
	 * name. Also the facts without the units (no Noren call for a name check),
	 * which the phone reports use.
	 *
	 * @param list<string> $problems
	 * @return array{0: list<string>, 1: array<string,mixed>}
	 */
	private static function setupProblems(App $kirby, array $problems): array
	{
		if (is_dir($kirby->root('site') . '/plugins/suchmaschinen')) {
			$problems[] = self::OLD_PLUGIN_PROBLEM;
		}
		// The web server serves an existing file before Kirby runs (.htaccess
		// "!-f"): a static robots.txt left in the web root silently replaces the route.
		foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $name) {
			if (is_file($kirby->root('index') . '/' . $name)) {
				$problems[] = sprintf(self::STATIC_FILE_PROBLEM, $name);
			}
		}
		if (Options::active($kirby) && Business::norenSearchesIndex($kirby)) {
			$problems[] = self::NOREN_LOCATIONS_PROBLEM;
		} elseif (Options::active($kirby) && Business::searchesIndex($kirby)) {
			$problems[] = self::LOCATION_PROBLEM;
		}
		$facts = Options::active($kirby) ? Business::facts($kirby, false) : [];
		if (Options::active($kirby) && ($facts['name'] ?? '') === '') {
			$problems[] = sprintf(self::NAME_PROBLEM, Options::fields($kirby)['brand']);
		}
		return [$problems, $facts];
	}

	/**
	 * Site report (see the layout in docs/SPEC.md §3.11): robots.txt and its
	 * warnings, sitemap counts, llms.txt length and section headings, preview
	 * state (preview, and hidden for an explicit annai.noindex => true), every
	 * phone and fax field with its raw value and its E.164 number or the reason
	 * it has none, also the phone of every further location page
	 * (printed, not counted),
	 * notes (NO_HOST_NOTE; printed, not counted), the page reports and
	 * the site-level problems, among them every option Options::warn()
	 * reported while the report was built (CONFIGURATION_PROBLEM).
	 *
	 * @param list<string> $only page ids; [] = all indexable pages
	 */
	public static function site(App $kirby, array $only = []): array
	{
		// Options::warn() logs a broken option once per process; the messages
		// of this run become site problems below, so the report says what the
		// log says (bin/annai-check runs with debug off, so nothing throws).
		$logged = count(Options::logged());

		// The sitemap and llms.txt as they are served once the preview ends
		// (while it is hidden, the routes serve an empty urlset and only the H1;
		// a protected preview serves them as they are after the go-live anyway).
		$entries = Pages::entries($kirby);
		$chunks = Sitemap::chunks($entries, Options::sitemapChunk($kirby));
		$llms = LlmsTxt::render(Routes::llmsModel($kirby));

		[$pages, $ids] = self::sitePages($kirby, $only);
		$preview = Pages::preview($kirby);
		$hidden = Pages::hidden($kirby);
		$problems = self::pageProblems($only, $ids, $pages, $preview);
		[$problems, $facts] = self::setupProblems($kirby, $problems);
		$phones = self::phoneReports($kirby, $facts);
		$notes = self::notes($kirby);
		$headings = self::headings($llms);

		$lastmod = $entries !== [] && array_filter($entries, fn ($entry) => ($entry['lastmod'] ?? null) === null) === [];
		$robots = self::robotsReport($kirby);

		foreach (array_slice(Options::logged(), $logged) as $message) {
			$problems[] = sprintf(self::CONFIGURATION_PROBLEM, (string)preg_replace('/^Annai: /', '', $message));
		}

		return [
			'robots' => $robots,
			'sitemap' => [
				'count'   => count($entries),
				'chunks'  => count($chunks),
				'lastmod' => $lastmod,
			],
			'llms' => [
				'length'   => strlen($llms),
				'headings' => $headings,
			],
			'preview'  => $preview,
			'hidden'   => $hidden,
			'phones'   => $phones,
			'notes'    => $notes,
			'pages'    => $pages,
			'problems' => $problems,
		];
	}

	/**
	 * The page reports of the site, and the ids of the indexable pages (the
	 * ids of $only are checked against them).
	 *
	 * @param list<string> $only
	 * @return array{0: list<array<string,mixed>>, 1: list<string>}
	 */
	private static function sitePages(App $kirby, array $only): array
	{
		$pages = [];
		$ids = [];
		foreach (Pages::all($kirby) as $page) {
			if ($only !== [] && !in_array($page->id(), $only, true)) {
				continue;
			}
			$pages[] = self::page($kirby, $page);
			$ids[] = $page->id();
		}
		return [$pages, $ids];
	}
}
