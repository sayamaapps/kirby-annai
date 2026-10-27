<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\I18n;

/**
 * Translations: every language has the same keys and placeholders as
 * English, no value is empty, and the helpers follow the language.
 *   php tests/I18nTest.php
 */
#[CoversClass(I18n::class)]
final class I18nTest extends TestCase
{
	public function testSuite(): void
	{
		$placeholders = function (string $text): array {
			preg_match_all('/\{(\w+)\}/', $text, $m);
			$names = array_unique($m[1]);
			sort($names);
			return $names;
		}
		;
		$en = I18n::table('en');
		holds(count($en) >= 24, 'English table loaded');
		foreach (I18n::LANGUAGES as $language) {
			$table = I18n::table($language);
			expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
			expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
			foreach ($en as $key => $text) {
				holds(isset($table[$key]) && is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
				expect($placeholders($text), $placeholders($table[$key] ?? ''), "$language.$key: same placeholders as English");
			}
		}
		expect([], I18n::table('fr'), 'unknown language: no table');
		// Every key the code relies on exists (docs/SPEC.md §5).
		foreach (['format.date', 'llms.contact', 'llms.address', 'llms.phone', 'llms.email', 'llms.action', 'llms.hours',
			'llms.hoursLine', 'llms.closed', 'llms.holidaysClosed', 'llms.onlyHours', 'llms.range', 'llms.events',
			'llms.cancelled', 'llms.pages', 'llms.optional', 'llms.home'] as $key) {
			holds(isset($en[$key]), "key $key exists");
		}
		for ($i = 1; $i <= 7; $i++) {
			holds(isset($en["day.$i"]), "weekday $i exists");
		}
		I18n::setLanguage('fr');
		expect('en', I18n::language(), 'unknown language falls back to English');
		expect('Contact', I18n::t('llms.contact'), 'English default');
		expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
		expect('only 8:30–12:00', I18n::t('llms.onlyHours', ['hours' => '8:30–12:00']), 'placeholders replaced');
		expect('Monday', I18n::day(0), 'day(0) is Monday');
		expect('Sunday', I18n::day(6), 'day(6) is Sunday');
		expect('Monday', I18n::day(7), 'day(7) wraps to Monday');
		expect('12 Mar 2027', I18n::date('2027-03-12'), 'English date');
		expect('12 Mar 2027', I18n::date('2027-03-12 18:00:00'), 'date part of a longer string');
		I18n::setLanguage('de');
		expect('de', I18n::language(), 'German selected');
		expect('Kontakt', I18n::t('llms.contact'), 'German string');
		expect('8:00–12:00 Uhr', I18n::t('llms.hoursLine', ['hours' => '8:00–12:00']), 'German hours line adds Uhr');
		expect('Montag', I18n::day(0), 'German weekday');
		expect('12.3.2027', I18n::date('2027-03-12'), 'German date');
		expect('12.3.2027', I18n::date(new DateTimeImmutable('2027-03-12')), 'German date from an object');
		expect('nonsense', I18n::date('nonsense'), 'unparsable date string comes back unchanged');
		I18n::setLanguage('en');
		// printable(): terminal-safe text for the check tool.
		expect("a\tb\nc", I18n::printable("a\tb\nc"), 'printable keeps tab and line feed');
		expect('Hof[2J', I18n::printable("Hof\e[2J"), 'printable drops ESC');
		expect('Hof31m', I18n::printable("Hof\u{9B}31m"), 'printable drops C1 (U+009B CSI)');
		expect('Hof?31m', I18n::printable("Hof\x9b31m"), 'printable turns a raw C1 byte (invalid UTF-8) into ?');
		expect('Gem?se[2J', I18n::printable("Gem\xfcse\e[2J"), 'printable on Latin-1 text: invalid byte ?, ESC dropped');
		expect('Gemüse', I18n::printable('Gemüse'), 'printable leaves valid UTF-8 alone');
		// annai.labels: Überschrift ersetzen, Rückfall auf die Tabelle nach dem Zurücksetzen
		I18n::setLabels(['llms.hours' => 'Sprechzeiten']);
		expect('Sprechzeiten', I18n::t('llms.hours'), 'label replaces the table text');
		I18n::setLabels([]);
		expect($en['llms.hours'], I18n::t('llms.hours'), 'label reset: table text again');
	}
}
