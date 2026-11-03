<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\JsonLd;

/**
 * A Noren location page: its own address and, for each area key, hours on Monday.
 * No keys: one plan for the whole page, no area.
 */
function depLocation(string $title, string $street, array $areas): array
{
	$fields = ['title' => $title, 'strasse' => $street, 'plz' => '12345', 'ort' => 'Musterstadt', 'telefon' => '030 34567001'];
	if ($areas === []) {
		return $fields + ['zeiten' => [['tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]];
	}
	foreach ($areas as $key) {
		$fields['bereiche'][] = ['schluessel' => $key, 'name' => ucfirst($key)];
		$fields['zeiten'][] = ['bereich' => $key, 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00'];
	}
	return $fields;
}

/**
 * A Noren page with hours and no address of its own: the address lives on
 * another page or in site.txt, so its units are at the business.
 */
function depHours(string $title, array $areas): array
{
	return array_diff_key(depLocation($title, '', $areas), array_flip(['strasse', 'plz', 'ort', 'telefon']));
}

/**
 * Renders the home page of a throwaway site with Noren and returns its
 * JSON-LD block, as a crawler reads it from the annai/head snippet.
 * $pages: content folder => [template, fields]. The first betrieb page is the business.
 * $site: more fields of site.txt (the business address, say).
 */
function depRender(array $pages, array $options = [], array $site = []): array
{
	$tmp = annai_site(['noren']);
	try {
		annai_page($tmp, '', 'site', ['title' => 'Hof', 'marke' => 'Hof'] + $site);
		foreach ($pages as $dir => [$template, $fields]) {
			annai_page($tmp, $dir, $template, $fields);
		}
		$kirby = annai_kirby($tmp, ['annai.active' => true, 'noren.active' => true, 'noren.language' => 'de', 'annai.language' => 'de'] + $options);
		[, $html] = annai_request($kirby, '/');
		$blocks = annai_jsonld($html);
		expect(1, count($blocks), 'the home page carries one JSON-LD block');
		return $blocks[0];
	} finally {
		annai_remove_tree($tmp);
	}
}

/** Every @id of a document, in document order. @return list<string> */
function depIds(array $document): array
{
	$ids = [];
	array_walk_recursive($document, static function (mixed $value, string|int $key) use (&$ids): void {
		if ($key === '@id') {
			$ids[] = (string)$value;
		}
	});
	return $ids;
}

/**
 * Department @ids in the head of the home page as a crawler reads it
 * (docs/reference.md, JSON-LD business node): no two nodes of one document
 * share an @id, whatever the page ids and area keys are.
 */
#[CoversClass(JsonLd::class)]
final class DepartmentIdTest extends TestCase
{
	private const BASE = 'http://example.invalid/';

	protected function setUp(): void
	{
		if (annai_sibling('noren') === null) {
			$this->markTestSkipped('kirby-noren not found (ANNAI_NOREN_PATH or ../kirby-noren)');
		}
	}

	/** @return array<string, array{0: array, 1: array, 2: list<string>, 3: string}> */
	public static function documents(): array
	{
		$business = static fn (array $areas): array => ['1_kontakt' => ['betrieb', depLocation('Kontakt', 'Musterweg 1', $areas)]];
		// The one Noren page has the address of site.txt too.
		$address = ['strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt'];
		return [
			// A location in a folder and a location with a dash share an area key.
			'location a/b and location a-b, area cafe' => [
				$business(['laden']) + [
					'2_a' => ['default', ['title' => 'A']],
					'2_a/1_b' => ['betrieb', depLocation('A B', 'Bahnhofstraße 2', ['cafe'])],
					'3_a-b' => ['betrieb', depLocation('A-B', 'Dorfstraße 3', ['cafe'])],
				],
				[],
				['loc:a/b:cafe', 'loc:a-b:cafe'],
				'business',
			],
			// "a" plus "b-c" and "a-b" plus "c" read the same when joined with a dash.
			'location a with area b-c and location a-b with area c' => [
				$business(['laden']) + [
					'2_a' => ['betrieb', depLocation('A', 'Bahnhofstraße 2', ['b-c'])],
					'3_a-b' => ['betrieb', depLocation('A-B', 'Dorfstraße 3', ['c'])],
				],
				[],
				['loc:a:b-c', 'loc:a-b:c'],
				'business',
			],
			// An area of the business and an area of another location shared a namespace.
			'business area annex-cafe and location annex with area cafe' => [
				$business(['laden', 'annex-cafe']) + [
					'2_annex' => ['betrieb', depLocation('Annex', 'Bahnhofstraße 2', ['cafe'])],
				],
				[],
				['annex-cafe', 'loc:annex:cafe'],
				'business',
			],
			// An area named like the business node's fragment.
			'business area named business, default entityId' => [
				$business(['laden', 'business']),
				[],
				['area:business'],
				'business',
			],
			'business area named betrieb, entityId betrieb' => [
				$business(['laden', 'betrieb']),
				['annai.entityId' => 'betrieb'],
				['area:betrieb'],
				'betrieb',
			],
			// Controls: what stays as it was or never collided.
			'locations alpha and beta, area cafe' => [
				$business(['laden']) + [
					'2_alpha' => ['betrieb', depLocation('Alpha', 'Bahnhofstraße 2', ['cafe'])],
					'3_beta' => ['betrieb', depLocation('Beta', 'Dorfstraße 3', ['cafe'])],
				],
				[],
				['loc:alpha:cafe', 'loc:beta:cafe'],
				'business',
			],
			'location without areas' => [
				$business(['laden']) + [
					'2_sued' => ['betrieb', depLocation('Süd', 'Bahnhofstraße 2', [])],
				],
				[],
				['loc:sued'],
				'business',
			],
			'business areas keep the bare key' => [
				$business(['laden', 'cafe', 'bar']),
				[],
				['cafe', 'bar'],
				'business',
			],
			'entityId betrieb, business area named business' => [
				$business(['laden', 'business']),
				['annai.entityId' => 'betrieb'],
				['business'],
				'betrieb',
			],
			// One Noren page. It is the business page unless annai.location says otherwise;
			// a page that is not the business page and has an address of its own is
			// a location, also when that is the address of site.txt.
			'one Noren page with an address, the business page' => [
				$business(['laden', 'cafe']),
				[],
				['cafe'],
				'business',
				$address,
			],
			'one Noren page with an address, annai.location => fn () => null' => [
				$business(['laden', 'cafe']),
				['annai.location' => fn () => null],
				['loc:kontakt:cafe'],
				'business',
				$address,
			],
			'one Noren page with an address, annai.location names another page' => [
				$business(['laden', 'cafe']),
				['annai.location' => 'home'],
				['loc:kontakt:cafe'],
				'business',
				$address,
			],
			// Two Noren pages without an address of their own are both "at the business",
			// so each used to claim the bare key. Only the business page keeps it.
			'two pages without an address, area cafe on both' => [
				$business(['laden']) + [
					'2_a' => ['betrieb', depHours('A', ['cafe'])],
					'3_b' => ['betrieb', depHours('B', ['cafe'])],
				],
				[],
				['loc:a:cafe', 'loc:b:cafe'],
				'business',
			],
			// The same with the pages in the other order: the ids follow the page, not the position.
			'two pages without an address, area cafe on both, order swapped' => [
				$business(['laden']) + [
					'2_b' => ['betrieb', depHours('B', ['cafe'])],
					'3_a' => ['betrieb', depHours('A', ['cafe'])],
				],
				[],
				['loc:a:cafe', 'loc:b:cafe'],
				'business',
			],
			// The business page has the area too: it keeps #cafe, the other page does not.
			'business area cafe and a page without an address with area cafe' => [
				$business(['laden', 'cafe']) + [
					'2_a' => ['betrieb', depHours('A', ['cafe'])],
				],
				[],
				['cafe', 'loc:a:cafe'],
				'business',
			],
			// The other page comes first in Noren's order; the business page is named by option.
			'business area cafe and a page without an address with area cafe, order swapped' => [
				['1_a' => ['betrieb', depHours('A', ['cafe'])], '2_kontakt' => ['betrieb', depLocation('Kontakt', 'Musterweg 1', ['laden', 'cafe'])]],
				['annai.location' => 'kontakt', 'annai.area' => 'laden'],
				['cafe', 'loc:a:cafe'],
				'business',
			],
			// Another area on the page without an address does not collide: it keeps its bare key.
			'business area laden and a page without an address with areas laden and cafe' => [
				$business(['laden']) + [
					'2_a' => ['betrieb', depHours('A', ['laden', 'cafe'])],
				],
				[],
				['loc:a:laden', 'cafe'],
				'business',
			],
			// Nobody keeps the key when no unit is on the business page (no business page at all).
			'no business page, two pages without an address, area cafe on both' => [
				[
					'1_a' => ['betrieb', depHours('A', ['laden', 'cafe'])],
					'2_b' => ['betrieb', depHours('B', ['cafe'])],
				],
				['annai.location' => fn () => null],
				['loc:a:cafe', 'loc:b:cafe'],
				'business',
				$address,
			],
			// Controls: one page without an address, no second claim. It keeps #<area>.
			'one hours page without an address, address on annai.location' => [
				[
					'1_kontakt' => ['default', ['title' => 'Kontakt'] + $address],
					'2_zeiten' => ['betrieb', depHours('Zeiten', ['laden', 'cafe'])],
				],
				['annai.location' => 'kontakt'],
				['cafe'],
				'business',
			],
			'one hours page without an address next to the business page' => [
				$business(['laden']) + [
					'2_a' => ['betrieb', depHours('A', ['cafe'])],
				],
				[],
				['cafe'],
				'business',
			],
		];
	}

	/**
	 * @param array<string, array{0: string, 1: array}> $pages
	 * @param list<string> $fragments the fragments of the departments, in any order
	 * @param array<string, string> $site more fields of site.txt
	 */
	#[DataProvider('documents')]
	public function testEveryIdOfTheDocumentIsUnique(array $pages, array $options, array $fragments, string $entityId, array $site = []): void
	{
		$document = depRender($pages, $options, $site);
		$ids = depIds($document);
		expect(array_values(array_unique($ids)), $ids, 'every @id in the document is different');
		expect(self::BASE . '#' . $entityId, $document['@id'] ?? null, 'the business node keeps #entityId');

		$departments = array_map(static fn (string $id): string => substr($id, strlen(self::BASE . '#')), depIds(['department' => $document['department'] ?? []]));
		sort($departments);
		sort($fragments);
		expect($fragments, $departments, 'department @ids');
	}
}
