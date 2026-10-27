<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements without PHP symbols the tool can match: the PHP version, the
// mbstring and JSON extensions (mb_* and json_* calls), the installer that places
// the plugin in site/plugins, and libphonenumber, whose namespace the package
// does not map (the phone code in src/Text.php uses it, guarded by class_exists).
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('ext-mbstring'))
		->addNamedFilter(NamedFilter::fromString('ext-json'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'))
		->addNamedFilter(NamedFilter::fromString('giggsey/libphonenumber-for-php-lite'));
};
