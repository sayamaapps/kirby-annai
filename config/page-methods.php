<?php

use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\Check;
use Sayamaapps\Annai\Head;
use Sayamaapps\Annai\Options;
use Sayamaapps\Annai\Pages;

// Page methods. $this is the page.
return [
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
];
