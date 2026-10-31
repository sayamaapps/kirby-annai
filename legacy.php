<?php

/**
 * Names of the 2.x API, kept for existing sites (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases and
 * the two 2.x functions only; no logic here. Nothing in this file reports a
 * deprecation at run time: the names are documented with @deprecated and the README.
 */

class_alias(\Sayamaapps\Annai\Business::class, 'Annai\\Business');
class_alias(\Sayamaapps\Annai\Check::class, 'Annai\\Check');
class_alias(\Sayamaapps\Annai\Head::class, 'Annai\\Head');
class_alias(\Sayamaapps\Annai\I18n::class, 'Annai\\I18n');
class_alias(\Sayamaapps\Annai\JsonLd::class, 'Annai\\JsonLd');
class_alias(\Sayamaapps\Annai\LlmsTxt::class, 'Annai\\LlmsTxt');
class_alias(\Sayamaapps\Annai\Options::class, 'Annai\\Options');
class_alias(\Sayamaapps\Annai\Pages::class, 'Annai\\Pages');
class_alias(\Sayamaapps\Annai\Robots::class, 'Annai\\Robots');
class_alias(\Sayamaapps\Annai\Routes::class, 'Annai\\Routes');
class_alias(\Sayamaapps\Annai\Sitemap::class, 'Annai\\Sitemap');
class_alias(\Sayamaapps\Annai\Text::class, 'Annai\\Text');

if (Kirby\Cms\Helpers::hasOverride('annai_option') === false) {
	/**
	 * Reads `sayamaapps.annai.<key>`, then `annai.<key>`. Same as Sayamaapps\Annai\Options::get(App::instance(), …).
	 *
	 * @deprecated 2.x name, use Sayamaapps\Annai\Options::get()
	 */
	function annai_option(string $key, mixed $default = null): mixed
	{
		return Sayamaapps\Annai\Options::get(Kirby\Cms\App::instance(), $key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('annai_active') === false) {
	/**
	 * Is Annai switched on? `sayamaapps.annai.active` (or `annai.active`) is a real true.
	 *
	 * @deprecated 2.x name, use Sayamaapps\Annai\Options::active()
	 */
	function annai_active(): bool
	{
		return Sayamaapps\Annai\Options::active(Kirby\Cms\App::instance());
	}
}
