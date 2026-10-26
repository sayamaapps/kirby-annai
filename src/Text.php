<?php

namespace Annai;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Kirby-free text helpers (docs/SPEC.md §3.2). phone() needs
 * giggsey/libphonenumber-for-php-lite (composer.json `require`).
 */
final class Text
{
	/**
	 * The pattern that splits user text into lines: CR, LF or CRLF. Never
	 * `\R` without `/u`: in byte mode `\R` matches NEL = 0x85 as well, the
	 * second byte of "Å" (C3 85), "ą" (C4 85) and of many CJK characters
	 * ("内" = E5 86 85), so a UTF-8 text would be cut inside a character.
	 * `/u` is no answer either: on a byte that is not UTF-8 preg_split() gives
	 * false, and `?: []` would lose every line.
	 */
	public const EOL = '/\r\n|\r|\n/';

	/**
	 * The character gate of the input contract (SPEC §3.2): after trim() the field holds
	 * only these, each for a reason —
	 *   digits "0"–"9"   the number;
	 *   " "              between groups: "030 34567000", "0171 123 45 67";
	 *   "+" first        the country code: "+49 30 34567000" ("0049 …" needs none);
	 *   "/"              between area code and number: "030 / 34567000";
	 *   "-"              between groups and before an extension: "0171-1234567", "34567-0";
	 *   "(" and ")"      around the trunk prefix or the area code: "+49 (0)30 34567000",
	 *                    "(030) 34567000".
	 * Not ".": dates and times are digits and dots ("12.12.2010", "9.00"), and with the dot
	 * they would pass the gate — libphonenumber reads "0351 / 12.12.2010" as +4935112122010,
	 * a valid German number. "030.34567000" gives '' and 'characters'. No letter ("Tel.",
	 * "ext.", a Kirbytag, HTML, a note), no line break, no tab, no other dash. A no-break space
	 * counts as " " (PHONE_SPACES).
	 */
	private const PHONE_GATE = '/^\+?[0-9 \/()-]*\z/';

	/**
	 * The no-break spaces U+00A0 and U+202F count as " ": they look the same in the Panel, and
	 * a number copied from a web page or Word carries them.
	 */
	private const PHONE_SPACES = ["\u{A0}", "\u{202F}"];

