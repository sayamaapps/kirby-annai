<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Head;

/** A complete Business::head() input. */
function head_full_input(): array
{
	return [
		'title'       => 'Hofladen · Testhof',
		'description' => 'Gemüse vom eigenen Feld.',
		'canonical'   => 'https://x/laden',
		'robots'      => 'noindex, follow',
		'ogType'      => 'website',
		'siteName'    => 'Testhof',
		'locale'      => 'de_DE',
		'image'       => ['url' => 'https://x/teilen.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Der Hof'],
		'twitterCard' => 'summary_large_image',
		'alternates'  => ['de' => 'https://x/de/laden', 'en' => 'https://x/en/laden', 'x-default' => 'https://x/de/laden'],
		'jsonld'      => ['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => 'Testhof'],
	];
}

/**
 * The <head> model and its tags (docs/SPEC.md §3.7, §9.6). Kirby-free.
 *   vendor/bin/phpunit tests/HeadTest.php
 */
#[CoversNothing]
final class HeadTest extends TestCase
{
	public function testSuite(): void
	{
		// --- build: model (§9.6) -------------------------------------------------

		$model = Head::build(head_full_input());
		expect(
			['title', 'description', 'canonical', 'robots', 'og', 'twitter', 'alternates', 'jsonld', 'tags'],
			array_keys($model),
			'build: model keys'
		);
		expect('Hofladen · Testhof', $model['title'], 'build: title kept');
		expect('https://x/laden', $model['canonical'], 'build: canonical kept');
		expect('noindex, follow', $model['robots'], 'build: robots kept');
		expect(
			[
				'og:title'        => 'Hofladen · Testhof',
				'og:description'  => 'Gemüse vom eigenen Feld.',
				'og:type'         => 'website',
				'og:url'          => 'https://x/laden',
				'og:image'        => 'https://x/teilen.jpg',
				'og:image:width'  => '1200',
				'og:image:height' => '630',
				'og:image:alt'    => 'Der Hof',
				'og:site_name'    => 'Testhof',
				'og:locale'       => 'de_DE',
			],
			$model['og'],
			'build: og exact, width and height as strings, og:url = canonical'
		);
		expect(['twitter:card' => 'summary_large_image'], $model['twitter'], 'build: twitter card');
		expect(
			[
				['hreflang' => 'de', 'href' => 'https://x/de/laden'],
				['hreflang' => 'en', 'href' => 'https://x/en/laden'],
				['hreflang' => 'x-default', 'href' => 'https://x/de/laden'],
			],
			$model['alternates'],
			'build: alternates as hreflang rows in input order'
		);
		expect($model['jsonld'], ['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => 'Testhof'], 'build: jsonld kept');
		$sparse = Head::build([
			'title'       => 'Kontakt · Testhof',
			'description' => '',
			'canonical'   => 'https://x/kontakt',
			'robots'      => null,
			'siteName'    => '',
			'locale'      => null,
			'image'       => null,
			'twitterCard' => null,
			'alternates'  => [],
			'jsonld'      => null,
		]);
		expect(
			['og:title' => 'Kontakt · Testhof', 'og:type' => 'website', 'og:url' => 'https://x/kontakt'],
			$sparse['og'],
			'build: og only has non-empty keys'
		);
		expect([], $sparse['twitter'], 'build: no twitter card when null');
		expect([], $sparse['alternates'], 'build: no alternates');
		expect(null, $sparse['jsonld'], 'build: no jsonld gives null');
		expect(null, $sparse['robots'], 'build: robots null kept as null');
		expect('', $sparse['description'], 'build: empty description kept empty');
		expect(Head::tags($sparse), $sparse['tags'], 'build: tags key is the tags() result');
		$imageNoSize = Head::build(array_merge(head_full_input(), ['image' => ['url' => 'https://x/a.jpg', 'width' => null, 'height' => null, 'alt' => '']]));
		holds(!array_key_exists('og:image:width', $imageNoSize['og']), 'build: no width key when width is null');
		holds(!array_key_exists('og:image:alt', $imageNoSize['og']), 'build: no alt key when alt is empty');
		expect('https://x/a.jpg', $imageNoSize['og']['og:image'] ?? null, 'build: og:image without size');
		// og:image:width/height/alt describe an og:image: without a URL they describe nothing.
		$imageNoUrl = Head::build(array_merge(head_full_input(), ['image' => ['url' => '', 'width' => 1200, 'height' => 630, 'alt' => 'Der Hof']]));
		expect(
			[],
			array_filter($imageNoUrl['og'], fn (string $key): bool => str_starts_with($key, 'og:image'), ARRAY_FILTER_USE_KEY),
			'build: no og:image:* keys when the image has no URL'
		);
		holds(!str_contains(Head::render($imageNoUrl), 'og:image'), 'render: no og:image tag at all without a URL');
		// --- tags: order and exact strings (§9.6) --------------------------------

		$expected = [
			'<title>Hofladen · Testhof</title>',
			'<meta name="description" content="Gemüse vom eigenen Feld.">',
			'<meta name="robots" content="noindex, follow">',
			'<link rel="canonical" href="https://x/laden">',
			'<link rel="alternate" hreflang="de" href="https://x/de/laden">',
			'<link rel="alternate" hreflang="en" href="https://x/en/laden">',
			'<link rel="alternate" hreflang="x-default" href="https://x/de/laden">',
			'<meta property="og:title" content="Hofladen · Testhof">',
			'<meta property="og:description" content="Gemüse vom eigenen Feld.">',
			'<meta property="og:type" content="website">',
			'<meta property="og:url" content="https://x/laden">',
			'<meta property="og:image" content="https://x/teilen.jpg">',
			'<meta property="og:image:width" content="1200">',
			'<meta property="og:image:height" content="630">',
			'<meta property="og:image:alt" content="Der Hof">',
			'<meta property="og:site_name" content="Testhof">',
			'<meta property="og:locale" content="de_DE">',
			'<meta name="twitter:card" content="summary_large_image">',
			'<script type="application/ld+json">{"@context":"https://schema.org","@type":"Thing","name":"Testhof"}</script>',
		];
		expect($expected, Head::tags($model), 'tags: full model, order and exact strings');
		expect(
			array_values(array_filter($expected, fn (string $tag): bool => !str_starts_with($tag, '<title>'))),
			Head::tags($model, ['title' => false]),
			'tags: title false drops only the <title>'
		);
		expect(
			['<link rel="canonical" href="https://x/kontakt">', '<meta property="og:title" content="Kontakt · Testhof">', '<meta property="og:type" content="website">', '<meta property="og:url" content="https://x/kontakt">'],
			Head::tags($sparse, ['title' => false]),
			'tags: sparse model with title option false (no <title>, og:title kept)'
		);
		expect(['<title>Kontakt · Testhof</title>', '<link rel="canonical" href="https://x/kontakt">', '<meta property="og:title" content="Kontakt · Testhof">', '<meta property="og:type" content="website">', '<meta property="og:url" content="https://x/kontakt">'], Head::tags($sparse), 'tags: sparse model with title');
		// --- tags: escaping (§9.6) -----------------------------------------------

		$escaped = Head::build(array_merge(head_full_input(), [
			'title'       => 'A "B" & <C>',
			'description' => 'Text mit </script> darin',
			'canonical'   => 'https://x/a?b=1&c=2',
		]));
		$escapedTags = Head::tags($escaped, ['title' => true]);
		expect('<title>A &quot;B&quot; &amp; &lt;C&gt;</title>', $escapedTags[0], 'escape: title text');
		expect('<meta name="description" content="Text mit &lt;/script&gt; darin">', $escapedTags[1], 'escape: </script> in description attribute');
		expect('<link rel="canonical" href="https://x/a?b=1&amp;c=2">', $escapedTags[3], 'escape: & in canonical href');
		holds(!str_contains(implode('', $escapedTags), '</script> darin'), 'escape: no raw </script> outside the JSON-LD block');
		// --- render (§9.6) -------------------------------------------------------

		expect(implode("\n", $expected) . "\n", Head::render($model), 'render: lines joined by newline, final newline');
		expect("<link rel=\"canonical\" href=\"https://x/\">\n", Head::render(['canonical' => 'https://x/']), 'render: raw model with canonical only is one line');
		expect(
			"<title>Startseite</title>\n<link rel=\"canonical\" href=\"https://x/\">\n",
			Head::render(['title' => 'Startseite', 'canonical' => 'https://x/'], ['title' => true]),
			'render: title and canonical, two lines'
		);
		expect('', Head::render([]), 'render: empty model renders nothing');
		expect('', Head::render(['title' => 'Nur Titel'], ['title' => false]), 'render: title false with nothing else renders nothing');
	}
}
