<?php

use Kirby\Cms\App;
use Kirby\Filesystem\F;
use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\Check;
use Sayamaapps\Annai\Head;
use Sayamaapps\Annai\I18n;
use Sayamaapps\Annai\Options;
use Sayamaapps\Annai\Pages;
use Sayamaapps\Annai\Routes;

// The class map (autoload.php) and the old names (legacy.php) load before the
// plugin is registered. Both files only declare; no logic runs here.
F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

/**
 * Annai (案内 — guidance; the one who shows the way): everything search
 * engines and AI agents read from a Kirby 5 site. robots.txt, sitemap.xml and
 * llms.txt as routes (behind whatever protects the site before Kirby boots),
 * JSON-LD for the business, meta description, canonical, Open Graph, noindex
 * and hreflang in one head snippet.
 *
 * Off by default: without `'annai.active' => true` every route falls through
 * to Kirby, every method returns null/[]/'' and the snippet prints nothing.
 *
 * Registration only: all logic lives in src/ (docs/SPEC.md §3.10).
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under sayamaapps.annai.*
 * otherwise).
 *
 * site/plugins/suchmaschinen (the plugin this package replaces) must NOT stay
 * installed next to it: both register the same route patterns, and Kirby
 * takes the first match in plugin load order (README, "Upgrading"). Nothing
 * here can detect that at load time without recursing into plugins(); the
 * check tool reports it instead (Check::site(), 'problems').
 */

/** Reads `annai.<key>`. Also meant for site snippets with their own markup. */
function annai_option(string $key, mixed $default = null): mixed
{
	return Options::get(App::instance(), $key, $default);
}

/** `annai.active` is a real true. */
function annai_active(): bool
{
	return Options::active(App::instance());
}

$annaiTranslations = [];
foreach (I18n::LANGUAGES as $annaiLanguage) {
	foreach (I18n::table($annaiLanguage) as $annaiKey => $annaiText) {
		$annaiTranslations[$annaiLanguage]['annai.' . $annaiKey] = $annaiText;
	}
}
unset($annaiLanguage, $annaiKey, $annaiText);

App::plugin('sayamaapps/annai', [
	// All strings, also usable as t('annai.<key>').
	'translations' => $annaiTranslations,

	/**
	 * Head markup without styling. A site with its own markup creates
	 * site/snippets/annai/head.php — Kirby looks there first — or uses
	 * $page->annaiHead() and prints the tags itself.
	 */
	'snippets' => [
		'annai/head' => __DIR__ . '/snippets/annai/head.php',
	],

	/**
	 * Every route names GET|HEAD. Without `method` Kirby registers only GET,
	 * a HEAD falls through to the page lookup and ends as 404 — crawlers and
	 * checkers that look with HEAD first then believe the file is missing.
	 *
	 * Off → $this->next(): a site's own route or a real file still works.
	 */
	'routes' => [
		[
			'pattern' => 'robots.txt',
			'method'  => 'GET|HEAD',
			'action'  => function () {
				$kirby = App::instance();
				return Options::active($kirby) ? Routes::robots($kirby) : $this->next();
			},
		],
		[
			'pattern' => 'sitemap.xml',
			'method'  => 'GET|HEAD',
			'action'  => function () {
				$kirby = App::instance();
				return (Options::active($kirby) ? Routes::sitemap($kirby) : null) ?? $this->next();
			},
		],
		[
			// Only the canonical number: (:num) also took "01" and "0001", which
			// served chunk 1 under three URLs (duplicate content for crawlers).
			// The dot is escaped: unescaped it matched any character, and
			// "sitemap-1Xxml" served chunk 1 as well (Kirby's router takes "\.").
			'pattern' => 'sitemap-([1-9][0-9]*)\.xml',
			'method'  => 'GET|HEAD',
			'action'  => function (string $chunk) {
				$kirby = App::instance();
				return (Options::active($kirby) ? Routes::sitemap($kirby, (int)$chunk) : null) ?? $this->next();
			},
		],
		[
			'pattern' => 'llms.txt',
			'method'  => 'GET|HEAD',
			'action'  => function () {
				$kirby = App::instance();
				return Options::active($kirby) ? Routes::llms($kirby) : $this->next();
			},
		],
	],

	'siteMethods' => [
		/** Business facts (docs/SPEC.md §3.8), [] when off. */
		'annaiFacts' => function (): array {
			return Options::active($this->kirby()) ? Business::facts($this->kirby()) : [];
		},

		/** Opening-hour units from Noren (docs/SPEC.md §3.8), [] when off or without Noren. */
		'annaiUnits' => function (): array {
			return Options::active($this->kirby()) ? Business::units($this->kirby()) : [];
		},

		/** The robots.txt text, '' when off. */
		'annaiRobots' => function (): string {
			return Options::active($this->kirby()) ? Routes::robotsText($this->kirby()) : '';
		},

		/** Sitemap entries (docs/SPEC.md §3.9: loc, lastmod, alternates, page), [] when off. */
		'annaiSitemap' => function (): array {
			return Options::active($this->kirby()) ? Pages::entries($this->kirby()) : [];
		},

		/** The llms.txt text, '' when off. */
		'annaiLlms' => function (): string {
			return Options::active($this->kirby()) ? Routes::llmsText($this->kirby()) : '';
		},

		/** Upcoming Koyomi events for llms.txt (docs/SPEC.md §3.9), [] when off or without Koyomi. */
		'annaiEvents' => function (): array {
			return Options::active($this->kirby()) ? Pages::events($this->kirby()) : [];
		},

		/** Is the preview protection active (docs/SPEC.md §7)? false when off. */
		'annaiPreview' => function (): bool {
			return Options::active($this->kirby()) && Pages::preview($this->kirby());
		},
	],

	'pageMethods' => [
		/** Head model (docs/SPEC.md §3.7.1) for own markup, or null when off. */
		'annaiHead' => function (): array|null {
			$kirby = $this->kirby();
			if (!Options::active($kirby)) {
				return null;
			}
			return Head::build(Business::head($kirby, $this));
		},

		/** JSON-LD document for this page (business node on the home page plus hook nodes), or null. */
		'annaiJsonLd' => function (): array|null {
			$kirby = $this->kirby();
			return Options::active($kirby) ? Business::jsonLd($kirby, $this) : null;
		},

		/** In sitemap, llms.txt and the check? false when off. */
		'annaiIndexable' => function (): bool {
			$kirby = $this->kirby();
			return Options::active($kirby) && !Pages::excluded($kirby, $this);
		},

		/** The robots meta value ('noindex, follow' …) or null. */
		'annaiRobots' => function (): string|null {
			$kirby = $this->kirby();
			return Options::active($kirby) ? Pages::robots($kirby, $this) : null;
		},

		/** hreflang map code → URL (incl. x-default), [] unless multilang and annai.hreflang. */
		'annaiAlternates' => function (): array {
			$kirby = $this->kirby();
			return Options::active($kirby) ? Pages::alternates($kirby, $this) : [];
		},

		/** Sitemap lastmod (W3C datetime) or null. */
		'annaiLastmod' => function (): string|null {
			$kirby = $this->kirby();
			return Options::active($kirby) ? Pages::lastmod($kirby, $this) : null;
		},

		/** What engines and agents see on this page (docs/SPEC.md §3.11), or null when off. */
		'annaiCheck' => function (): array|null {
			$kirby = $this->kirby();
			return Options::active($kirby) ? Check::page($kirby, $this) : null;
		},
	],
]);
