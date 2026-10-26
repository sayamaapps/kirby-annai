<?php

/**
 * Class map for Kirby's F::loadClasses() (AUT-2). Keys are lowercase class names,
 * values are paths relative to the plugin root. tests/AutoloadTest.php checks the
 * map against src/.
 */

return [
	'sayamaapps\\annai\\business' => 'src/Business.php',
	'sayamaapps\\annai\\check' => 'src/Check.php',
	'sayamaapps\\annai\\head' => 'src/Head.php',
	'sayamaapps\\annai\\i18n' => 'src/I18n.php',
	'sayamaapps\\annai\\jsonld' => 'src/JsonLd.php',
	'sayamaapps\\annai\\llmstxt' => 'src/LlmsTxt.php',
	'sayamaapps\\annai\\options' => 'src/Options.php',
	'sayamaapps\\annai\\pages' => 'src/Pages.php',
	'sayamaapps\\annai\\robots' => 'src/Robots.php',
	'sayamaapps\\annai\\routes' => 'src/Routes.php',
	'sayamaapps\\annai\\sitemap' => 'src/Sitemap.php',
	'sayamaapps\\annai\\text' => 'src/Text.php',
];
