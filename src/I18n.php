<?php

namespace Sayamaapps\Annai;

/**
 * Strings of the plugin, Kirby-free. (docs/SPEC.md §3.1, §5).
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `annai.<key>` (index.php). Annai reads its
 * own tables: a missing Kirby translation must never silently turn a German
 * llms.txt English.
 *
 * The language is set once per request (Options::applyLanguage()) and
 * defaults to English. Missing keys fall back to English, then to the key.
 *
 * @package   Kirby Annai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-annai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class I18n
{
	/** Languages Annai ships. English is the default and the fallback. */
	public const LANGUAGES = ['en', 'de'];

	/** @var array<string,string> Überschriften aus annai.labels, je Anfrage gesetzt. */
	private static array $labels = [];
	private static string $language = 'en';

	/** @var array<string, array<string,string>> */
	private static array $tables = [];

	/**
	 * A date in the current language ("12 Mar 2027" / "12.3.2027"). Accepts a
	 * DateTimeInterface or "Y-m-d" (longer strings are cut to the date part);
	 * an unparsable string comes back unchanged.
	 */
	public static function date(\DateTimeInterface|string $date): string
	{
		if (is_string($date)) {
			$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10));
			if ($parsed === false) {
				return $date;
			}
			$date = $parsed;
		}
		return $date->format(self::t('format.date'));
	}

	/** Full weekday name, $weekday 0 = Monday … 6 = Sunday. */
	public static function day(int $weekday): string
	{
		return self::t('day.' . ((($weekday % 7) + 7) % 7 + 1));
	}

	public static function language(): string
	{
		return self::$language;
	}

	/**
	 * Text without C0/C1 control characters (keeps tab and line feed), for
	 * terminal output: an escape sequence from a Panel field or a page id
	 * cannot rewrite the terminal. Bytes that are not valid UTF-8 become "?"
	 * first, so a raw C1 byte (0x9B = CSI on 8-bit terminals) cannot slip
	 * through either.
	 */
	public static function printable(string $text): string
	{
		return (string)preg_replace('/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u', '', mb_scrub($text, 'UTF-8'));
	}

	/**
	 * Replaces texts by key (`annai.labels`). Options checks the keys and the
	 * placeholders before it calls this.
	 *
	 * @param array<string,string> $labels
	 */
	public static function setLabels(array $labels): void
	{
		self::$labels = $labels;
	}

	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/** `{name}` placeholders are replaced with the given values, unescaped. */
	public static function t(string $key, array $values = []): string
	{
		$text = self::$labels[$key] ?? self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
		if ($values === []) {
			return $text;
		}
		$pairs = [];
		foreach ($values as $name => $value) {
			$pairs['{' . $name . '}'] = (string)$value;
		}
		return strtr($text, $pairs);
	}

	/** @return array<string,string> */
	public static function table(string $language): array
	{
		if (!in_array($language, self::LANGUAGES, true)) {
			return [];
		}
		return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
	}
}
