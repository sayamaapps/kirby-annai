<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\I18n;
use Sayamaapps\Annai\LlmsTxt;

/** One unit (§3.8 shape) with a Monday split shift, Saturday sales only, Sunday closed. */
function annai_test_unit(string $name, array $overrides = []): array
{
	$closed = ['blocks' => []];
	$days = [
		0 => ['blocks' => [[480, 720], [840, 1080]]],
		1 => $closed, 2 => $closed, 3 => $closed, 4 => $closed,
		5 => ['blocks' => [[480, 720]], 'note' => 'nur Verkauf'],
		6 => $closed,
	];
	return array_merge([
		'area' => 'laden',
		'name' => $name,
		'primary' => true,
		'days' => $days,
		'holidays' => false,
		'closures' => [],
	], $overrides);
}

/** The fixture as a full model (German texts). */
function annai_test_model(array $overrides = []): array
{
	return array_merge([
		'title' => 'Testhof',
		'summary' => 'Bio-Hofladen mit Café',
		'intro' => [],
		'contact' => [
			'address' => 'Musterweg 1, 12345 Musterstadt',
			'phone' => '030 / 34567000',
			'phoneE164' => '+493034567000',
			'email' => 'hof@example.test',
			'action' => ['name' => 'Online bestellen', 'target' => 'https://shop.example/testhof'],
			'extra' => [],
		],
		'units' => [annai_test_unit('Hofladen', ['holidays' => true, 'closures' => [
			['from' => '2027-12-31', 'to' => '2027-12-31', 'label' => 'Silvester', 'hours' => null, 'note' => ''],
			['from' => '2027-12-24', 'to' => '2027-12-24', 'label' => 'Heiligabend', 'hours' => [510, 720], 'note' => ''],
		]])],
		'events' => [],
		'sections' => [],
		'pages' => [
			['title' => 'Startseite', 'url' => 'https://example.test', 'note' => ''],
			['title' => 'Hofladen', 'url' => 'https://example.test/laden', 'note' => 'Gemüse vom eigenen Feld.'],
		],
		'optional' => [],
	], $overrides);
}

/**
 * llms.txt (docs/SPEC.md §3.5, §9.4): the lines in German, English labels,
 * unit blocks, closures, events, sections, pages and link rules.
 *   vendor/bin/phpunit tests/LlmsTxtTest.php
 */
