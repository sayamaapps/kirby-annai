<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

const SNIPPET_TEST_HTML = <<<'HTML'
<title>Hofladen · Testhof</title>
<meta name="description" content="Gemüse vom eigenen Feld.">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="https://x/laden">
<meta property="og:title" content="Hofladen · Testhof">
<meta property="og:description" content="Gemüse vom eigenen Feld.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://x/laden">
<meta property="og:site_name" content="Testhof">
<meta property="og:locale" content="de_DE">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Thing","name":"Testhof"}</script>

HTML;

/** The explicit model used by most checks, built by the plugin's own Head class. */
function snippet_test_model(): array
{
	return Sayamaapps\Annai\Head::build([
		'title'       => 'Hofladen · Testhof',
		'description' => 'Gemüse vom eigenen Feld.',
		'canonical'   => 'https://x/laden',
		'robots'      => 'noindex, follow',
		'ogType'      => 'website',
		'siteName'    => 'Testhof',
		'locale'      => 'de_DE',
		'twitterCard' => 'summary_large_image',
		'jsonld'      => ['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => 'Testhof'],
	]);
}

/**
 * snippets/annai/head.php rendered by a booted Kirby (docs/SPEC.md §3.7.3, §9.7).
 * Kirby loads the plugin through annai_site(); no src file is required here.
 *   vendor/bin/phpunit tests/SnippetTest.php
 */
#[CoversNothing]
final class SnippetTest extends TestCase
{
	public function testSuite(): void
	{
		// --- Annai off -----------------------------------------------------------

		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, []);
			expect('', $kirby->snippet('annai/head', [], true), 'off: snippet without model prints nothing');
			expect('', $kirby->snippet('annai/head', ['head' => null], true), 'off: explicit null model prints nothing');
			[$code, $body] = annai_request($kirby, '/');
			expect(200, $code, 'off: home page still renders');
			holds(!str_contains($body, '<title>'), 'off: home page has no annai <title>');
			holds(!str_contains($body, 'application/ld+json'), 'off: home page has no JSON-LD');
		} finally {
			annai_remove_tree($tmp);
		}
		// --- explicit model (the snippet does not depend on Business) ------------

		$tmp = annai_site();
		try {
			$kirby = annai_kirby($tmp, []);
			$html = $kirby->snippet('annai/head', ['head' => snippet_test_model()], true);
			expect(SNIPPET_TEST_HTML, $html, 'explicit model: snippet prints Head::render() output exactly');
			expect(
				Sayamaapps\Annai\Head::render(snippet_test_model(), ['title' => true]),
				$html,
				'explicit model: same string as Head::render()'
			);
			$noTitle = $kirby->snippet('annai/head', ['head' => snippet_test_model(), 'title' => false], true);
			holds(!str_contains($noTitle, '<title>'), 'title false: no <title>');
			expect(
				substr(SNIPPET_TEST_HTML, strpos(SNIPPET_TEST_HTML, "\n") + 1),
				$noTitle,
				'title false: everything else unchanged'
			);
			expect(
				'<link rel="canonical" href="https://x/">' . "\n",
				$kirby->snippet('annai/head', ['head' => ['canonical' => 'https://x/']], true),
				'explicit model without og: canonical and og keys from build only'
			);
		} finally {
			annai_remove_tree($tmp);
		}
		// --- site override wins -------------------------------------------------

		$tmp = annai_site();
		try {
			@mkdir($tmp . '/site/snippets/annai', 0700, true);
			file_put_contents($tmp . '/site/snippets/annai/head.php', "<meta name=\"own-head\" content=\"<?= \$head['title'] ?? 'none' ?>\">\n");
			$kirby = annai_kirby($tmp, []);
			expect(
				"<meta name=\"own-head\" content=\"Hofladen · Testhof\">\n",
				$kirby->snippet('annai/head', ['head' => snippet_test_model()], true),
				'override: site/snippets/annai/head.php replaces the plugin snippet'
			);
		} finally {
			annai_remove_tree($tmp);
		}
		// --- through a page: $page->annaiHead() on the home page -----------------

		$tmp = annai_site();
		try {
			annai_page($tmp, '', 'site', [
				'title'        => 'Testhof',
				'marke'        => 'Testhof',
				'beschreibung' => 'Bio-Hofladen mit Café',
				'strasse'      => 'Musterweg 1',
				'plz'          => '12345',
				'ort'          => 'Musterstadt',
				'telefon'      => '030 / 34567000',
			]);
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.active' => true]);
			[$code, $body] = annai_request($kirby, '/');
			expect(200, $code, 'page: home page renders');
			holds(str_contains($body, '<head><title>Testhof</title>' . "\n" . '<meta name="description" content="Bio-Hofladen mit Café">'), 'page: title and description tags from $page->annaiHead()');
			holds(str_contains($body, '<link rel="canonical" href="http://example.invalid">'), 'page: canonical tag');
			holds(str_contains($body, '<meta property="og:site_name" content="Testhof">'), 'page: og:site_name tag');
			$blocks = annai_jsonld($body);
			expect(1, count($blocks), 'page: exactly one JSON-LD block on the home page');
			expect('LocalBusiness', $blocks[0]['@type'] ?? null, 'page: business node type');
			expect('Testhof', $blocks[0]['name'] ?? null, 'page: business node name');
			expect('+493034567000', $blocks[0]['telephone'] ?? null, 'page: telephone in E.164');
			expect('http://example.invalid/#business', $blocks[0]['@id'] ?? null, 'page: business @id');
		} finally {
			annai_remove_tree($tmp);
		}
		// --- through a page with a site template that prints its own title -------

		$tmp = annai_site();
		try {
			file_put_contents($tmp . '/site/templates/home.php', '<html><head><title>Site title</title><?php snippet("annai/head", ["title" => false]) ?></head><body>Fixture</body></html>');
			annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof']);
			$kirby = annai_kirby($tmp, ['sayamaapps.annai.active' => true]);
			[, $body] = annai_request($kirby, '/');
			expect(1, substr_count($body, '<title>'), 'title false in a template: only the site title is printed');
			holds(str_contains($body, '<title>Site title</title>'), 'title false in a template: the site title stays');
			holds(str_contains($body, '<link rel="canonical" href="http://example.invalid">'), 'title false in a template: canonical still rendered');
			holds(str_contains($body, '<meta property="og:title" content="Testhof">'), 'title false in a template: og:title still rendered');
		} finally {
			annai_remove_tree($tmp);
		}
	}
}
