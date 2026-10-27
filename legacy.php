<?php

/**
 * Names of the 2.x API, kept until 3.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases only;
 * no logic here.
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
	/** Reads `sayamaapps.annai.<key>` (2.x: `annai.<key>`). Deprecated: use Sayamaapps\Annai\Options::get(). */
	function annai_option(string $key, mixed $default = null): mixed
	{
		static $reported = false;
		$reported = $reported || Kirby\Cms\Helpers::deprecated(
			'annai_option() is deprecated, use Sayamaapps\Annai\Options::get()',
			'annai-function'
		);

		return Sayamaapps\Annai\Options::get(Kirby\Cms\App::instance(), $key, $default);
	}
}

if (Kirby\Cms\Helpers::hasOverride('annai_active') === false) {
	/** Is Annai switched on? Deprecated: use Sayamaapps\Annai\Options::active(). */
	function annai_active(): bool
	{
		static $reported = false;
		$reported = $reported || Kirby\Cms\Helpers::deprecated(
			'annai_active() is deprecated, use Sayamaapps\Annai\Options::active()',
			'annai-function'
		);

		return Sayamaapps\Annai\Options::active(Kirby\Cms\App::instance());
	}
}
