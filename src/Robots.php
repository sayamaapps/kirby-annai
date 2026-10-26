<?php

namespace Sayamaapps\Annai;

/**
 * robots.txt per RFC 9309 (docs/SPEC.md §3.3).
 *
 * Plain strings in, string out. An explicitly named user agent gets its own
 * group, and per RFC 9309 §2.2.1 that group is not merged with the `*` group:
 * an allowed agent group therefore repeats the `*` disallows.
 */
final class Robots
{
	/**
	 * Known AI user agents and their role. Compiled 2026-10-11 from the vendor pages
	 * named below and the community list https://github.com/ai-robots-txt/ai.robots.txt
	 * (robots.json). KEEP THIS COMMENT and the sources in the file:
	 *   OpenAI     https://platform.openai.com/docs/bots                       GPTBot (training), ChatGPT-User (user), OAI-SearchBot (search)
	 *   Anthropic  https://support.anthropic.com/en/articles/8896518            ClaudeBot (training), Claude-User (user), Claude-SearchBot (search)
	 *   Google     https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers   Google-Extended (training token)
	 *   Apple      https://support.apple.com/en-us/119829                      Applebot-Extended (training)
	 *   Common Crawl https://commoncrawl.org/ccbot                             CCBot (training)
	 *   Perplexity https://docs.perplexity.ai/guides/bots                      PerplexityBot (search), Perplexity-User (user)
	 *   Meta       https://developers.facebook.com/docs/sharing/webmasters/web-crawlers   meta-externalagent (training), meta-externalfetcher (user)
	 *   Amazon     https://developer.amazon.com/amazonbot                      Amazonbot (training)
	 *   Mistral    https://docs.mistral.ai/robots/                             MistralAI-User (user)
	 *   DuckDuckGo https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot/   DuckAssistBot (search)
	 *   Allen AI   https://allenai.org/crawler                                 AI2Bot (training)
	 *   community list only: Bytespider, cohere-ai, Diffbot, omgili, Timpibot, PanguBot, img2dataset (training), YouBot (search)
	 *
	 * Roles: 'training' (builds models), 'search' (feeds an AI search index),
	 * 'user' (fetches a page because a person asked right now).
	 * docs/agent-ready.md rule: never block 'user' and 'search' agents.
	 */
	public const AI_AGENTS = [
		'GPTBot' => 'training', 'ChatGPT-User' => 'user', 'OAI-SearchBot' => 'search',
		'ClaudeBot' => 'training', 'Claude-User' => 'user', 'Claude-SearchBot' => 'search',
		'Google-Extended' => 'training', 'Applebot-Extended' => 'training', 'CCBot' => 'training',
		'PerplexityBot' => 'search', 'Perplexity-User' => 'user',
		'meta-externalagent' => 'training', 'meta-externalfetcher' => 'user',
		'Amazonbot' => 'training', 'MistralAI-User' => 'user', 'DuckAssistBot' => 'search',
		'AI2Bot' => 'training', 'Bytespider' => 'training', 'cohere-ai' => 'training',
		'Diffbot' => 'training', 'omgili' => 'training', 'Timpibot' => 'training',
		'PanguBot' => 'training', 'img2dataset' => 'training', 'YouBot' => 'search',
	];
	public const PRESETS = [null, 'blockTraining', 'allowAll'];

	/**
	 * Per-agent rule map from the preset and the overrides: agent → true (allow) | false (block).
	 *   preset null            → only $overrides
	 *   preset 'blockTraining' → every 'training' agent false, then $overrides
	 *   preset 'allowAll'      → every known agent true (explicit groups), then $overrides
	 *
	 * @param array<string,bool> $overrides
	 * @return array<string,bool>
	 */
	public static function agents(string|null $preset, array $overrides = []): array
	{
		$map = [];
		foreach (self::AI_AGENTS as $name => $role) {
			if ($preset === 'blockTraining' && $role === 'training') {
				$map[$name] = false;
			} elseif ($preset === 'allowAll') {
				$map[$name] = true;
			}
		}
		// Product tokens are case-insensitive (RFC 9309 §2.2.1): "gptbot" overrides
		// the preset's GPTBot instead of adding a second group next to it.
		foreach ($overrides as $name => $allowed) {
			$name = self::canonical((string)$name, $map);
			$map[$name] = (bool)$allowed;
		}
		return $map;
	}

