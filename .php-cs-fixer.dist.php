<?php

// Kirby's rule set removes @package from class docblocks. This plugin keeps it,
// so that one rule is switched off.
return Kirby\PhpCs\Config::create()
	->setRules(['@Kirby/style:risky' => true, 'phpdoc_no_package' => false])
	->setFinder(
		PhpCsFixer\Finder::create()
			->exclude('vendor')
			->in(__DIR__)
	);
