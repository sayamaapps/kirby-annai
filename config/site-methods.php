<?php

use Sayamaapps\Annai\Business;
use Sayamaapps\Annai\Pages;
use Sayamaapps\Annai\Routes;

// Site methods. $this is the site. Each closure hands over to src/; the callee returns the off value itself.
return [
	/** Business facts (docs/SPEC.md §3.8), [] when off. */
	'annaiFacts' => function (): array {
		return Business::facts($this->kirby());
	},

	/** Opening-hour units from Noren (docs/SPEC.md §3.8), [] when off or without Noren. */
	'annaiUnits' => function (): array {
		return Business::units($this->kirby());
	},

	/** The robots.txt text, '' when off. */
	'annaiRobots' => function (): string {
		return Routes::robotsTextWhenOn($this->kirby());
	},

	/** Sitemap entries (docs/SPEC.md §3.9: loc, lastmod, alternates, page), [] when off. */
	'annaiSitemap' => function (): array {
		return Pages::entries($this->kirby());
	},

	/** The llms.txt text, '' when off. */
	'annaiLlms' => function (): string {
		return Routes::llmsTextWhenOn($this->kirby());
	},

	/** Upcoming Koyomi events for llms.txt (docs/SPEC.md §3.9), [] when off or without Koyomi. */
	'annaiEvents' => function (): array {
		return Pages::events($this->kirby());
	},

	/** Is the preview protection active (docs/SPEC.md §7)? false when off. */
	'annaiPreview' => function (): bool {
		return Pages::previewWhenOn($this->kirby());
	},
];
