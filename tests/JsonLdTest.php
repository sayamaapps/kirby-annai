<?php
/**
 * JSON-LD arrays and encoding (SPEC.md §3.6, §9.5). Kirby-free.
 *   php tests/JsonLdTest.php
 * Owner: task `markup`.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Text.php';
require_once __DIR__ . '/../src/JsonLd.php';

use Annai\JsonLd;

/** The Testhof facts (SPEC.md §3.8 shape): two units, the cafe is not primary. */
function jsonld_testhof_facts(): array
{
    return [
        'type'        => 'GroceryStore',
        'name'        => 'Testhof',
        'tagline'     => '',
        'description' => 'Bio-Hofladen mit Café',
        'url'         => 'https://x',
        'image'       => null,
        'phone'       => '030 / 23125000',
        'phoneE164'   => '+493023125000',
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

/* --- localBusiness on the Testhof facts (§9.5) --------------------------- */

$node = JsonLd::localBusiness(jsonld_testhof_facts());
expect([
    '@type'                     => 'GroceryStore',
    '@id'                       => 'https://x/#business',
    'name'                      => 'Testhof',
    'description'               => 'Bio-Hofladen mit Café',
    'url'                       => 'https://x',
    'telephone'                 => '+493023125000',
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
            'telephone'                 => '+493023125000',
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
    'place'    => ['street' => 'Bahnhofstraße 2', 'postalCode' => '12346', 'city' => 'Musterdorf', 'phone' => '030 23125070', 'phoneE164' => '+493023125070'],
];
$facts['units'][] = ['area' => null, 'location' => 'nord', 'name' => 'Nord', 'primary' => false, 'schema' => [], 'place' => ['street' => 'Nordweg 3', 'city' => 'Musterstadt']];
$facts['units'][] = ['area' => null, 'name' => 'Ohne Ort', 'primary' => false, 'schema' => []];
$departments = JsonLd::localBusiness($facts)['department'];
expect(['https://x/#standorte-sued-cafe', 'https://x/#nord'], array_column($departments, '@id'),
    'localBusiness: another location gives @id location-area or location; a unit with neither is dropped');
expect(
    ['@type' => 'PostalAddress', 'streetAddress' => 'Bahnhofstraße 2', 'postalCode' => '12346', 'addressLocality' => 'Musterdorf', 'addressCountry' => 'DE'],
    $departments[0]['address'],
    'localBusiness: a department at another location has its own address'
);
expect('+493023125070', $departments[0]['telephone'], 'localBusiness: a department at another location has its own phone');
holds(!array_key_exists('telephone', $departments[1]), 'localBusiness: no phone at the other location → no telephone (never the main one)');

/* --- Variants ------------------------------------------------------------ */

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

$fax = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['faxE164' => '+493023125099']));
expect('+493023125099', $fax['faxNumber'] ?? null, 'localBusiness: faxNumber from faxE164');

// A phone or fax field that gives no number (SPEC §3.2, the input contract): the display string
// stays for llms.txt, the JSON-LD has no telephone and no faxNumber.
$unread = JsonLd::localBusiness(array_merge(jsonld_testhof_facts(), ['phone' => 'Tel. 030 23125000', 'phoneE164' => '', 'fax' => 'folgt', 'faxE164' => '']));
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

/* --- document ------------------------------------------------------------ */

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

/* --- encode and script --------------------------------------------------- */

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

finish('JsonLdTest');
