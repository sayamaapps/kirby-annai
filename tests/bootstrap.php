<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The plugin loads as Kirby loads it: index.php registers the plugin, and the
// class map (autoload.php) that it loads serves the classes, as in production.
require_once dirname(__DIR__) . '/index.php';

require_once __DIR__ . '/helpers.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
