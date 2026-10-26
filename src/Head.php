<?php

namespace Annai;

/**
 * The <head> model and its tags (docs/SPEC.md §3.7).
 *
 * The meta part of a page head: the Open Graph and twitter tags, and the
 * noindex switch. Nothing here decides
 * a value: Business::head() (§3.8) does, this class only normalises it and prints
 * it in a fixed order.
 */
final class Head
{
	/**
	 * Normalises the Business::head() input (docs/SPEC.md §3.7.1) into the model the
	 * snippet and own markup use. The model carries the tag strings too, as
	 * 'tags', computed from the model without that key.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(array $input): array
	{
		$title = (string)($input['title'] ?? '');
		$description = (string)($input['description'] ?? '');
		$canonical = (string)($input['canonical'] ?? '');
		$robots = self::text($input['robots'] ?? null);
		$ogType = self::text($input['ogType'] ?? null) ?? 'website';
		$siteName = (string)($input['siteName'] ?? '');
		$locale = self::text($input['locale'] ?? null);
		$twitterCard = self::text($input['twitterCard'] ?? null);
		$jsonld = is_array($input['jsonld'] ?? null) && $input['jsonld'] !== [] ? $input['jsonld'] : null;

		$image = is_array($input['image'] ?? null) ? $input['image'] : [];
		$imageUrl = (string)($image['url'] ?? '');
		if ($imageUrl === '') {
			// Width, height and alt describe an og:image; without one they describe nothing.
			$image = [];
		}

		$og = [
			'og:title'        => $title,
			'og:description'  => $description,
			'og:type'         => $ogType,
			'og:url'          => $canonical,
			'og:image'        => $imageUrl,
			'og:image:width'  => isset($image['width']) && $image['width'] !== null ? (string)$image['width'] : '',
			'og:image:height' => isset($image['height']) && $image['height'] !== null ? (string)$image['height'] : '',
			'og:image:alt'    => (string)($image['alt'] ?? ''),
			'og:site_name'    => $siteName,
			'og:locale'       => (string)$locale,
		];
		$og = array_filter($og, fn (string $value): bool => $value !== '');

		$alternates = [];
		foreach ((array)($input['alternates'] ?? []) as $code => $href) {
			if (is_string($href) && $href !== '') {
				$alternates[] = ['hreflang' => (string)$code, 'href' => $href];
			}
		}

		$model = [
			'title'       => $title,
			'description' => $description,
			'canonical'   => $canonical,
			'robots'      => $robots,
			'og'          => $og,
			'twitter'     => $twitterCard !== null ? ['twitter:card' => $twitterCard] : [],
			'alternates'  => $alternates,
			'jsonld'      => $jsonld,
		];
		$model['tags'] = self::tags($model);
		return $model;
	}

	private static function meta(string $attribute, string $name, string $content): string
	{
		return '<meta ' . $attribute . '="' . Text::esc($name) . '" content="' . Text::esc($content) . '">';
	}

	/** The tags joined by newlines with a final newline; '' when there are none. */
	public static function render(array $model, array $options = []): string
	{
		$tags = self::tags($model, $options);
		return $tags === [] ? '' : implode("\n", $tags) . "\n";
	}

	/**
	 * HTML tags in the order of docs/SPEC.md §3.7.1, one string each. Attribute
	 * values and the title text are escaped with Text::esc(); the JSON-LD block
	 * comes from JsonLd::script().
	 *
	 * Options: ['title' => false] drops the <title> element (the site prints
	 * its own). Missing model keys count as empty.
	 *
	 * @return list<string>
	 */
	public static function tags(array $model, array $options = []): array
	{
		$tags = [];
		$title = (string)($model['title'] ?? '');
		if (($options['title'] ?? true) !== false && $title !== '') {
			$tags[] = '<title>' . Text::esc($title) . '</title>';
		}

		$description = (string)($model['description'] ?? '');
		if ($description !== '') {
			$tags[] = self::meta('name', 'description', $description);
		}

		$robots = self::text($model['robots'] ?? null);
		if ($robots !== null) {
			$tags[] = self::meta('name', 'robots', $robots);
		}

		$canonical = (string)($model['canonical'] ?? '');
		if ($canonical !== '') {
			$tags[] = '<link rel="canonical" href="' . Text::esc($canonical) . '">';
		}

		foreach ((array)($model['alternates'] ?? []) as $alternate) {
			$tags[] = '<link rel="alternate" hreflang="' . Text::esc((string)$alternate['hreflang'])
				. '" href="' . Text::esc((string)$alternate['href']) . '">';
		}

		foreach ((array)($model['og'] ?? []) as $property => $value) {
			$tags[] = self::meta('property', (string)$property, (string)$value);
		}

		foreach ((array)($model['twitter'] ?? []) as $name => $value) {
			$tags[] = self::meta('name', (string)$name, (string)$value);
		}

		if (is_array($model['jsonld'] ?? null) && ($model['jsonld'] ?? []) !== []) {
			$tags[] = JsonLd::script($model['jsonld']);
		}

		return $tags;
	}

	/** A non-empty string, else null. */
	private static function text(mixed $value): string|null
	{
		return is_string($value) && $value !== '' ? $value : null;
	}
}
