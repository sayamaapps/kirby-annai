<?php

use Kirby\Cms\App;
use Sayamaapps\Annai\Options;
use Sayamaapps\Annai\Routes;

/**
 * Every route names GET|HEAD. Without `method` Kirby registers only GET,
 * a HEAD falls through to the page lookup and ends as 404 — crawlers and
 * checkers that look with HEAD first then believe the file is missing.
 *
 * Off → $this->next(): a site's own route or a real file still works.
 */
return [
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
			return (Options::active($kirby) ? Routes::sitemap($kirby, (int) $chunk) : null) ?? $this->next();
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
];
