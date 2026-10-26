<?php

// Snippets of the plugin. A site's own copy under site/snippets/annai/head.php
// wins: Kirby looks there first.
return [
	/**
	 * Head markup without styling. A site with its own markup creates
	 * site/snippets/annai/head.php — Kirby looks there first — or uses
	 * $page->annaiHead() and prints the tags itself.
	 */
	'annai/head' => dirname(__DIR__) . '/snippets/annai/head.php',
];
