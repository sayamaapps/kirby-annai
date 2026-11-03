<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\JsonLd;

/** The Testhof facts (docs/SPEC.md §3.8 shape): two units, the cafe is not primary. */
function jsonld_testhof_facts(): array
{
	return [
		'type'        => 'GroceryStore',
		'name'        => 'Testhof',
		'tagline'     => '',
		'description' => 'Bio-Hofladen mit Café',
		'url'         => 'https://x',
		'image'       => null,
		'phone'       => '030 / 34567000',
		'phoneE164'   => '+493034567000',
		'fax'         => '',
		'faxE164'     => '',
		'email'       => '',
		'street'      => 'Musterweg 1',
		'postalCode'  => '12345',
		'city'        => 'Musterstadt',
		'country'     => 'DE',
		'geo'         => null,
		'sameAs'      => ['https://www.instagram.com/testhof'],
		'action'      => [
			'type'   => 'OrderAction',
			'name'   => 'Online bestellen',
			'target' => 'https://shop.example/testhof',
		],
		'entityId'    => 'business',
		'units'       => [
			[
				'area'    => 'laden',
				'name'    => 'Hofladen',
				'primary' => true,
				'days'    => [],
				'schema'  => [
					'openingHoursSpecification' => [
						['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['https://schema.org/Monday'], 'opens' => '08:00', 'closes' => '12:00'],
					],
					'specialOpeningHoursSpecification' => [],
				],
			],
			[
				'area'    => 'cafe',
				'name'    => 'Hofcafé',
				'primary' => false,
				'days'    => [],
				'schema'  => [
					'openingHoursSpecification' => [
						['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['https://schema.org/Sunday'], 'opens' => '10:00', 'closes' => '17:00'],
					],
					'specialOpeningHoursSpecification' => [],
				],
			],
		],
	];
}

/**
 * JSON-LD arrays and encoding (docs/SPEC.md §3.6, §9.5). Kirby-free.
 *   vendor/bin/phpunit tests/JsonLdTest.php
 */
#[CoversClass(JsonLd::class)]
final class JsonLdTest extends TestCase
{
	/** The exact forms, one per case. */
	public function testDepartmentIdForms(): void
	{
		$facts = jsonld_testhof_facts();
		$facts['units'] = [
			['area' => 'laden', 'name' => 'Laden', 'primary' => true, 'schema' => []],
			['area' => 'cafe', 'location' => 'kontakt', 'atBusiness' => true, 'name' => 'Cafe', 'primary' => false, 'schema' => []],
			['area' => 'business', 'name' => 'Bus', 'primary' => false, 'schema' => []],
			['area' => 'x:y', 'name' => 'Bus', 'primary' => false, 'schema' => []],
			['area' => 'cafe', 'location' => 'a/b', 'atBusiness' => false, 'name' => 'A', 'primary' => false, 'schema' => [], 'place' => ['street' => 'Weg 1', 'city' => 'Ort']],
			['area' => 'b:c', 'location' => 'a:d/ü', 'atBusiness' => false, 'name' => 'B', 'primary' => false, 'schema' => [], 'place' => ['street' => 'Weg 1', 'city' => 'Ort']],
			['area' => null, 'location' => 'a/b', 'atBusiness' => false, 'name' => 'C', 'primary' => false, 'schema' => [], 'place' => ['street' => 'Weg 1', 'city' => 'Ort']],
		];
		expect(
			[
				'https://x/#cafe',
				'https://x/#area:business',
				'https://x/#area:x%3Ay',
				'https://x/#loc:a/b:cafe',
				'https://x/#loc:a%3Ad/%C3%BC:b%3Ac',
				'https://x/#loc:a/b',
			],
			array_column(JsonLd::localBusiness($facts)['department'], '@id'),
			'localBusiness: bare key at the business, area: for the entityId and for a ":", loc: for another location (page id with "/", parts percent-encoded)'
		);
		expect('https://x/#business', JsonLd::localBusiness($facts)['@id'], 'localBusiness: the business node keeps #entityId');
	}

	/**
	 * Department @ids (docs/reference.md, JSON-LD business node): the bare area key at
	 * the business, `loc:` plus the page id for another location, `area:` for a key
	 * that may not stay bare. Every combination of the parts below gives its own @id,
	 * next to the business node's, also when a part holds ":", "%", "/" or "loc:".
	 */
	public function testDepartmentIdsAreUnique(): void
	{
		$parts = ['a', 'b', 'a-b', 'a/b', 'a:b', 'b:a', 'a%3Ab', 'loc', 'loc:a', 'area:a', 'business', 'betrieb', 'é', 'a b'];
		foreach (['business', 'betrieb', 'a'] as $entityId) {
			$facts = jsonld_testhof_facts();
			$facts['entityId'] = $entityId;
			$facts['units'] = [];
			foreach ($parts as $area) {
				// At the business: no location page to tell the unit by.
				$facts['units'][] = ['area' => $area, 'name' => 'B', 'primary' => false, 'schema' => []];
				foreach ([...$parts, null] as $key) {
					// At another location: $area is the location's page id here, $key the area.
					$facts['units'][] = ['area' => $key, 'location' => $area, 'atBusiness' => false, 'name' => 'L', 'primary' => false, 'schema' => [],
						'place' => ['street' => 'Nordweg 3', 'city' => 'Musterstadt']];
				}
			}
			$node = JsonLd::localBusiness($facts);
			$ids = array_merge([$node['@id']], array_column($node['department'], '@id'));
			expect(1 + count($facts['units']), count($ids), "entityId $entityId: every unit has a department");
			expect(count($ids), count(array_unique($ids)), "entityId $entityId: all @ids of one document are different");
			foreach ($ids as $id) {
				$fragment = substr($id, strlen('https://x/#'));
				if (str_starts_with($fragment, 'loc:') || str_starts_with($fragment, 'area:')) {
					holds(preg_match('#^(?:[A-Za-z0-9._~/-]|%[0-9A-F]{2}|:)+$#', $fragment) === 1, "entityId $entityId: $fragment is a valid URI fragment");
				}
			}
		}
	}

	public function testSuite(): void
	{
		// --- localBusiness on the Testhof facts (§9.5) ---------------------------

		$node = JsonLd::localBusiness(jsonld_testhof_facts());
		expect([
			'@type'                     => 'GroceryStore',
			'@id'                       => 'https://x/#business',
			'name'                      => 'Testhof',
			'description'               => 'Bio-Hofladen mit Café',
			'url'                       => 'https://x',
			'telephone'                 => '+493034567000',
			'address'                   => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => 'Musterweg 1',
				'postalCode'      => '12345',
				'addressLocality' => 'Musterstadt',
				'addressCountry'  => 'DE',
			],
			'openingHoursSpecification' => [
				['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['https://schema.org/Monday'], 'opens' => '08:00', 'closes' => '12:00'],
			],
			'sameAs'                    => ['https://www.instagram.com/testhof'],
			'department'                => [
				[
					'@type'                     => 'GroceryStore',
					'@id'                       => 'https://x/#cafe',
					'name'                      => 'Testhof — Hofcafé',
					'address'                   => [
						'@type'           => 'PostalAddress',
						'streetAddress'   => 'Musterweg 1',
						'postalCode'      => '12345',
						'addressLocality' => 'Musterstadt',
						'addressCountry'  => 'DE',
					],
					'telephone'                 => '+493034567000',
					'openingHoursSpecification' => [
						['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['https://schema.org/Sunday'], 'opens' => '10:00', 'closes' => '17:00'],
					],
				],
			],
			'potentialAction'           => [
				'@type'  => 'OrderAction',
				'name'   => 'Online bestellen',
				'target' => ['@type' => 'EntryPoint', 'urlTemplate' => 'https://shop.example/testhof'],
			],
		], $node, 'localBusiness: Testhof node exact (empty keys pruned, primary hours, one department)');
		holds(!array_key_exists('email', $node), 'localBusiness: no email key when email is empty');
		holds(!array_key_exists('geo', $node), 'localBusiness: no geo key when geo is null');
		holds(!array_key_exists('image', $node), 'localBusiness: no image key when image is null');
		holds(!array_key_exists('faxNumber', $node), 'localBusiness: no faxNumber key when fax is empty');
		holds(!array_key_exists('specialOpeningHoursSpecification', $node), 'localBusiness: empty special hours pruned');
		holds(!in_array('', $node['sameAs'], true), 'localBusiness: sameAs has no empty string');
		// A unit at another location (Business::units() sets `place`) and one without an area.
		$facts = jsonld_testhof_facts();
		$facts['units'][1] += [
			'location' => 'standorte/sued',
			'place'    => ['street' => 'Bahnhofstraße 2', 'postalCode' => '12346', 'city' => 'Musterdorf', 'phone' => '030 34567070', 'phoneE164' => '+493034567070'],
		];
		$facts['units'][] = ['area' => null, 'location' => 'nord', 'name' => 'Nord', 'primary' => false, 'schema' => [], 'place' => ['street' => 'Nordweg 3', 'city' => 'Musterstadt']];
		$facts['units'][] = ['area' => null, 'name' => 'Ohne Ort', 'primary' => false, 'schema' => []];
		$departments = JsonLd::localBusiness($facts)['department'];
		expect(
			['https://x/#loc:standorte/sued:cafe', 'https://x/#loc:nord'],
			array_column($departments, '@id'),
			'localBusiness: another location gives @id loc:location:area or loc:location; a unit with neither is dropped'
		);
		expect(
			['@type' => 'PostalAddress', 'streetAddress' => 'Bahnhofstraße 2', 'postalCode' => '12346', 'addressLocality' => 'Musterdorf', 'addressCountry' => 'DE'],
			$departments[0]['address'],
			'localBusiness: a department at another location has its own address'
		);
		expect('+493034567070', $departments[0]['telephone'], 'localBusiness: a department at another location has its own phone');
		holds(!array_key_exists('telephone', $departments[1]), 'localBusiness: no phone at the other location → no telephone (never the main one)');
		// --- Variants ------------------------------------------------------------

		expect([], JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['type' => false])), 'localBusiness: type false gives []');
		expect([], JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['name' => ''])), 'localBusiness: empty name gives []');
		$noUnits = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['units' => []]));
		holds(!array_key_exists('department', $noUnits), 'localBusiness: no units, no department key');
		holds(!array_key_exists('openingHoursSpecification', $noUnits), 'localBusiness: no units, no opening hours key');
		holds(!array_key_exists('specialOpeningHoursSpecification', $noUnits), 'localBusiness: no units, no special hours key');
		expect('Testhof', $noUnits['name'] ?? null, 'localBusiness: no units still has the name');
		$geo = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['geo' => ['lat' => 52.52, 'lng' => 13.41]]));
		expect(['@type' => 'GeoCoordinates', 'latitude' => 52.52, 'longitude' => 13.41], $geo['geo'] ?? null, 'localBusiness: geo as GeoCoordinates floats');
		expect(true, is_float($geo['geo']['latitude']) && is_float($geo['geo']['longitude']), 'localBusiness: geo values are floats');
		$fax = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['faxE164' => '+493034567099']));
		expect('+493034567099', $fax['faxNumber'] ?? null, 'localBusiness: faxNumber from faxE164');
		// A phone or fax field that gives no number (SPEC §3.2, the input contract): the display string
		// stays for llms.txt, the JSON-LD has no telephone and no faxNumber.
		$unread = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['phone' => 'Tel. 030 34567000', 'phoneE164' => '', 'fax' => 'folgt', 'faxE164' => '']));
		holds(!array_key_exists('telephone', $unread) && !array_key_exists('faxNumber', $unread), 'localBusiness: no telephone and no faxNumber when the E.164 number is ""');
		$emailed = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['email' => 'hof@example.invalid']));
		expect('hof@example.invalid', $emailed['email'] ?? null, 'localBusiness: email when set');
		$noAddress = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['street' => '', 'city' => '', 'postalCode' => '']));
		holds(!array_key_exists('address', $noAddress), 'localBusiness: address omitted when street and city are empty');
		$cityOnly = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['street' => '']));
		expect('Musterstadt', $cityOnly['address']['addressLocality'] ?? null, 'localBusiness: address kept with city only');
		$noAction = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['action' => null]));
		holds(!array_key_exists('potentialAction', $noAction), 'localBusiness: no potentialAction without action');
		$emptyTarget = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['action' => ['type' => 'OrderAction', 'name' => 'x', 'target' => '']]));
		holds(!array_key_exists('potentialAction', $emptyTarget), 'localBusiness: action without target gives no potentialAction');
		$entity = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['entityId' => 'betrieb']));
		expect('https://x/#betrieb', $entity['@id'] ?? null, 'localBusiness: @id uses the entityId option');
		// --- document ------------------------------------------------------------

		expect(null, JsonLd::document([]), 'document: no nodes gives null');
		expect(null, JsonLd::document([[]]), 'document: empty node dropped, null');
		expect(null, JsonLd::document(['junk', null, 3]), 'document: non-arrays dropped, null');
		expect(
			['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => 'A'],
			JsonLd::document([['@type' => 'Thing', 'name' => 'A']]),
			'document: one node gets @context in front'
		);
		expect(
			['@context' => 'https://schema.org', '@graph' => [['@type' => 'A', 'name' => 'a'], ['@type' => 'B']]],
			JsonLd::document([
				['@context' => 'https://schema.org', '@type' => 'A', 'name' => 'a'],
				['@type' => 'B'],
			]),
			'document: two nodes become @graph, inner @context removed'
		);
		expect(
			['@context' => 'https://schema.org', '@type' => 'A'],
			JsonLd::document(['junk', [], ['@type' => 'A']]),
			'document: non-array and empty entries dropped before counting'
		);
		// --- encode and script ---------------------------------------------------

		expect(
			'{"d":"\\u003C/script\\u003E\\u003Cb\\u003E\\u0026ü/"}',
			JsonLd::encode(['d' => '</script><b>&ü/']),
			'encode: </script> and tags become \\u003C, & becomes \\u0026, ü and / stay unescaped'
		);
		expect(
			'{"@context":"https://schema.org","@type":"A"}',
			JsonLd::encode(['@context' => 'https://schema.org', '@type' => 'A']),
			'encode: slashes unescaped'
		);
		expect(
			'{"bad":"Caf' . "\u{FFFD}" . ' am Hof"}',
			JsonLd::encode(['bad' => "Caf\xE9 am Hof"]),
			'encode: a byte that is not UTF-8 (Latin-1 é) becomes U+FFFD instead of throwing'
		);
		$thrown = false;
		try {
			JsonLd::encode(['bad' => NAN]);
		} catch (JsonException) {
			$thrown = true;
		}
		holds($thrown, 'encode: NAN still throws JsonException');
		expect(
			'<script type="application/ld+json">{"@context":"https://schema.org","@type":"A","name":"Testhof"}</script>',
			JsonLd::script(['@context' => 'https://schema.org', '@type' => 'A', 'name' => 'Testhof']),
			'script: exact wrapper'
		);
		expect(
			'<script type="application/ld+json">{"@context":"https://schema.org","@type":"A","name":"\\u003C/script\\u003E"}</script>',
			JsonLd::script(['@context' => 'https://schema.org', '@type' => 'A', 'name' => '</script>']),
			'script: a </script> in a value cannot close the block'
		);
	}
}