	/** HTML escaping for text and attributes. */
	public static function esc(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Plain-text excerpt like Kirby's Str::excerpt(): strip tags, decode
	 * entities, collapse whitespace; cut to $max characters, drop the last
	 * partial word and append "…". The result is never longer than $max,
	 * ellipsis included: a text without a space in the cut keeps $max - 1
	 * characters plus the ellipsis (it used to come back as $max + 1).
	 */
	public static function excerpt(string $text, int $max): string
	{
		$plain = self::plain($text);
		if (mb_strlen($plain, 'UTF-8') <= $max) {
			return $plain;
		}
		$cut = mb_substr($plain, 0, max(0, $max), 'UTF-8');
		$space = mb_strrpos($cut, ' ', 0, 'UTF-8');
		if ($space !== false && $space > 0) {
			$cut = mb_substr($cut, 0, $space, 'UTF-8');
		} else {
			$cut = mb_substr($cut, 0, max(0, $max - 1), 'UTF-8');
		}
		return rtrim($cut) . '…';
	}

	/**
	 * Collapses all whitespace (incl. newlines) to one space and trims.
	 * Bytes that are not valid UTF-8 (a Latin-1 "ü" from an import) become
	 * U+FFFD, as in the JSON-LD, instead of emptying the whole text. C0 and C1
	 * control characters that are not whitespace (ESC, DEL, CSI …) are removed,
	 * the class of I18n::printable(): they used to reach <title>, the meta
	 * description and og:* raw from a Panel field, while every other output
	 * filtered them. excerpt() and plain() inherit it.
	 */
	public static function line(string $text): string
	{
		// Controls first: a tab, a line feed, a vertical tab or NEL is whitespace and separates words below.
		$text = (string)preg_replace('/(?!\s)[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', '', self::utf8($text));
		return trim((string)preg_replace('/\s+/u', ' ', $text));
	}

	/**
	 * The E.164 number of a phone or fax field ("+493034567000"), or '' (SPEC §3.2, the input
	 * contract). The field holds the number and nothing else: trimmed, only
	 * the characters of PHONE_GATE. A field with any other character — a letter, markup, a
	 * Kirbytag, a note, a dot, a line break — gives '' and the reason 'characters'
	 * (phoneProblem()), whatever digits it holds: Annai does not look for a number in text.
	 * A field within the gate is read by Google's libphonenumber
	 * (giggsey/libphonenumber-for-php-lite): parse() with the region of $country (a calling
	 * code, `annai.phoneCountry`: '49' → DE), isValidNumber(), format(E164). A field it cannot
	 * parse or that is no valid number gives '' and 'invalid'. An empty field gives '' without
	 * a reason. Without the library (Annai copied without Composer) every other field gives ''
	 * and 'library'. Takes the raw field value; Business::e164() logs every reason.
	 */
	public static function phone(string $display, string $country = '49'): string
	{
		return self::phoneRead($display, $country)[0];
	}

	/**
	 * Why phone() gives '' for a field that is not empty: 'characters' (a character outside
	 * PHONE_GATE), 'invalid' (libphonenumber cannot parse it, or it is no valid number),
	 * 'library' (libphonenumber is not installed). null when phone() gives a number or the
	 * field is empty (spaces only count as empty).
	 */
	public static function phoneProblem(string $display, string $country = '49'): string|null
	{
		return self::phoneRead($display, $country)[1];
	}

	/**
	 * Reads the field: ['+' and the E.164 digits, null] or ['', why not]; ['', null] for an
	 * empty field.
	 *
	 * @return array{0: string, 1: ?string}
	 */
	private static function phoneRead(string $display, string $country): array
	{
		$field = trim(str_replace(self::PHONE_SPACES, ' ', $display));
		if ($field === '') {
			return ['', null];
		}
		if (preg_match(self::PHONE_GATE, $field) !== 1) {
			return ['', 'characters'];
		}
		if (!class_exists(PhoneNumberUtil::class)) {
			return ['', 'library'];
		}
		$util = PhoneNumberUtil::getInstance();
		try {
			$number = $util->parse($field, $util->getRegionCodeForCountryCode((int)$country));
		} catch (NumberParseException) {
			return ['', 'invalid'];
		}
		return $util->isValidNumber($number) ? [$util->format($number, PhoneNumberFormat::E164), null] : ['', 'invalid'];
	}

	/**
	 * Rendered HTML (a kirbytext() result) as one line of plain text: tags
	 * stripped, entities decoded, whitespace collapsed — what excerpt() does
	 * before it cuts, for text that is not shortened (the JSON-LD description,
	 * the llms.txt summary). Takes HTML, not plain text: strip_tags() would eat
	 * a literal "<6 Jahre", which kirbytext() delivers as "&lt;6 Jahre".
	 */
	public static function plain(string $html): string
	{
		return self::line(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * Removes null, '' and [] recursively; lists stay lists. Keeps 0, 0.0 and false.
	 */
	public static function prune(array $data): array
	{
		$result = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$value = self::prune($value);
				if ($value === []) {
					continue;
				}
			} elseif ($value === null || $value === '') {
				continue;
			}
			$result[$key] = $value;
		}
		return array_is_list($data) ? array_values($result) : $result;
	}

	/** "8:00–12:00" (en dash U+2013). */
	public static function range(int $from, int $to): string
	{
		return self::time($from) . '–' . self::time($to);
	}

	/**
	 * A field value as a JSON string for a log line or the check ("030 34567000\n0171 1234567"):
	 * every control character shows as an escape — C0 by json_encode(), C1 (U+0080–U+009F,
	 * which JSON_UNESCAPED_UNICODE would keep raw) here — so a line break is visible and an
	 * escape sequence from a Panel field cannot reach a terminal. Invalid UTF-8 becomes U+FFFD.
	 */
	public static function shown(string $value): string
	{
		$json = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return (string)preg_replace_callback('/[\x{80}-\x{9F}]/u', static fn (array $c): string => sprintf('\\u%04x', mb_ord($c[0], 'UTF-8')), $json);
	}

	/** "G:i" without leading hour zero, modulo one day. */
	public static function time(int $minutes): string
	{
		$m = (($minutes % 1440) + 1440) % 1440;
		return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
	}

	/**
	 * Bytes that are not valid UTF-8 become U+FFFD; valid text is returned
	 * unchanged. The global mb_substitute_character() is restored afterwards.
	 * Needed before kirbytext(): its parser turns a text with one Latin-1 byte
	 * into "<p></p>".
	 */
	public static function utf8(string $text): string
	{
		if (preg_match('//u', $text) === 1) {
			return $text;
		}
		$substitute = mb_substitute_character();
		mb_substitute_character(0xFFFD);
		$text = mb_scrub($text, 'UTF-8');
		mb_substitute_character($substitute);
		return $text;
	}

	/**
	 * XML escaping for sitemap text nodes and attributes. A byte that is not
	 * UTF-8 becomes U+FFFD (ENT_SUBSTITUTE; without it the whole value would be
	 * ''), and characters XML 1.0 does not allow at all (C0 controls except tab,
	 * LF and CR, U+FFFE, U+FFFF) are removed: no escape makes them legal, and
	 * one of them makes the whole file unreadable.
	 */
	public static function xml(string $text): string
	{
		$escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $escaped);
	}
}
