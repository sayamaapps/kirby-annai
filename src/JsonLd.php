<?php

namespace Sayamaapps\Annai;

/**
 * schema.org arrays and JSON-LD encoding (docs/SPEC.md §3.6).
 *
 * The business node and its encoding. Nothing is typed
 * twice: every value comes from Business::facts() (§3.8). Empty values are
 * removed by Text::prune(), so an empty email or a missing geo point never
 * reaches the output (an empty string is a wrong statement for an agent).
 *
 * @package   Kirby Annai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-annai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class JsonLd
{
	public const CONTEXT = 'https://schema.org';

	/**
	 * JSON_HEX_TAG keeps a "</script>" inside a value from closing the block,
	 * JSON_HEX_AMP does the same for "&" in HTML attributes and contexts that
	 * read the text as markup. JSON_INVALID_UTF8_SUBSTITUTE turns a byte that
	 * is not UTF-8 (a Latin-1 "é" left over from an import) into
	 * U+FFFD instead of throwing: one bad byte in a field must not take the
	 * home page down. INF, NAN and too deep nesting still throw.
	 */
	public const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

	/**
	 * PostalAddress from the facts, null when street and city are both empty.
	 *
	 * @return array<string, string>|null
	 */
	private static function address(array $facts): array|null
	{
		$street = (string)($facts['street'] ?? '');
		$city = (string)($facts['city'] ?? '');
		if ($street === '' && $city === '') {
			return null;
		}
		return [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street,
			'postalCode'      => (string)($facts['postalCode'] ?? ''),
			'addressLocality' => $city,
			'addressCountry'  => (string)($facts['country'] ?? ''),
		];
	}

	/**
	 * Wraps nodes into one document. [] → null. One node → ['@context' => CONTEXT] + node.
	 * Several → ['@context' => CONTEXT, '@graph' => nodes], inner @context keys removed.
	 * Nodes that are not arrays or are [] are dropped first.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function document(array $nodes): array|null
	{
		$kept = [];
		foreach ($nodes as $node) {
			if (is_array($node) && $node !== []) {
				unset($node['@context']);
				$kept[] = $node;
			}
		}
		if ($kept === []) {
			return null;
		}
		if (count($kept) === 1) {
			return ['@context' => self::CONTEXT] + $kept[0];
		}
		return ['@context' => self::CONTEXT, '@graph' => $kept];
	}

	/** json_encode with self::FLAGS; throws JsonException on failure. */
	public static function encode(array $document): string
	{
		return json_encode($document, self::FLAGS | JSON_THROW_ON_ERROR);
	}

	/**
	 * Fragment of a department's @id, null when the unit has neither an area nor
	 * a location. No two units of one document get the same one:
	 *
	 * - `cafe`, the bare area key, for a unit at the business (Business::units()
	 *   says so with `atBusiness`: no address of its own, or the business page
	 *   itself). Not when the key equals the business node's fragment
	 *   (`entityId`) or holds ":", then the unit takes the `area:` form.
	 * - `loc:standort/b` or `loc:standort/b:praxis`, for a unit at another
	 *   location (or one without an area): "loc:", the location's page id with
	 *   "/" kept, and ":" plus the area key when there is one.
	 * - `area:business`, "area:" and the key, for a unit at the business whose
	 *   key may not stay bare.
	 *
	 * Page id and key are percent-encoded part by part, so ":" only separates
	 * the parts (an area key is free text and a page folder may be named
	 * "a:b"). A bare key holds no ":", "loc:" and "area:" differ, and the number
	 * of ":" tells "loc:<id>" from "loc:<id>:<area>". Only an `entityId` that
	 * starts with "loc:" or "area:" could still meet a department; the
	 * reference says not to set one.
	 */
	private static function fragment(array $unit, string $entityId): string|null
	{
		$area = is_string($unit['area'] ?? null) && $unit['area'] !== '' ? $unit['area'] : null;
		$location = is_string($unit['location'] ?? null) && $unit['location'] !== '' ? $unit['location'] : null;
		$atBusiness = ($unit['atBusiness'] ?? !is_array($unit['place'] ?? null)) === true;
		if ($area !== null && ($atBusiness || $location === null)) {
			return !str_contains($area, ':') && $area !== $entityId ? $area : 'area:' . self::part($area);
		}
		if ($location === null) {
			return null;
		}
		return 'loc:' . self::part($location, true) . ($area !== null ? ':' . self::part($area) : '');
	}

	/**
	 * The business node (no @context), see docs/SPEC.md §3.6. [] when the business
	 * has no schema.org type (type false) or no name.
	 *
	 * @return array<string, mixed>
	 */
	public static function localBusiness(array $facts): array
	{
		$type = $facts['type'] ?? false;
		$name = (string)($facts['name'] ?? '');
		if ($type === false || $name === '') {
			return [];
		}

		$url = (string)($facts['url'] ?? '');
		$entityId = (string)($facts['entityId'] ?? 'business');
		$address = self::address($facts);

		$primary = null;
		$departments = [];
		foreach ((array)($facts['units'] ?? []) as $unit) {
			if (!is_array($unit)) {
				continue;
			}
			if (($unit['primary'] ?? false) === true) {
				$primary ??= $unit;
				continue;
			}
			$fragment = self::fragment($unit, $entityId);
			if ($fragment === null) {
				continue;
			}
			$place = is_array($unit['place'] ?? null) ? $unit['place'] : null;
			$unitName = (string)($unit['name'] ?? '');
			$departments[] = [
				'@type'                            => $type,
				'@id'                              => $url . '/#' . $fragment,
				'name'                             => $unitName !== '' ? $name . ' — ' . $unitName : $unitName,
				// A unit at another location has its own address and phone, never the main one.
				'address'                          => $place !== null ? self::address($place + ['country' => $facts['country'] ?? '']) : $address,
				'telephone'                        => $place !== null ? ($place['phoneE164'] ?? null) : ($facts['phoneE164'] ?? null),
				'openingHoursSpecification'        => $unit['schema']['openingHoursSpecification'] ?? [],
				'specialOpeningHoursSpecification' => $unit['schema']['specialOpeningHoursSpecification'] ?? [],
			];
		}

		$geo = $facts['geo'] ?? null;
		$action = $facts['action'] ?? null;
		$sameAs = [];
		foreach ((array)($facts['sameAs'] ?? []) as $link) {
			if (is_string($link) && $link !== '') {
				$sameAs[] = $link;
			}
		}

		$node = [
			'@type'                            => $type,
			'@id'                              => $url . '/#' . $entityId,
			'name'                             => $name,
			'description'                      => $facts['description'] ?? null,
			'url'                              => $url,
			'image'                            => $facts['image'] ?? null,
			'telephone'                        => $facts['phoneE164'] ?? null,
			'faxNumber'                        => $facts['faxE164'] ?? null,
			'email'                            => $facts['email'] ?? null,
			'address'                          => $address,
			'geo'                              => is_array($geo) ? [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float)$geo['lat'],
				'longitude' => (float)$geo['lng'],
			] : null,
			'openingHoursSpecification'        => $primary['schema']['openingHoursSpecification'] ?? [],
			'specialOpeningHoursSpecification' => $primary['schema']['specialOpeningHoursSpecification'] ?? [],
			'sameAs'                           => $sameAs,
			'department'                       => $departments,
			'potentialAction'                  => is_array($action) && (string)($action['target'] ?? '') !== '' ? [
				'@type'  => $action['type'] ?? 'Action',
				'name'   => $action['name'] ?? null,
				'target' => ['@type' => 'EntryPoint', 'urlTemplate' => (string)$action['target']],
			] : null,
		];

		return Text::prune($node);
	}

	/**
	 * Percent-encodes one part of a fragment (RFC 3986). The result holds no ":",
	 * so ":" can separate the parts; rawurlencode() is injective ("%" becomes
	 * "%25"). "/" is encoded unless $keepSlash is true (a page id).
	 */
	private static function part(string $text, bool $keepSlash = false): string
	{
		$encoded = rawurlencode($text);
		return $keepSlash ? str_replace('%2F', '/', $encoded) : $encoded;
	}

	/** The complete script element for the document. */
	public static function script(array $document): string
	{
		return '<script type="application/ld+json">' . self::encode($document) . '</script>';
	}
}
