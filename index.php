<?php

use Kirby\Cms\App;
use Kirby\Filesystem\F;
use Sayamaapps\Annai\Options;

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

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

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

Kirby::plugin(
	name: 'sayamaapps/annai',
	extends: [
		'options'      => require __DIR__ . '/config/options.php',
		'translations' => require __DIR__ . '/config/translations.php',
		'snippets'     => require __DIR__ . '/config/snippets.php',
		'routes'       => require __DIR__ . '/config/routes.php',
		'siteMethods'  => require __DIR__ . '/config/site-methods.php',
		'pageMethods'  => require __DIR__ . '/config/page-methods.php',
	],
	root: __DIR__
);
