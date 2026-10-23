<?php
/**
 * Text helpers (SPEC.md §3.2, §9.1): whitespace, excerpts, pruning, phone numbers,
 * opening times and escaping.
 *   php tests/TextTest.php
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Text.php';
// phone() needs giggsey/libphonenumber-for-php-lite (composer install); without it the
// phone checks fail with the reason 'library', the rest runs.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Annai\Text;

// line()
expect('Bio-Hofladen mit Café', Text::line("Bio-Hofladen\nmit  Café "), 'line collapses newlines and double spaces');
expect('a b', Text::line("a\n b"), 'line: "a\\n b" becomes "a b"');
expect('', Text::line(" \n\t "), 'line of whitespace only is empty');
expect("M\u{FFFD}ller Hof", Text::line("M\xFCller \n Hof"), 'line: an invalid UTF-8 byte becomes U+FFFD, the text stays');
expect("M\u{FFFD}ller \n Hof", Text::utf8("M\xFCller \n Hof"), 'utf8: an invalid byte becomes U+FFFD, whitespace untouched');
expect("Müller", Text::utf8("Müller"), 'utf8: valid text unchanged');
$before = mb_substitute_character();
Text::line("\xFF");
expect($before, mb_substitute_character(), 'line leaves the global substitute character alone');
// C0 and C1 control characters from a Panel field used to reach <title>, the meta
// description and og:* raw (every other output filters them); line() removes them, and
// excerpt() and plain() inherit it. Whitespace controls (tab, line feed, vertical tab, form
// feed, CR, NEL) still separate words.
expect('abc', Text::line("a\x1bb\x01c"), 'line: ESC and SOH are removed');
expect('a[2Jb', Text::line("a\e[2Jb"), 'line: an escape sequence loses its ESC, the printable rest stays');
expect('ab', Text::line("a\x7Fb"), 'line: DEL is removed');
expect('ab', Text::line("a\u{9B}b"), 'line: a C1 control (CSI) is removed');
expect("a b\u{200B}", Text::line("a\u{85}b\u{200B}"), 'line: NEL is whitespace, a zero-width space is text');
expect('a b c d e', Text::line("a\tb\x0Bc\x0Cd\re"), 'line: tab, vertical tab, form feed and CR separate words');
expect('a b', Text::line("a \x01 b"), 'line: a control between spaces leaves one space');
expect('Gemüse vom…', Text::excerpt("Gemüse\x01 vom \eFeld", 14), 'excerpt inherits the control filter');
expect('Tom & Jerry', Text::plain("<p>Tom &amp;\x02 Jerry</p>"), 'plain inherits the control filter');

// excerpt()
expect('Gemüse vom eigenen Feld.', Text::excerpt('Gemüse vom eigenen Feld.', 155), 'excerpt keeps a short text');
expect('aaaa…', Text::excerpt('aaaa bbbb cccc', 9), 'excerpt cuts at the last space and adds an ellipsis');
expect('x y', Text::excerpt('<b>x</b> y', 10), 'excerpt strips tags');
expect('', Text::excerpt('', 5), 'excerpt of empty text is empty');
expect('Tom & Jerry', Text::excerpt('Tom &amp; Jerry', 155), 'excerpt decodes entities');
// A text without a space used to come back as max + 1 characters (the cut plus the ellipsis); $max
// is the length of the result, ellipsis included.
expect('abcdefg…', Text::excerpt('abcdefghijkl', 8), 'a text without a space keeps the cut, ellipsis within max');
expect(155, mb_strlen(Text::excerpt(str_repeat('a', 200), 155)), '200 letters without a space excerpt to exactly 155 characters');
foreach ([1, 2, 5, 9, 14, 60] as $max) {
    foreach ([str_repeat('a', 200), 'aaaa bbbb cccc dddd eeee', 'Gemüse vom eigenen Feld und Eier vom Hof', 'a b c d e f g h i j k l m n o p'] as $text) {
        holds(mb_strlen(Text::excerpt($text, $max)) <= $max, 'excerpt(' . json_encode(mb_substr($text, 0, 12)) . "…, $max) is at most $max characters");
    }
}
expect('Gemüse vom…', Text::excerpt('Gemüse vom eigenen Feld', 14), 'excerpt counts characters, not bytes');

// plain(): what excerpt() does before it cuts — for a kirbytext() result that becomes text (JSON-LD, llms.txt).
expect('Tom & Jerry x', Text::plain("<p>Tom &amp; Jerry<br>\n x</p>"), 'plain strips tags, decodes entities and collapses whitespace');
expect('Kinder <6 Jahre', Text::plain('<p>Kinder &lt;6 Jahre</p>'), 'plain keeps an encoded "<" as text');
expect('', Text::plain(''), 'plain of empty text is empty');
expect(str_repeat('a ', 200) . 'b', Text::plain('<p>' . str_repeat('a ', 200) . 'b</p>'), 'plain does not shorten');

// prune()
expect(
    ['b' => ['d' => 1], 'e' => [['x']]],
    Text::prune(['a' => '', 'b' => ['c' => null, 'd' => 1], 'e' => [[], ['x']]]),
    'prune removes null, empty strings and empty arrays recursively'
);
expect([['x']], Text::prune([[], ['x']]), 'prune re-indexes lists');
expect(
    ['zero' => 0, 'float' => 0.0, 'false' => false],
    Text::prune(['zero' => 0, 'float' => 0.0, 'false' => false, 'null' => null]),
    'prune keeps 0, 0.0 and false'
);
expect([0, 'a'], Text::prune([0, null, 'a', '']), 'prune keeps 0 inside a list and re-indexes it');
expect([], Text::prune([]), 'prune of an empty array is empty');

// phone() — the input contract (SPEC §3.2): the field holds only the
// number; a character outside the gate gives '' and 'characters', libphonenumber reads the
// rest (parse with the region of the calling code, isValidNumber, E.164).
$phoneCheck = static function (array $cases, string $why): void {
    foreach ($cases as $case) {
        [$input, $expected, $problem] = $case;
        $country = $case[3] ?? '49';
        expect([$expected, $problem], [Text::phone($input, $country), Text::phoneProblem($input, $country)],
            'phone(' . json_encode($input, JSON_UNESCAPED_UNICODE) . ($country !== '49' ? ', ' . $country : '') . '): ' . $why);
    }
};

// The golden table: seven phone and fax fields in common spellings (the numbers are
// fictional), each with the E.164 number libphonenumber 9.0.41 gives.
$phoneCheck([
    ['01512 3456789', '+4915123456789', null], ['030 34567020', '+493034567020', null], ['+49 30 34567040', '+493034567040', null],
    ['030 34567060', '+493034567060', null], ['030 / 34567000', '+493034567000', null], ['+49 1512 3456788', '+4915123456788', null],
    ['030 34567010', '+493034567010', null],
], 'a real field gives its number');

// The phone pair the Kirby-free fixtures and SPEC §3.5 use for a further location (LlmsTxtTest,
// JsonLdTest) is one the contract gives (a pair used before turned out 'invalid' under it).
$phoneCheck([['030 34567070', '+493034567070', null]], 'the fixture pair of a further location is a valid number');

// Within the gate, as operators write a number: every allowed character (SPEC §3.2), a
// leading 00, the trunk in parentheses, the no-break spaces, whitespace around the number.
$phoneCheck([
    ['0049 30 34567000', '+493034567000', null], ['+49 (0)30 34567000', '+493034567000', null], ['+49 (0) 30 34567000', '+493034567000', null],
    ['(030) 34567000', '+493034567000', null], ['0171-1234567', '+491711234567', null], ['+49 30 34567000-0', '+4930345670000', null],
    ["030\u{A0}34567000", '+493034567000', null], ["030\u{202F}34567000", '+493034567000', null], [" 030 34567000\n", '+493034567000', null],
], 'an allowed spelling is read');

// A number from abroad with "+", and a national number read with the configured country.
$phoneCheck([
    ['+43 1 234 56', '+43123456', null], ['+43 (0)1 234 56', '+43123456', null], ['+41 44 123 45 67', '+41441234567', null],
    ['+44 20 7946 0958', '+442079460958', null], ['+39 06 1234 5678', '+390612345678', null], ['+1 (212) 555-0123', '+12125550123', null],
    ['+49 30 34567000', '+493034567000', null, '43'],
    ['01 234 56', '+43123456', null, '43'], ['06 1234 5678', '+390612345678', null, '39'], ['0471 123456', '+390471123456', null, '39'],
    ['(212) 555-0123', '+12125550123', null, '1'],
], 'a foreign number with "+" and a national number of the configured country');

// Outside the gate: text, markup, Kirbytags, notes, a dot, a line break — '' and 'characters',
// also when the field holds a number libphonenumber would find.
$phoneCheck(array_map(static fn (string $field): array => [$field, '', 'characters'], [
    'Tel. 030 34567000', 'Telefon: 030 34567000', '030 34567000 (Zentrale)', 'folgt', 'Fax folgt', 'abc',
    '<a href="tel:+493034567000">030 34567000</a>', '<b>030 34567000</b>', '(tel: 030 34567000)', '(link: tel:+493034567040 text: +49 30 34567040)',
    '030 34567000, 0171 1234567', "030 34567000\n0171 1234567", "030\t34567000", '0351 / 12.12.2010', '089 1234 - 1.10.2026',
    '0351 12345 9.00 Uhr', '030.34567000', '+33.1.23.45.67.89', '030 34567000 ext. 12', '030 +34567000', '(+49) 30 34567000',
    '030 – 34567000', '０３０ ２３１２５０００', "030 34567000\x1b",
]), 'a field with more than the number gives no number');

// Within the gate, but no valid number: '' and 'invalid'.
$phoneCheck(array_map(static fn (array $c): array => [$c[0], '', 'invalid', $c[1] ?? '49'], [
    ['34567'], ['0351'], ['+49'], ['+'], ['0'], ['(0)'], ['-'], ['()'], ['+49 12345678901234'], ['+1 (555) 123-4567'],
    ['030 1'], ['0171 123'], ['030 34567000', '999'], [str_repeat('1', 300)],
]), 'a field within the gate that is no valid number');

// Empty: '' without a reason, nothing to report.
$phoneCheck([['', '', null], ['   ', '', null], ["\n\t", '', null], ["\u{A0}", '', null]], 'an empty field');

// The limit of the contract (SPEC §3.2): a note written only with allowed characters is read as
// libphonenumber reads it. annai-check shows the result before the go-live, so this is the
// documented limit, not a bug.
$phoneCheck([['030 34567000 (12)', '+49303456700012', null], ['030 34567000 0815', '+4930345670000815', null]],
    'digits in allowed characters are read as the number');

// Without the library (Annai copied without Composer): every other field gives '' and
// 'library', the gate still answers first. A child process without the autoloader.
$phoneProbe = 'require ' . var_export(__DIR__ . '/../src/I18n.php', true) . '; require ' . var_export(__DIR__ . '/../src/Text.php', true) . ';'
    . ' $out = []; foreach (["030 34567000", "Tel. 030 34567000", ""] as $field) { $out[] = [Annai\Text::phone($field), Annai\Text::phoneProblem($field)]; }'
    . ' echo json_encode([class_exists("libphonenumber\\\\PhoneNumberUtil"), $out]);';
$process = proc_open([PHP_BINARY, '-r', $phoneProbe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$phoneWithout = null;
if (is_resource($process)) {
    $phoneWithout = json_decode((string) stream_get_contents($pipes[1]), true);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
expect([false, [['', 'library'], ['', 'characters'], ['', null]]], $phoneWithout, 'phone without libphonenumber: "library", the gate first, empty stays silent');

// Run time: the gate is one linear pattern, and libphonenumber gives up after 250 characters.
$phoneStarted = microtime(true);
foreach ([str_repeat('1 ', 25000), str_repeat('(0)', 16666), str_repeat('1a', 25000), '+' . str_repeat('1', 50000)] as $long) {
    Text::phone($long);
    Text::phoneProblem($long);
}
$phoneTime = microtime(true) - $phoneStarted;
holds($phoneTime < 0.25, 'phone: four fields of 50,000 characters take well under a second (' . round($phoneTime * 1000) . ' ms)');

// shown(): a field value for a log line or the check (Business::e164(), Check::render()): a line
// break and every control character as an escape, C1 too (JSON_UNESCAPED_UNICODE keeps it raw).
expect('"030 34567000\\n0171 1234567"', Text::shown("030 34567000\n0171 1234567"), 'shown: a line break as \\n');
expect('"a\\u001bb\\u009bc Büro / ' . "\u{FFFD}" . '"', Text::shown("a\x1bb\u{9B}c Büro / \xFF"), 'shown: ESC and CSI as escapes, letters and "/" as they are, an invalid byte as U+FFFD');

// time() and range()
expect('8:00', Text::time(480), 'time 480 is 8:00');
expect('18:00', Text::time(1080), 'time 1080 is 18:00');
expect('2:00', Text::time(1560), 'time wraps after midnight: 1560 is 2:00');
expect('0:00', Text::time(1440), 'time 1440 is 0:00');
expect('8:05', Text::time(485), 'minutes are zero-padded');
expect('8:00–12:00', Text::range(480, 720), 'range uses an en dash');

// esc() and xml()
expect('&lt;a href=&quot;x&quot;&gt;&amp;', Text::esc('<a href="x">&'), 'esc escapes HTML');
expect('&amp;amp;', Text::esc('&amp;'), 'esc double-encodes an existing entity');
expect("a&amp;b&apos;", Text::xml("a&b'"), 'xml escapes ampersand and apostrophe');
expect('&lt;&gt;', Text::xml('<>'), 'xml escapes angle brackets');
expect("ab", Text::xml("a\x01b"), 'xml drops a C0 control character XML 1.0 does not allow');
expect("a\tb\nc", Text::xml("a\tb\nc"), 'xml keeps tab and line feed');
expect("caf\u{FFFD}", Text::xml("caf\xE9"), 'xml turns a byte that is not UTF-8 into U+FFFD instead of ""');
expect("ab", Text::xml("a\u{FFFE}b"), 'xml drops U+FFFE');

finish('Text');
