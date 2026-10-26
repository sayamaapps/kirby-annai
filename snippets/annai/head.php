<?php
/**
 * Head markup of Annai (docs/SPEC.md §3.7.3): title, description, robots,
 * canonical, hreflang, Open Graph, twitter card and JSON-LD. Head markup only:
 * no charset, viewport, theme colour, icons, fonts or stylesheets, and no
 * CSS classes. Those stay in the site's own <head>.
 *
 * Usage:
 *   <?php snippet('annai/head') ?>                              uses $page->annaiHead()
 *   <?php snippet('annai/head', ['title' => false]) ?>          the site prints its own <title>
 *   <?php snippet('annai/head', ['head' => $model]) ?>          an explicit model (Head::build())
 *
 * Prints nothing when there is no model, i.e. when Annai is off. A site that
 * needs other markup copies this file to site/snippets/annai/head.php; Kirby
 * takes the site's copy first.
 */
$model = $head ?? (isset($page) ? $page->annaiHead() : null);
if ($model === null) {
	return;
}
echo Annai\Head::render($model, ['title' => ($title ?? true) !== false]);
