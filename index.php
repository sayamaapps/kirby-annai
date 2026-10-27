<?php

use Kirby\Filesystem\F;

/**
 * Annai (案内 — guidance; the one who shows the way): everything search
 * engines and AI agents read from a Kirby 5 site. robots.txt, sitemap.xml and
 * llms.txt as routes (behind whatever protects the site before Kirby boots),
 * JSON-LD for the business, meta description, canonical, Open Graph, noindex
 * and hreflang in one head snippet.
 *
 * Off by default: without `'sayamaapps.annai.active' => true` every route falls through
 * to Kirby, every method returns null/[]/'' and the snippet prints nothing.
 *
 * Registration only: all logic lives in src/ (docs/SPEC.md §3.10).
 *
 * Defaults are passed as fallback values at each option call. The 'options'
 * block (config/options.php) declares every key with null, so that a 2.x
 * name still shows through it (Options::read()).
 *
 * The predecessor plugin (a site/plugins/ folder, see Check::OLD_PLUGIN_PROBLEM)
 * must NOT stay installed next to this one: both register the same route patterns, and Kirby
 * takes the first match in plugin load order (README, "Upgrading"). Nothing
 * here can detect that at load time without recursing into plugins(); the
 * check tool reports it instead (Check::site(), 'problems').
 */
F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

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