	/**
	 * The spelling of an agent name compared case-insensitively: the name in
	 * AI_AGENTS ("gptbot" → "GPTBot"), else a name already in $map, else $name.
	 *
	 * @param array<string,bool> $map
	 */
	private static function canonical(string $name, array $map = []): string
	{
		foreach ([...array_keys(self::AI_AGENTS), ...array_keys($map)] as $known) {
			if (strcasecmp((string)$known, $name) === 0) {
				return (string)$known;
			}
		}
		return $name;
	}

	/**
	 * The non-empty disallow paths, trimmed; with $valid only those validPath()
	 * accepts (render() writes only these, warnings() names the others).
	 *
	 * @return list<string>
	 */
	private static function disallow(array $config, bool $valid = true): array
	{
		$paths = [];
		foreach ((array)($config['disallow'] ?? []) as $path) {
			$path = trim((string)$path);
			if ($path !== '' && (!$valid || self::validPath($path))) {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * The extra lines (trimmed right, empty ones dropped), split at the first
	 * User-agent line: [lines for the * group, lines that start groups of their own].
	 * An element with line feeds inside is split into lines first: as one "line"
	 * it never matched the User-agent test, so a whole group written into one
	 * string landed inside the * group and its rules were repeated for every
	 * allowed agent. Split at CR, LF and CRLF only (Text::EOL): `\R` in byte
	 * mode would cut "Disallow: /Åland/" inside the "Å".
	 *
	 * @return array{0: list<string>, 1: list<string>}
	 */
	private static function extra(array $config): array
	{
		$lines = [];
		foreach ((array)($config['extra'] ?? []) as $element) {
			array_push($lines, ...(preg_split(Text::EOL, (string)$element) ?: []));
		}
		$rules = [];
		$groups = [];
		foreach ($lines as $line) {
			$line = rtrim($line);
			if ($line === '') {
				continue;
			}
			if ($groups === [] && preg_match('/^\s*user-agent\s*:/i', $line) !== 1) {
				$rules[] = $line;
			} else {
				$groups[] = $line;
			}
		}
		return [$rules, $groups];
	}

	/**
	 * Minimal parser for the check tool: groups of user agents and their rules,
	 * and the Sitemap records, per RFC 9309: comments and blank lines are
	 * ignored (a blank line does not end a group), consecutive User-agent lines
	 * share one group, a User-agent line after a rule starts the next group,
	 * field names are case-insensitive.
	 *
	 * @return array{groups: list<array{agents: list<string>, rules: list<array{type: string, path: string}>}>, sitemaps: list<string>}
	 */
	public static function parse(string $text): array
	{
		$groups = [];
		$sitemaps = [];
		$current = null;
		foreach (preg_split(Text::EOL, $text) ?: [] as $raw) {
			// RFC 9309 §2.2: blank lines and comments do not end a group; only a
			// User-agent line after a rule starts a new one.
			$line = trim(explode('#', $raw, 2)[0]);
			if ($line === '') {
				continue;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$field = strtolower(trim(substr($line, 0, $colon)));
			$value = trim(substr($line, $colon + 1));
			if ($field === 'user-agent') {
				if ($current === null || $groups[$current]['rules'] !== []) {
					$groups[] = ['agents' => [], 'rules' => []];
					$current = count($groups) - 1;
				}
				$groups[$current]['agents'][] = $value;
			} elseif ($field === 'allow' || $field === 'disallow') {
				if ($current !== null) {
					$groups[$current]['rules'][] = ['type' => $field, 'path' => $value];
				}
			} elseif ($field === 'sitemap') {
				$sitemaps[] = $value;
			}
		}
		return ['groups' => $groups, 'sitemaps' => $sitemaps];
	}

	/**
	 * The robots.txt file. $config keys: disallow (list), sitemap (?string),
	 * agents (agent → bool, from agents()), extra (raw lines as their own block),
	 * blockAll (bool, Pages::hidden(): everything closed). A "/" in disallow makes the
	 * * group "Disallow: /" alone (no Allow: /, which would win the tie).
	 */
	public static function render(array $config): string
	{
		if (!empty($config['blockAll'])) {
			return "User-agent: *\nDisallow: /\n";
		}
		$disallow = self::disallow($config);
		$agents = (array)($config['agents'] ?? []);
		[$rules, $groups] = self::extra($config);
		$blocks = [];

		// "/" among the paths closes the site for everyone. Next to "Allow: /"
		// it would not: the two rules are of equal length, and RFC 9309 §2.2.2
		// (Google alike) lets the Allow win, so the group blocked nothing while
		// warnings() said "Disallow: / for everyone". The group is then the
		// closed one, as blockAll writes it; the other paths are covered by it.
		$closed = in_array('/', $disallow, true);
		$star = $closed ? ['User-agent: *', 'Disallow: /'] : ['User-agent: *', 'Allow: /'];
		foreach ($closed ? [] : $disallow as $path) {
			$star[] = 'Disallow: ' . $path;
		}
		// Under RFC 9309 a blank line does not end a group: extra lines after the
		// last agent group would belong to that agent (Disallow: /intern for
		// ClaudeBot only). With agent groups the extra lines therefore go into
		// the * group, and their rules are repeated in every allowed agent
		// group like the disallows. Without agent groups the file stays as
		// before: the extra lines are their own block, which RFC 9309 reads as
		// part of the * group anyway.
		if ($agents !== []) {
			array_push($star, ...$rules);
		}
		$blocks[] = implode("\n", $star);

		$blocked = array_keys(array_filter($agents, fn (bool $allowed) => !$allowed));
		$allowed = array_keys(array_filter($agents, fn (bool $allowed) => $allowed));
		sort($blocked, SORT_STRING);
		sort($allowed, SORT_STRING);
		foreach ($blocked as $name) {
			$blocks[] = implode("\n", ['User-agent: ' . $name, 'Disallow: /']);
		}
		foreach ($allowed as $name) {
			// An allowed agent is allowed: it repeats the * disallows except "/",
			// which would stand against its Allow: / (and lose the tie anyway).
			$group = ['User-agent: ' . $name, 'Allow: /'];
			foreach ($disallow as $path) {
				if ($path !== '/') {
					$group[] = 'Disallow: ' . $path;
				}
			}
			foreach ($rules as $line) {
				if (!str_starts_with(ltrim($line), '#')) {
					$group[] = $line;
				}
			}
			$blocks[] = implode("\n", $group);
		}

		if ($agents === [] && $rules !== []) {
			$blocks[] = implode("\n", $rules);
		}
		// Extra lines from the first User-agent line on are groups of their own.
		if ($groups !== []) {
			$blocks[] = implode("\n", $groups);
		}

		$sitemap = trim((string)($config['sitemap'] ?? ''));
		if ($sitemap !== '') {
			$blocks[] = 'Sitemap: ' . $sitemap;
		}

		return implode("\n\n", $blocks) . "\n";
	}

	/**
	 * A Disallow/Allow value that works as a path (RFC 9309 §2.2.2): it starts
	 * with "/" (a rule without it matches nothing), holds no "#" (a comment
	 * starts there, the rest of the line is dropped), no space (the path ABNF
	 * UTF8-1-noctl is %x21 / %x22 / %x24-7F: a space ends the path for a strict
	 * parser, "/my path" is no rule) and no control character (a line feed
	 * would write a rule of its own, a tab or escape is noise), and it is
	 * UTF-8 (RFC 9309 §2.2: the file MUST be UTF-8; one Latin-1 byte in a path
	 * makes the whole file a non-UTF-8 file).
	 */
	public static function validPath(string $path): bool
	{
		return $path !== '' &&
			$path[0] === '/' &&
			!str_contains($path, '#') &&
			preg_match('/[\x00-\x20\x7F]/', $path) !== 1 &&
			mb_check_encoding($path, 'UTF-8');
	}

	/**
	 * Problems a site owner should know about (English: developer tool text):
	 *  - a blocked user or search agent (Claude-User, OAI-SearchBot, …);
	 *  - "Disallow: / for everyone" when blockAll or the * group blocks "/";
	 *  - a disallow entry validPath() rejects, with every rule of a path named
	 *    (the UTF-8 rule too: for "/b\xE4ckerei" none of the others holds).
	 *
	 * @return list<string>
	 */
	public static function warnings(array $config): array
	{
		$warnings = [];
		if (!empty($config['blockAll'])) {
			return ['Disallow: / for everyone'];
		}
		if (in_array('/', self::disallow($config), true)) {
			$warnings[] = 'Disallow: / for everyone';
		}
		foreach (self::disallow($config, false) as $path) {
			if (!self::validPath($path)) {
				$warnings[] = 'Disallow: ' . Text::shown($path)
					. ' left out: a path starts with / and holds no space, # or control character, and is UTF-8';
			}
		}
		$agents = (array)($config['agents'] ?? []);
		$blocked = array_keys(array_filter($agents, fn (bool $allowed) => !$allowed));
		sort($blocked, SORT_STRING);
		foreach ($blocked as $name) {
			$role = self::AI_AGENTS[self::canonical((string)$name)] ?? null;
			if ($role === 'user' || $role === 'search') {
				$warnings[] = sprintf(
					"Blocks %s (%s): agents in the user's service and AI search engines should stay allowed",
					$name,
					$role
				);
			}
		}
		return $warnings;
	}
}
