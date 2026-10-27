<?php

use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\Check;
use Sayamaapps\Annai\Head;
use Sayamaapps\Annai\Pages;

// Page methods. $this is the page. Each closure hands over to src/ (the class owns the Options check).
return [
	/** Head model (docs/SPEC.md §3.7.1) for own markup, or null when off. */
	'annaiHead' => function (): array|null {
		return Head::forPage($this->kirby(), $this);
	},

	/** JSON-LD document for this page (business node on the home page plus hook nodes), or null. */
	'annaiJsonLd' => function (): array|null {
		return Business::jsonLd($this->kirby(), $this);
	},

	/** In sitemap, llms.txt and the check? false when off. */
	'annaiIndexable' => function (): bool {
		return Pages::indexableWhenOn($this->kirby(), $this);
	},

	/** The robots meta value ('noindex, follow' …) or null. */
	'annaiRobots' => function (): string|null {
		return Pages::robotsWhenOn($this->kirby(), $this);
	},

	/** hreflang map code → URL (incl. x-default), [] unless multilang and annai.hreflang. */
	'annaiAlternates' => function (): array {
		return Pages::alternatesWhenOn($this->kirby(), $this);
	},

	/** Sitemap lastmod (W3C datetime) or null. */
	'annaiLastmod' => function (): string|null {
		return Pages::lastmodWhenOn($this->kirby(), $this);
	},

	/** What engines and agents see on this page (docs/SPEC.md §3.11), or null when off. */
	'annaiCheck' => function (): array|null {
		return Check::pageWhenOn($this->kirby(), $this);
	},
];
