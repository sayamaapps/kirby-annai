<?php

namespace Sayamaapps\Annai;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Http\Response;
use Kirby\Http\Route;

/**
 * The route bodies: robots.txt, sitemap.xml, llms.txt (docs/SPEC.md §3.9).
 *
 * Every absolute URL comes from the site or page (never a hard-coded host).
 * Each public method returns null or a plain text when Annai is off; index.php
 * decides the fall-through ($this->next()).
 *
 * @package   Kirby Annai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-annai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Routes
{
	/**
	 * Response headers: `Cache-Control: no-store` while the preview is on, also
	 * when the files are the normal ones. The three files sit behind the
	 * protection, but a site that exempts them (so a crawler can read them)
	 * hands the closed file or the page list to a CDN or proxy in front, which
	 * kept it until its TTL ran out — past the go-live. Kirby's `cache(false)`
	 * covers its own page cache only.
	 *
	 * @return ?array<string,string>
	 */
	private static function headers(App $kirby): array|null
	{
		return Pages::preview($kirby) ? ['Cache-Control' => 'no-store'] : null;
	}

	/** Response text/plain. */
	public static function llms(App $kirby): Response
	{
		return new Response(self::llmsText($kirby), 'text/plain', null, self::headers($kirby));
	}

	/**
	 * LlmsTxt::render() input (docs/SPEC.md §3.5, §3.9) from Business::facts() and
	 * Pages. Texts follow the site language (applyLanguage first).
	 */
	public static function llmsModel(App $kirby): array
	{
		Options::applyLanguage($kirby);
		$facts = Business::facts($kirby);
		$site = $kirby->site();
		$siteUrl = rtrim($site->url(), '/');

		$address = Text::line(implode(', ', array_filter([
			$facts['street'] ?? '',
			trim(($facts['postalCode'] ?? '') . ' ' . ($facts['city'] ?? '')),
		], fn ($part) => $part !== '')));
		$action = $facts['action'] ?? null;

		// The home page first, under its own label — unless `annai.exclude.pages`
		// keeps it out: then it is in no list, as in the sitemap.
		$pages = [];
		$home = $site->homePage();
		// Every link is the page's canonical URL: a page without content in the
		// current language is linked under the language version that has it
		// (Pages::contentUrl(), as the head's canonical).
		if ($home instanceof Page && !Pages::excluded($kirby, $home)) {
			$pages[] = ['title' => I18n::t('llms.home'), 'url' => Pages::contentUrl($kirby, $home) ?? $siteUrl, 'note' => ''];
		}
		// Title and note come from the same language version as the link
		// (Pages::contentIn()).
		$intro = Options::fields($kirby)['intro'];
		foreach (Pages::menu($kirby) as $page) {
			$code = Pages::contentLanguage($kirby, $page);
			$pages[] = [
				'title' => Pages::titleIn($page, $code),
				'url'   => $code === null ? $page->url() : $page->url($code),
				// kirbytext() first, like the meta description: no raw Markdown or Kirbytags in the note.
				// Invalid UTF-8 before it, else kirbytext() returns "<p></p>".
				'note'  => self::note(Pages::contentIn($page, $code)->get($intro)),
			];
		}

		$optional = [];
		foreach (Pages::optional($kirby) as $page) {
			$code = Pages::contentLanguage($kirby, $page);
			$optional[] = ['title' => Pages::titleIn($page, $code), 'url' => $code === null ? $page->url() : $page->url($code), 'note' => ''];
		}

		$model = [
			'title'    => self::siteName($kirby, $facts),
			'summary'  => Text::line((string)($facts['description'] ?? '')),
			'contact'  => [
				'address'    => $address,
				'phone'      => (string)($facts['phone'] ?? ''),
				'phoneE164'  => (string)($facts['phoneE164'] ?? ''),
				'email'      => (string)($facts['email'] ?? ''),
				'action'     => is_array($action) && ($action['target'] ?? '') !== ''
					? ['name' => (string)($action['name'] ?? ''), 'target' => (string)$action['target']]
					: null,
				'extra'      => [],
			],
			'units'    => $facts['units'] ?? [],
			'events'   => Pages::events($kirby),
			'pages'    => $pages,
			'optional' => $optional,
		];

		$hook = Options::hook($kirby, 'llms.extra');
		if ($hook !== null) {
			$changed = Options::call($kirby, 'llms.extra', $hook, [$model, $facts], $model, "using Annai's model");
			if (is_array($changed)) {
				$model = $changed;
			} else {
				Options::warn($kirby, 'Annai: annai.llms.extra must return the model array, not ' . get_debug_type($changed) . ' — ignored.');
			}
		}
		return $model;
	}

	/** The llms.txt route: the response, or the next route when Annai is off. */
	public static function llmsRoute(App $kirby, Route $route): mixed
	{
		return Options::active($kirby) ? self::llms($kirby) : $route->next();
	}

	/** LlmsTxt::render(llmsModel()); while Pages::hidden('/llms.txt') only the H1 (see sitemap()). */
	public static function llmsText(App $kirby): string
	{
		if (Pages::hidden($kirby, '/llms.txt')) {
			// The name alone: no Noren call for a file that shows no hours.
			return LlmsTxt::heading(self::siteName($kirby, Business::facts($kirby, false))) . "\n";
		}
		return LlmsTxt::render(self::llmsModel($kirby));
	}

	/** annaiLlms(): the llms.txt text, '' when Annai is off. */
	public static function llmsTextWhenOn(App $kirby): string
	{
		return Options::active($kirby) ? self::llmsText($kirby) : '';
	}

	/**
	 * The newest lastmod of a chunk, compared as instants (offsets differ); null when none.
	 * A year alone ("2000", a W3C datetime) is the first of January: DateTimeImmutable
	 * reads "2000" as 20:00 today, which made it the newest of every chunk. A year and
	 * month is the first of the month (PHP reads it so already; spelled out here).
	 */
	private static function newest(array $entries): string|null
	{
		$newest = null;
		$newestTime = null;
		foreach ($entries as $entry) {
			$lastmod = $entry['lastmod'] ?? null;
			if (!is_string($lastmod) || $lastmod === '') {
				continue;
			}
			$date = $lastmod;
			if (preg_match('/^\d{4}$/D', $lastmod) === 1) {
				$date .= '-01-01';
			} elseif (preg_match('/^\d{4}-\d{2}$/D', $lastmod) === 1) {
				$date .= '-01';
			}
			try {
				$time = (new DateTimeImmutable($date))->getTimestamp();
			} catch (\Exception) {
				continue;
			}
			if ($newestTime === null || $time > $newestTime) {
				$newestTime = $time;
				$newest = $lastmod;
			}
		}
		return $newest;
	}

	/** The llms.txt note of a page: its intro field rendered and excerpted (160 characters). */
	private static function note(Field $field): string
	{
		return Text::excerpt((string)$field->value(Text::utf8((string)$field->value()))->kirbytext(), 160);
	}

	/**
	 * Entries without the 'page' key, the shape Sitemap::render() takes.
	 *
	 * @return list<array{loc: string, lastmod: ?string, alternates: array<string,string>}>
	 */
	private static function plainEntries(array $entries): array
	{
		return array_map(fn (array $entry): array => [
			'loc'        => $entry['loc'],
			'lastmod'    => $entry['lastmod'],
			'alternates' => $entry['alternates'],
		], $entries);
	}

	/** Response text/plain. */
	public static function robots(App $kirby): Response
	{
		return new Response(self::robotsText($kirby), 'text/plain', null, self::headers($kirby));
	}

	/**
	 * Robots::render() input (docs/SPEC.md §3.3, §3.9). The Sitemap line uses Kirby's
	 * base URL: on a multi-language site $site->url() is the current language's
	 * URL (/de), and /de/sitemap.xml does not exist. blockAll only while
	 * Pages::hidden('/robots.txt') (annai.noindex => true, or the protection
	 * exempts the route): a protected preview answers the normal file.
	 */
	public static function robotsConfig(App $kirby): array
	{
		return [
			'disallow' => Options::disallow($kirby),
			'sitemap'  => Pages::base($kirby) . '/sitemap.xml',
			'agents'   => Robots::agents(Options::robotsAi($kirby), Options::robotsAgents($kirby)),
			'extra'    => Options::robotsExtra($kirby),
			'blockAll' => Pages::hidden($kirby, '/robots.txt'),
		];
	}

	/** The robots.txt route: the response, or the next route when Annai is off. */
	public static function robotsRoute(App $kirby, Route $route): mixed
	{
		return Options::active($kirby) ? self::robots($kirby) : $route->next();
	}

	/** The robots.txt text for the current options. It does not check the master switch (robotsTextWhenOn() does). */
	public static function robotsText(App $kirby): string
	{
		return Robots::render(self::robotsConfig($kirby));
	}

	/** annaiRobots() of the site: the robots.txt text, '' when Annai is off. */
	public static function robotsTextWhenOn(App $kirby): string
	{
		return Options::active($kirby) ? self::robotsText($kirby) : '';
	}

	/**
	 * $chunk null: one sitemap.xml when the entries fit into one file, else a
	 * sitemapindex over sitemap-<n>.xml. $chunk n: the n-th file, or null when
	 * out of range or when only one file exists. While Pages::hidden() is true
	 * for the route (/sitemap.xml, or /sitemap-<n>.xml for a chunk): an empty
	 * urlset, sent with Cache-Control: no-store (headers()); a closed chunk is
	 * null → 404 (nothing to split, so no header either). A protected preview
	 * lists the entries as usual, unless the protection exempts the route.
	 * Response application/xml.
	 */
	public static function sitemap(App $kirby, int|null $chunk = null): Response|null
	{
		if (!Options::active($kirby)) {
			return null;
		}
		// A closed route lists nothing: robots.txt says Disallow: / anyway. A
		// protected preview lists the pages as the file is served after the
		// go-live: Sudare answers unauthenticated requests before Kirby renders.
		$path = $chunk === null ? '/sitemap.xml' : '/sitemap-' . $chunk . '.xml';
		$entries = Pages::hidden($kirby, $path) ? [] : Pages::entries($kirby);
		$size = Options::sitemapChunk($kirby);
		$parts = Sitemap::chunks($entries, $size);
		$headers = Pages::preview($kirby) ? ['Cache-Control' => 'no-store'] : null;

		if ($chunk === null) {
			if (count($parts) <= 1) {
				return new Response(Sitemap::render(self::plainEntries($parts[0] ?? [])), 'application/xml', null, $headers);
			}
			$base = Pages::base($kirby);
			$sitemaps = [];
			foreach ($parts as $number => $part) {
				$sitemaps[] = [
					'loc'     => $base . '/sitemap-' . ((int)$number + 1) . '.xml',
					'lastmod' => self::newest($part),
				];
			}
			return new Response(Sitemap::index($sitemaps), 'application/xml', null, $headers);
		}

		if (count($parts) <= 1 || $chunk < 1 || $chunk > count($parts)) {
			return null;
		}
		return new Response(Sitemap::render(self::plainEntries($parts[$chunk - 1])), 'application/xml', null, $headers);
	}

	/**
	 * The name for the llms.txt H1: the business name (brand field, else the
	 * site title), else the host of Kirby's base URL. llmstxt.org requires an
	 * H1 with a name. Without brand and title the file would begin with
	 * "# " alone; the check reports the missing name (Check::NAME_PROBLEM).
	 * '' only without a host as well (the CLI without --url: NO_HOST_NOTE).
	 */
	private static function siteName(App $kirby, array $facts): string
	{
		$name = (string)($facts['name'] ?? '');
		if ($name !== '') {
			return $name;
		}
		$host = parse_url(Pages::base($kirby), PHP_URL_HOST);
		return is_string($host) ? $host : '';
	}
}