#[CoversClass(LlmsTxt::class)]
final class LlmsTxtTest extends TestCase
{
	public function testSuite(): void
	{
		// ── German, the expectations of the fixture ─────────────────────────────────────
		I18n::setLanguage('de');
		$md = LlmsTxt::render(annai_test_model());
		holds(str_starts_with($md, "# Testhof\n\n> Bio-Hofladen mit Café\n\n## Kontakt\n\n"), 'H1 and quote, then the Kontakt section');
		holds(str_contains($md, "\n- Adresse: Musterweg 1, 12345 Musterstadt\n"), 'Adresse line');
		holds(str_contains($md, "\n- Telefon: 030 / 34567000 (tel:+493034567000)\n"), 'Telefon line with E.164 in brackets');
		holds(str_contains($md, "\n- E-Mail: hof@example.test\n"), 'E-Mail line');
		holds(str_contains($md, "\n- Online bestellen: https://shop.example/testhof\n"), 'action line uses its own name');
		holds(str_contains($md, "\n## Öffnungszeiten\n\n- Montag: 8:00–12:00, 14:00–18:00 Uhr\n"), 'Monday hours line');
		holds(str_contains($md, "\n- Dienstag: geschlossen\n"), 'Tuesday closed');
		holds(str_contains($md, "\n- Samstag: 8:00–12:00 Uhr (nur Verkauf)\n"), 'Saturday with note');
		holds(str_contains($md, "\n- Gesetzliche Feiertage: geschlossen\n"), 'holidays line');
		holds(str_contains($md, "\n- 31.12.2027 (Silvester): geschlossen\n"), 'Silvester closure');
		holds(str_contains($md, "\n- 24.12.2027 (Heiligabend): nur 8:30–12:00 Uhr\n"), 'Heiligabend shortened hours');
		holds(!str_contains($md, '### '), 'one unit: no ### heading');
		holds(str_contains($md, "\n## Seiten\n\n- [Startseite](https://example.test)\n- [Hofladen](https://example.test/laden): Gemüse vom eigenen Feld.\n"), 'home first, then the note of a page');
		// The complete German output for one unit with every block type.
		$full = LlmsTxt::render(annai_test_model([
			'intro' => ['Ein Absatz.'],
			'contact' => ['address' => '', 'phone' => '', 'phoneE164' => '', 'email' => '', 'action' => null, 'extra' => []],
			'units' => [annai_test_unit('Hofladen', ['holidays' => true, 'closures' => [
				['from' => '2027-08-02', 'to' => '2027-08-15', 'label' => 'Betriebsferien', 'hours' => null, 'note' => 'Sommerpause'],
				['from' => '2027-12-24', 'to' => '2027-12-24', 'label' => 'Heiligabend', 'hours' => [510, 720], 'note' => ''],
			]])],
			'events' => [
				['title' => 'Retreat', 'url' => 'https://example.test/retreat', 'when' => 'Fr, 12.3.2027, 18:00 Uhr', 'venue' => 'Beispielhof', 'cancelled' => false],
				['title' => 'Hoffest', 'url' => 'https://example.test/fest', 'when' => 'Sa, 1.5.2027', 'venue' => null, 'cancelled' => true],
			],
			'sections' => [['title' => 'Im Notfall', 'lines' => ['- 112: Notruf', '   ']]],
			'pages' => [['title' => 'Startseite', 'url' => 'https://example.test', 'note' => '']],
			'optional' => [['title' => 'Impressum', 'url' => 'https://example.test/impressum', 'note' => '']],
		]));
		$expected = implode("\n", [
			'# Testhof',
			'',
			'> Bio-Hofladen mit Café',
			'',
			'Ein Absatz.',
			'',
			'## Öffnungszeiten',
			'',
			'- Montag: 8:00–12:00, 14:00–18:00 Uhr',
			'- Dienstag: geschlossen',
			'- Mittwoch: geschlossen',
			'- Donnerstag: geschlossen',
			'- Freitag: geschlossen',
			'- Samstag: 8:00–12:00 Uhr (nur Verkauf)',
			'- Sonntag: geschlossen',
			'- Gesetzliche Feiertage: geschlossen',
			'- 2.8.2027 bis 15.8.2027 (Betriebsferien): geschlossen, Sommerpause',
			'- 24.12.2027 (Heiligabend): nur 8:30–12:00 Uhr',
			'',
			'## Termine',
			'',
			'- [Retreat](https://example.test/retreat): Fr, 12.3.2027, 18:00 Uhr, Beispielhof',
			'- [Hoffest](https://example.test/fest): Sa, 1.5.2027 (abgesagt)',
			'',
			'## Im Notfall',
			'',
			'- 112: Notruf',
			'',
			'## Seiten',
			'',
			'- [Startseite](https://example.test)',
			'',
			'## Optional',
			'',
			'- [Impressum](https://example.test/impressum)',
			'',
		]);
		expect($expected, $full, 'German: complete file with every block, sections before Pages');
		// Two units: a ### heading per unit, in the order given.
		$two = LlmsTxt::render(annai_test_model(['units' => [
			annai_test_unit('Hofladen'),
			annai_test_unit('Café', ['area' => 'cafe', 'primary' => false, 'holidays' => false]),
		]]));
		expect(2, substr_count($two, "\n### "), 'two units: two ### headings');
		holds(str_contains($two, "## Öffnungszeiten\n\n### Hofladen\n\n- Montag: 8:00–12:00, 14:00–18:00 Uhr"), 'Hofladen heading and hours');
		holds(str_contains($two, "\n### Café\n\n- Montag: 8:00–12:00, 14:00–18:00 Uhr"), 'Café heading and hours');
		holds(strpos($two, '### Hofladen') < strpos($two, '### Café'), 'units keep their order');
		// A unit without a name gives no empty "### " heading; the other units keep theirs.
		$unnamed = LlmsTxt::render(annai_test_model(['units' => [
			annai_test_unit(''),
			annai_test_unit('Café', ['area' => 'cafe', 'primary' => false, 'holidays' => false]),
		]]));
		holds(!str_contains($unnamed, "### \n") && !str_contains($unnamed, "###\n"), 'unnamed unit: no empty ### heading');
		holds(str_contains($unnamed, "## Öffnungszeiten\n\n- Montag: 8:00–12:00, 14:00–18:00 Uhr"), 'unnamed unit: its hours follow the section heading directly');
		holds(str_contains($unnamed, "\n### Café\n\n- Montag:"), 'unnamed unit: the named unit keeps its heading');
		expect(1, substr_count($unnamed, "\n### "), 'unnamed unit: one ### heading in total');
		// A unit at another location (Business::units() sets `place`): its address and phone under its heading.
		$elsewhere = LlmsTxt::render(annai_test_model(['units' => [
			annai_test_unit('Laden A'),
			annai_test_unit('Praxis B', ['area' => 'praxis', 'primary' => false, 'location' => 'b',
				'place' => ['street' => 'Bahnhofstraße 2', 'postalCode' => '12346', 'city' => 'Musterdorf', 'phone' => '030 34567070', 'phoneE164' => '+493034567070']]),
		]]));
		holds(
			str_contains($elsewhere, "\n### Praxis B\n\n- Adresse: Bahnhofstraße 2, 12346 Musterdorf\n- Telefon: 030 34567070 (tel:+493034567070)\n\n- Montag: 8:00–12:00, 14:00–18:00 Uhr"),
			'unit at another location: address and phone under its heading, then the hours'
		);
		holds(str_contains($elsewhere, "### Laden A\n\n- Montag:"), 'unit at the business location: no address lines (the contact block has them)');
		// ── English ─────────────────────────────────────────────────────────────────
		I18n::setLanguage('en');
		$en = LlmsTxt::render(annai_test_model());
		holds(str_contains($en, "\n## Contact\n"), 'English: Contact heading');
		holds(str_contains($en, "\n## Opening hours\n"), 'English: Opening hours heading');
		holds(str_contains($en, "\n- Monday: 8:00–12:00, 14:00–18:00\n"), 'English: Monday without Uhr');
		holds(str_contains($en, "\n- Tuesday: closed\n"), 'English: Tuesday closed');
		holds(str_contains($en, "\n- Public holidays: closed\n"), 'English: holidays line');
		holds(str_contains($en, "\n- Address: Musterweg 1, 12345 Musterstadt\n"), 'English: Address label');
		holds(str_contains($en, "\n- 24 Dec 2027 (Heiligabend): only 8:30–12:00\n"), 'English: only hours');
		holds(str_contains($en, "\n## Pages\n"), 'English: Pages heading');
		I18n::setLanguage('de');
		// ── Structure and optional parts ───────────────────────────────────────────
		$onlyTitle = LlmsTxt::render(['title' => 'Testhof', 'pages' => []]);
		expect("# Testhof\n\n## Seiten\n", $onlyTitle, 'title only: Seiten heading alone, no empty sections');
		$noContact = LlmsTxt::render(annai_test_model(['contact' => ['address' => '', 'phone' => '', 'phoneE164' => '', 'email' => '', 'action' => null, 'extra' => []]]));
		holds(!str_contains($noContact, '## Kontakt'), 'empty contact: no Kontakt section');
		$noEvents = LlmsTxt::render(annai_test_model(['events' => []]));
		holds(!str_contains($noEvents, '## Termine'), 'no events: no Termine section');
		$noOptional = LlmsTxt::render(annai_test_model(['optional' => []]));
		holds(!str_contains($noOptional, '## Optional'), 'no optional entries: no Optional section');
		$noHolidays = LlmsTxt::render(annai_test_model(['units' => [annai_test_unit('Hofladen', ['holidays' => false])]]));
		holds(!str_contains($noHolidays, 'Feiertage'), 'holidays false: no holidays line');
		$extraContact = LlmsTxt::render(annai_test_model(['contact' => ['address' => '', 'phone' => '', 'phoneE164' => '',
			'email' => '', 'action' => null, 'extra' => ['- Fax: 030 34567099', '  ']]]));
		holds(str_contains($extraContact, "## Kontakt\n\n- Fax: 030 34567099\n"), 'extra contact lines are appended unescaped');
		// contact.extra lines go through Text::line() like sections and intro (SPEC §3.5);
		// rtrim() alone used to keep a leading indent and a line feed inside the line.
		$extraLined = LlmsTxt::render(annai_test_model(['contact' => ['address' => '', 'phone' => '', 'phoneE164' => '',
			'email' => '', 'action' => null, 'extra' => ["  - Fax:\n   030 34567099  ", "\t"]]]));
		holds(str_contains($extraLined, "## Kontakt\n\n- Fax: 030 34567099\n\n## "), 'extra contact line: whitespace collapsed to one line and trimmed, a blank line dropped');
		holds(str_contains($extraLined, '<b>') === false && str_contains(LlmsTxt::render(annai_test_model(['contact' => ['address' => '', 'phone' => '', 'phoneE164' => '',
			'email' => '', 'action' => null, 'extra' => ['- Fax: <b>0</b> [x](y)']]])), "- Fax: <b>0</b> [x](y)\n"), 'extra contact line: not escaped (hook text, like sections)');
		$phoneOnly = LlmsTxt::render(annai_test_model(['contact' => ['address' => '', 'phone' => '', 'phoneE164' => '+493034567000',
			'email' => '', 'action' => null, 'extra' => []]]));
		holds(str_contains($phoneOnly, "- Telefon: +493034567000\n"), 'E.164 alone is shown as the phone');
		$actionFallback = LlmsTxt::render(annai_test_model(['contact' => ['address' => '', 'phone' => '', 'phoneE164' => '',
			'email' => '', 'action' => ['name' => '', 'target' => 'https://shop.example'], 'extra' => []]]));
		holds(str_contains($actionFallback, "- Haupthandlung: https://shop.example\n"), 'action without name uses the default label');
		$closureRange = LlmsTxt::render(annai_test_model(['units' => [annai_test_unit('Hofladen', ['closures' => [
			['from' => '2027-08-02', 'to' => '2027-08-15', 'label' => 'Betriebsferien', 'hours' => null, 'note' => 'Sommerpause'],
		]])]]));
		holds(str_contains($closureRange, "- 2.8.2027 bis 15.8.2027 (Betriebsferien): geschlossen, Sommerpause\n"), 'closure range with note');
		$sections = LlmsTxt::render(annai_test_model(['sections' => [
			['title' => 'Im Notfall', 'lines' => ['- 112: Notruf']],
			['title' => 'Leer', 'lines' => ['  ']],
		]]));
		holds(str_contains($sections, "## Im Notfall\n\n- 112: Notruf\n\n## Seiten"), 'sections come before Pages');
		holds(!str_contains($sections, '## Leer'), 'a section without lines is left out');
		// Events: venue, cancelled, bracket and link rules.
		$events = LlmsTxt::render(annai_test_model(['events' => [
			['title' => 'Retreat [Frühling]', 'url' => 'https://example.test/r', 'when' => 'Fr, 12.3.2027', 'venue' => 'Beispielhof', 'cancelled' => false],
			['title' => 'Abend', 'url' => 'https://example.test/a', 'when' => '', 'venue' => '', 'cancelled' => true],
		]]));
		holds(str_contains($events, "## Termine\n\n- [Retreat (Frühling)](https://example.test/r): Fr, 12.3.2027, Beispielhof\n"), 'event with venue; brackets in titles become parentheses');
		holds(str_contains($events, "- [Abend](https://example.test/a): (abgesagt)\n"), 'cancelled event without date');
		// Page notes and brackets.
		$pages = LlmsTxt::render(['title' => 'T', 'pages' => [['title' => 'A [B] C', 'url' => 'https://example.test/x', 'note' => "  Zwei\n Zeilen "]]]);
		holds(str_contains($pages, "- [A (B) C](https://example.test/x): Zwei Zeilen\n"), 'brackets in page titles and notes are line-cleaned');
		// unitLines() on its own.
		$lines = LlmsTxt::unitLines(annai_test_unit('Hofladen', ['holidays' => true, 'closures' => [
			['from' => '2027-12-31', 'to' => '2027-12-31', 'label' => 'Silvester', 'hours' => null, 'note' => ''],
		]]));
		expect(9, count($lines), 'unitLines: seven days, holidays and one closure');
		expect('- Montag: 8:00–12:00, 14:00–18:00 Uhr', $lines[0], 'unitLines: Monday');
		expect('- Samstag: 8:00–12:00 Uhr (nur Verkauf)', $lines[5], 'unitLines: Saturday with note');
		expect('- Sonntag: geschlossen', $lines[6], 'unitLines: Sunday');
		expect('- Gesetzliche Feiertage: geschlossen', $lines[7], 'unitLines: holidays after the days');
		expect('- 31.12.2027 (Silvester): geschlossen', $lines[8], 'unitLines: closure after holidays');
		// The summary is the raw site description: no HTML, and a link keeps its target
		// only when it is on the allowlist (http, https, mailto, tel, /, #), checked after
		// entity decoding with whitespace and control characters removed.
		$summaryOf = function (string $summary): string {
			$file = LlmsTxt::render(['title' => 'T', 'summary' => $summary]);
			return preg_match('/^> (.*)$/m', $file, $m) === 1 ? $m[1] : '';
		}
		;
		$summaryCases = [
			'tags and a javascript: link'       => ['<img src=x onerror=alert(1)> [klick](javascript:alert(1))', 'klick'],
			'scheme in mixed case'              => ['[a](JaVaScRiPt:alert(1))', 'a'],
			'leading space in the target'       => ['[a]( javascript:alert(1))', 'a'],
			'tab inside the scheme'             => ["[a](java\tscript:alert(1))", '(a)(java script:alert(1))'],
			'control character in the scheme'   => ["[a](java\x01script:alert(1))", 'a'],
			'colon as &#58;'                    => ['[a](javascript&#58;alert(1))', 'a'],
			'letter as &#x6A;'                  => ['[a](&#x6A;avascript:alert(1))', 'a'],
			'vbscript:'                         => ['[a](vbscript:msgbox(1))', 'a'],
			'data:'                             => ['[a](data:text/html,x)', 'a'],
			'file:'                             => ['[a](file:///etc/passwd)', 'a'],
			'any other scheme'                  => ['[a](ftp://x.test/a)', 'a'],
			'angle-bracket target'              => ['[a](<javascript:alert(1)>)', 'a'],
			'image with a script source'        => ['![i](javascript:alert(1))', '!i'],
			'label with brackets'               => ['[a [b] c](javascript:x)', 'a (b) c'],
			'unclosed target'                   => ['[a](javascript:alert(1)', '(a)(javascript:alert(1)'],
			'reference link and definition'     => ['[x][1] [1]: javascript:alert(1)', '(x)(1) (1): javascript:alert(1)'],
			'autolink'                          => ['vorher <javascript:alert(1)> nachher', 'vorher nachher'],
			'encoded tag'                       => ['a &lt;img src=x onerror=alert(1)&gt; b', 'a b'],
			'double-encoded tag'                => ['a &amp;lt;img src=x onerror=alert(1)&amp;gt; b', 'a b'],
			'split tag'                         => ['a <<img>img src=x onerror=alert(1)> b', 'a b'],
			'nested script tag'                 => ['a <scr<script>ipt>x</script> b', 'a ipt\\>x b'],
			'unclosed tag'                      => ['a <img src=x onerror=alert(1)', 'a img src=x onerror=alert(1)'],
			'unclosed "<" before a word'        => ['Kinder <zehn Jahre frei, sonst 5 €', 'Kinder zehn Jahre frei, sonst 5 €'],
			'nesting deeper than the rounds, after a backslash' => ['\\' . str_repeat('<', 11) . str_repeat('img>', 10) . 'img src=x onerror=alert(1)>', null],
			'e-mail address in angle brackets'  => ['Fragen an <info@hof.de>, gern', 'Fragen an info@hof.de, gern'],
			'leftover < and >'                  => ['1 < 2 > 0 & **fett**', '1 \\< 2 \\> 0 & **fett**'],
			// A "<" that cannot open HTML keeps the text after it (strip_tags() dropped it).
			'literal < before a digit'          => ['Kinder <6 Jahre frei', 'Kinder \\<6 Jahre frei'],
			'literal <3, <- and <='             => ['Hof <3 Laden, a <- b, x<=y', 'Hof \\<3 Laden, a \\<- b, x\\<=y'],
			// Control characters go before the tag strip, else "\<img" with onerror survives.
			'control character after <'         => ["\\<\x01img src=x onerror=alert(1)>", '\\\\'],
			'encoded tag with a control character' => ["a &lt;\x01img src=x onerror=alert(1)&gt; b", 'a b'],
			'allowed targets stay'              => ['[w](https://x.test) [m](mailto:a@b.test) [t](tel:+49) [r](/kontakt) [h](#karte)',
				'[w](https://x.test) [m](mailto:a@b.test) [t](tel:+49) [r](/kontakt) [h](#karte)'],
			'allowed target: parens encoded, title dropped' => ['[q](https://x.test/a_(b) "Titel")', '[q](https://x.test/a_%28b%29)'],
			'allowed image stays'               => ['![j](https://x.test/j.png)', '![j](https://x.test/j.png)'],
			'allowed scheme in upper case'      => ['[a](HTTPS://X.test/A)', '[a](HTTPS://X.test/A)'],
		];
		foreach ($summaryCases as $what => [$input, $expected]) {
			if ($expected !== null) {
				expect($expected, $summaryOf($input), "summary: $what");
			}
		}
		// A "<" opens a tag in Markdown unless an odd number of backslashes precedes it.
		$opensTag = static fn (string $out): bool => preg_match('/(?<!\\\\)(?:\\\\\\\\)*<[A-Za-z!\/?]/', $out) === 1;
		foreach ($summaryCases as $what => [$input]) {
			$out = $summaryOf($input);
			holds(
				preg_match('/\]\(\s*<?\s*(?!https?:|mailto:|tel:|\/|#)/i', $out) !== 1 && !$opensTag($out),
				"summary: $what leaves no unsafe link and no tag"
			);
		}
		// The link pattern is linear (an earlier recursive label pattern took seconds per request).
		$start = microtime(true);
		$summaryOf(str_repeat('[a ', 8000) . '](https://x)');
		$summaryOf(str_repeat('[', 20000) . '](https://x)');
		holds(microtime(true) - $start < 0.5, 'summary: 8000 × "[a " and 20000 × "[" before a link take well under a second');
		expect('[a (b) c](https://x.test)', $summaryOf('[a [b] c](https://x.test)'), 'summary: one level of brackets in a label: still a link, inner brackets as parentheses');
		// A long bare target (a data: URI) is read possessively. One backtracking frame
		// per character used to exhaust PCRE's JIT stack at about 8,000 characters (PHP 8.4,
		// pcre.jit=1; without JIT at 100,000), and the whole summary was gone.
		$file = LlmsTxt::render(['title' => 'T', 'summary' => 'Hofladen. [Bild](data:image/png;base64,' . str_repeat('A', 100000) . ') Mehr.']);
		holds(str_contains($file, "\n> Hofladen"), 'summary: a 100,000-character data: target keeps the summary (JIT: ' . var_export(ini_get('pcre.jit'), true) . ')');
		holds(str_contains($file, "\n> Hofladen. Bild Mehr.\n"), 'summary: the data: link loses its target, the label stays');
		// Parentheses in a bare target: three levels, CommonMark's minimum. Recursion
		// without a limit rescanned the rest of the text from every "[a](": 20 s for
		// "[a](" × 25,000 without JIT.
		expect('[a](https://x.test/a%28b%28c%28d%29%29%29e)', $summaryOf('[a](https://x.test/a(b(c(d)))e)'), 'summary: three levels of parentheses in a target: a link');
		expect('(a)(https://x.test/a(b(c(d(e)))))', $summaryOf('[a](https://x.test/a(b(c(d(e)))))'), 'summary: four levels: no link, the text stays');
		$start = microtime(true);
		$summaryOf(str_repeat('[a](', 25000) . ')');
		$summaryOf(str_repeat('[a](x(y)', 12500) . ')');
		$deep = $summaryOf('Hofladen. [a](x' . str_repeat('(', 50000) . str_repeat(')', 50000) . ') Mehr.');
		holds(microtime(true) - $start < 0.5, 'summary: 25,000 × "[a](", 12,500 × "[a](x(y)" and 50,000 nested parentheses take well under a second');
		holds(str_starts_with($deep, 'Hofladen. (a)(x(((') && str_ends_with($deep, ')) Mehr.'), 'summary: 50,000 nested parentheses keep the text');
		// When PCRE gives up anyway (here: pcre.backtrack_limit 2, which the plain text
		// patterns pass and the link pattern does not), the text stays and
		// no link is kept, instead of an empty summary.
		ini_set('pcre.backtrack_limit', '2');
		try {
			$limited = LlmsTxt::render(['title' => 'T', 'summary' => 'Hofladen. [Laden](https://x.test/laden) und [Café](https://x.test/cafe).']);
		} finally {
			ini_restore('pcre.backtrack_limit');
		}
		holds(str_contains($limited, "\n> Hofladen. (Laden)(https://x.test/laden) und (Café)(https://x.test/cafe).\n"), 'summary: a pattern PCRE gives up on keeps the text, every link as plain text');
		// A run of "<" with no letter after it. The opener pattern used to eat the whole
		// run at every position and backtrack it (quadratic: 4 s for 20,000 "<", 73 s for
		// 50,000 — in the H1, the summary, every title and note, and the preview file);
		// "&lt;" × 50,000 decoded into the same run. Now one possessive run per start.
		$run = str_repeat('<', 50000);
		$start = microtime(true);
		$heading = LlmsTxt::heading($run);
		$file = LlmsTxt::render(['title' => 'T', 'summary' => $run . ' Hofladen']);
		$entities = LlmsTxt::render(['title' => 'T', 'summary' => str_repeat('&lt;', 20000) . 'img' . str_repeat('&gt;', 20000)]);
		holds(microtime(true) - $start < 0.5, 'plain: 50,000 × "<" in the H1 and the summary, 20,000 × "&lt;" … "&gt;" take well under a second');
		expect('# ' . str_repeat('\\<', 50000), $heading, 'plain: a run of "<" before nothing stays, every "<" escaped');
		holds(str_contains($file, "\n> " . str_repeat('\\<', 50000) . " Hofladen\n"), 'summary: a run of "<" before a space stays as escaped text');
		holds(str_contains($entities, "\n> " . str_repeat('\\<', 19999) . str_repeat('\\>', 19999) . "\n"), 'summary: decoded "<" run, "img", ">" run: "<img>" goes as a tag, the rest stays escaped');
		expect('# img', LlmsTxt::heading(str_repeat('<', 50000) . 'img'), 'plain: a run of "<" before a letter goes entirely (none may open a tag)');
		expect('# a \\<\\< b img c', LlmsTxt::heading('a << b <<<img c'), 'plain: only the run before a letter goes, a run before a space stays');
		$nested = '\\' . str_repeat('<', 11) . str_repeat('img>', 10) . 'img src=x onerror=alert(1)>';
		$file = LlmsTxt::render(['title' => 'Testhof ' . $nested, 'pages' => [['title' => $nested, 'url' => 'https://x.test', 'note' => $nested]]]);
		holds(!$opensTag($file), 'plain: nesting deeper than the rounds after a backslash opens no tag in H1, title or note');
		// Titles, notes and names from the Panel: no markup, not even entity-encoded, and no links.
		$file = LlmsTxt::render(['title' => 'Hof &lt;b&gt;', 'pages' => [
			['title' => '<b>X</b>&lt;script&gt;alert(1)&lt;/script&gt;', 'url' => 'https://x.test', 'note' => '&lt;img src=x onerror=1&gt; [k](javascript:1)'],
		]]);
		holds(str_starts_with($file, "# Hof\n"), 'plain: entity-encoded tag in the H1 is removed');
		holds(str_contains($file, "- [Xalert(1)](https://x.test): (k)(javascript:1)\n"), 'plain: page title and note lose (encoded) tags, a note link is no link');
		// Invalid UTF-8 becomes U+FFFD (not "?"), and the global substitute character is restored.
		$substitute = mb_substitute_character();
		holds(str_starts_with(LlmsTxt::render(['title' => "M\xFCller Hof"]), "# M\u{FFFD}ller Hof\n"), 'plain: an invalid byte becomes U+FFFD');
		expect($substitute, mb_substitute_character(), 'plain: mb_substitute_character() is restored');
		// A literal "<" in a summary, a title or a note keeps the text after it.
		holds(
			str_contains(LlmsTxt::render(['title' => 'T', 'summary' => 'Kinder <6 Jahre frei']), "\n> Kinder \\<6 Jahre frei\n"),
			'summary: "Kinder <6 Jahre frei" keeps the text after "<"'
		);
		$file = LlmsTxt::render(['title' => 'Hof <3 Laden', 'pages' => [['title' => 'Preise <10 €', 'url' => 'https://x.test/p', 'note' => 'Kinder <6 frei']]]);
		holds(str_starts_with($file, "# Hof \\<3 Laden\n"), 'plain: H1 "Hof <3 Laden" keeps the text after "<"');
		holds(str_contains($file, "- [Preise \\<10 €](https://x.test/p): Kinder \\<6 frei\n"), 'plain: page title and note keep the text after "<"');
		// Link targets from a hook: spaces and parentheses are percent-encoded so the link does not end early.
		$file = LlmsTxt::render(['title' => 'T', 'pages' => [['title' => 'Liste', 'url' => 'https://x.test/a b(c)', 'note' => '']]]);
		holds(str_contains($file, '- [Liste](https://x.test/a%20b%28c%29)' . "\n"), 'link: space and parentheses in the URL are percent-encoded');
		// An empty title — a Koyomi event without one, a page or a hook entry — used to
		// give "- [](url)", a link that names nothing. The URL is the label then.
		$file = LlmsTxt::render(annai_test_model([
			'pages' => [['title' => '', 'url' => 'https://x.test/leer', 'note' => 'Notiz']],
			'optional' => [['title' => " \n ", 'url' => 'https://x.test/a b', 'note' => '']],
			'events' => [['title' => '', 'url' => 'https://x.test/events/breathing', 'when' => '12. März 2027, 18:00', 'venue' => null, 'cancelled' => false]],
		]));
		holds(!str_contains($file, '- [](') && !str_contains($file, '[]('), 'link: no "- [](" for an empty title');
		holds(str_contains($file, "- [https://x.test/leer](https://x.test/leer): Notiz\n"), 'link: a page with an empty title is labelled with its URL, the note stays');
		holds(str_contains($file, "- [https://x.test/a%20b](https://x.test/a%20b)\n"), 'link: a title of whitespace only counts as empty, the label is the encoded URL');
		holds(str_contains($file, "- [https://x.test/events/breathing](https://x.test/events/breathing): 12. März 2027, 18:00\n"), 'link: an event with an empty title is labelled with its URL');
		// File rules, on the complete German file and on an English one.
		foreach (['de' => $full, 'en' => $en] as $language => $file) {
			holds(!str_contains($file, "\n\n\n"), "$language: no two consecutive blank lines");
			holds(str_ends_with($file, "\n") && !str_ends_with($file, "\n\n"), "$language: ends with exactly one newline");
			preg_match_all('/\]\(([^)\s]+)\)/', $file, $links);
			holds(count($links[1]) > 0, "$language: has links");
			foreach ($links[1] as $href) {
				holds((bool)preg_match('#^https?://#', $href), "$language: link is absolute: $href");
			}
		}
	}
}
