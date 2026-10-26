<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The package classes load as they do in production: every src file is
// required once (the same paths the index.php of the plugin requires).
foreach (glob(dirname(__DIR__) . '/src/*.php') ?: [] as $file) {
	require_once $file;
}

require_once __DIR__ . '/helpers.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
