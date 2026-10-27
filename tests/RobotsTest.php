<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Robots;

/**
 * robots.txt (docs/SPEC.md §3.3, §9.2): RFC 9309 groups, AI agent presets, warnings and parsing.
 *   vendor/bin/phpunit tests/RobotsTest.php
 */
#[CoversClass(Robots::class)]
final class RobotsTest extends TestCase
{
	public function testSuite(): void
	{
		// Default file: one * group and a sitemap line.
		expect(
			"User-agent: *\nAllow: /\nDisallow: /panel\n\nSitemap: https://x/sitemap.xml\n",
			Robots::render(['disallow' => ['/panel'], 'sitemap' => 'https://x/sitemap.xml']),
			'default config renders the * group and the sitemap line'
		);
		expect(
			"User-agent: *\nAllow: /\n",
			Robots::render(['disallow' => []]),
			'empty disallow list gives only Allow'
		);
		expect(
			"User-agent: *\nAllow: /\nDisallow: /panel\nDisallow: /anruf\n",
			Robots::render(['disallow' => ['/panel', '/anruf'], 'sitemap' => null]),
			'two disallows, no sitemap line'
		);
		expect(
			"User-agent: *\nAllow: /\n",
			Robots::render(['disallow' => ['  ', ''], 'sitemap' => '']),
			'empty paths and an empty sitemap are skipped'
		);
		// Agent presets.
		$block = Robots::agents('blockTraining');
		expect(false, $block['GPTBot'] ?? null, 'blockTraining blocks GPTBot');
		holds(!array_key_exists('ChatGPT-User', $block), 'blockTraining leaves user agents out');
		holds(!array_key_exists('OAI-SearchBot', $block), 'blockTraining leaves search agents out');
		expect(true, Robots::agents('blockTraining', ['GPTBot' => true])['GPTBot'], 'override wins over blockTraining');
		$all = Robots::agents('allowAll');
		expect(count(Robots::AI_AGENTS), count($all), 'allowAll has one entry per known agent');
		holds(!in_array(false, $all, true), 'allowAll allows every known agent');
		expect(['FooBot' => false], Robots::agents(null, ['FooBot' => false]), 'null preset keeps unknown overrides');
		expect([], Robots::agents(null), 'null preset without overrides is empty');
		expect(['ClaudeBot' => true], Robots::agents(null, ['ClaudeBot' => 1]), 'override values are cast to bool');
		// Agent groups: blocked before allowed, each set sorted; an allowed group repeats the * disallows.
		$text = Robots::render([
			'disallow' => ['/panel'],
			'agents' => ['ChatGPT-User' => true, 'GPTBot' => false, 'CCBot' => false, 'ClaudeBot' => false],
			'sitemap' => 'https://x/sitemap.xml',
		]);
		expect(
			"User-agent: *\nAllow: /\nDisallow: /panel\n\n"
			. "User-agent: CCBot\nDisallow: /\n\n"
			. "User-agent: ClaudeBot\nDisallow: /\n\n"
			. "User-agent: GPTBot\nDisallow: /\n\n"
			. "User-agent: ChatGPT-User\nAllow: /\nDisallow: /panel\n\n"
			. "Sitemap: https://x/sitemap.xml\n",
			$text,
			'agent groups: blocked sorted first, allowed group repeats the * disallows'
		);
		// Extra lines form their own block; blockAll is exact.
		expect(
			"User-agent: *\nAllow: /\n\n# comment\nCrawl-delay: 5\n\nSitemap: https://x/s.xml\n",
			Robots::render(['extra' => ['# comment', 'Crawl-delay: 5  ', ''], 'sitemap' => 'https://x/s.xml']),
			'extra lines are one block between the groups and the sitemap line'
		);
		expect(
			"User-agent: *\nDisallow: /\n",
			Robots::render(['blockAll' => true, 'disallow' => ['/panel'], 'sitemap' => 'https://x/s.xml',
				'agents' => ['GPTBot' => true], 'extra' => ['Crawl-delay: 5']]),
			'blockAll gives exactly the closed file'
		);
		// With agent groups the extra lines belong to the * group (RFC 9309: a blank line
		// does not end a group, so after the last agent group they would be that agent's),
		// and their rules are repeated in every allowed agent group.
		$withAgents = Robots::render(['disallow' => ['/panel'], 'agents' => ['GPTBot' => false, 'ClaudeBot' => true],
			'extra' => ['# Interner Bereich', 'Disallow: /intern', 'User-agent: FooBot', 'Disallow: /'], 'sitemap' => 'https://x/s.xml']);
		expect(
			"User-agent: *\nAllow: /\nDisallow: /panel\n# Interner Bereich\nDisallow: /intern\n\n"
			. "User-agent: GPTBot\nDisallow: /\n\n"
			. "User-agent: ClaudeBot\nAllow: /\nDisallow: /panel\nDisallow: /intern\n\n"
			. "User-agent: FooBot\nDisallow: /\n\n"
			. "Sitemap: https://x/s.xml\n",
			$withAgents,
			'extra with agents: rules in the * group and repeated for allowed agents, own groups after the agent groups'
		);
		$groups = Robots::parse($withAgents)['groups'];
		expect(['ClaudeBot'], $groups[2]['agents'] ?? null, 'extra with agents: parsed group 3 is ClaudeBot');
		expect(
			[['type' => 'allow', 'path' => '/'], ['type' => 'disallow', 'path' => '/panel'], ['type' => 'disallow', 'path' => '/intern']],
			$groups[2]['rules'] ?? null,
			'extra with agents: ClaudeBot keeps /intern closed and gets no rule meant for FooBot'
		);
		expect(
			[['type' => 'disallow', 'path' => '/panel'], ['type' => 'disallow', 'path' => '/intern']],
			array_values(array_filter($groups[0]['rules'] ?? [], fn ($rule) => $rule['type'] === 'disallow')),
			'extra with agents: the * group holds Disallow: /intern'
		);
		// An extra element with line feeds inside is split into lines before the
		// grouping. As one "line" it would never match the User-agent test, so a whole group
		// written into one string would land inside the * group and its rules would be
		// repeated in every allowed agent group.
		$multiline = Robots::render(['disallow' => ['/panel'], 'agents' => ['GPTBot' => false, 'ClaudeBot' => true],
			'extra' => ["# reason\nUser-agent: Googlebot\r\nDisallow: /", 'Crawl-delay: 5'], 'sitemap' => 'https://x/s.xml']);
		expect(
			"User-agent: *\nAllow: /\nDisallow: /panel\n# reason\n\n"
			. "User-agent: GPTBot\nDisallow: /\n\n"
			. "User-agent: ClaudeBot\nAllow: /\nDisallow: /panel\n\n"
			. "User-agent: Googlebot\nDisallow: /\nCrawl-delay: 5\n\n"
			. "Sitemap: https://x/s.xml\n",
			$multiline,
			'extra with line feeds inside an element: split into lines, the Googlebot group is a group of its own'
		);
		$multilineGroups = Robots::parse($multiline)['groups'];
		expect(['Googlebot'], $multilineGroups[3]['agents'] ?? null, 'extra with line feeds: parsed group 4 is Googlebot');
		expect([['type' => 'allow', 'path' => '/'], ['type' => 'disallow', 'path' => '/panel']], $multilineGroups[2]['rules'] ?? null, 'extra with line feeds: ClaudeBot gets no rule meant for Googlebot');
		holds(!str_contains(implode("\n", $multilineGroups[0]['agents']), 'Googlebot'), 'extra with line feeds: the * group holds no second User-agent line');
		// No consecutive blank lines, exactly one trailing newline.
		$full = Robots::render(['disallow' => ['/a'], 'agents' => ['GPTBot' => false, 'ChatGPT-User' => true],
			'extra' => ['# x'], 'sitemap' => 'https://x/s.xml']);
		holds(!str_contains($full, "\n\n\n"), 'no two consecutive blank lines');
		holds(str_ends_with($full, "\n") && !str_ends_with($full, "\n\n"), 'ends with exactly one newline');
		// warnings()
		expect(
			["Blocks Claude-User (user): agents in the user's service and AI search engines should stay allowed"],
			Robots::warnings(['agents' => ['Claude-User' => false]]),
			'blocking a user agent gives one warning naming it and its role'
		);
		expect(
			["Blocks OAI-SearchBot (search): agents in the user's service and AI search engines should stay allowed"],
			Robots::warnings(['agents' => ['OAI-SearchBot' => false]]),
			'blocking a search agent gives a warning'
		);
		expect([], Robots::warnings(['agents' => ['GPTBot' => false]]), 'blocking a training agent gives no warning');
		expect([], Robots::warnings(['disallow' => ['/panel']]), 'a plain config gives no warning');
		expect(['Disallow: / for everyone'], Robots::warnings(['blockAll' => true]), 'blockAll warns');
		expect(['Disallow: / for everyone'], Robots::warnings(['disallow' => ['/']]), 'a * group blocking / warns');
		// "/" in disallow. "Allow: /" and "Disallow: /" are rules of equal length,
		// and RFC 9309 §2.2.2 (Google too) lets the Allow win: the * group used to block nothing
		// while the check said "Disallow: / for everyone". The group is now the closed one;
		// an explicitly allowed agent keeps its Allow: / and repeats the other paths only.
		expect(
			"User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: ChatGPT-User\nAllow: /\nDisallow: /panel\n\nSitemap: https://x/s.xml\n",
			Robots::render(['disallow' => ['/panel', '/'], 'agents' => ['ChatGPT-User' => true, 'GPTBot' => false], 'sitemap' => 'https://x/s.xml']),
			'disallow "/": the * group is "Disallow: /" alone (no Allow: /), an allowed agent keeps Allow: / and the other paths'
		);
		expect("User-agent: *\nDisallow: /\n", Robots::render(['disallow' => ['/']]), 'disallow "/" alone: the closed group, as blockAll writes it');
		expect(
			[['type' => 'disallow', 'path' => '/']],
			Robots::parse(Robots::render(['disallow' => ['/'], 'extra' => ['# geschlossen'], 'agents' => ['CCBot' => false]]))['groups'][0]['rules'] ?? null,
			'disallow "/": parsed, the * group holds no allow rule that would win the tie'
		);
		expect(['Disallow: / for everyone'], Robots::warnings(['disallow' => ['/panel', '/']]), 'disallow "/" with other paths still warns');
		// A disallow path that is not a path (RFC 9309 §2.2.2: starts with "/"; "#" starts a
		// comment; a line feed would write a rule of its own) is left out and warned.
		$badPaths = ['panel', '/intern#geheim', "/a\nAllow: /b", "/tab\there", '/my path', '/panel'];
		expect(
			"User-agent: *\nAllow: /\nDisallow: /panel\n",
			Robots::render(['disallow' => $badPaths]),
			'render: only a valid path is written'
		);
		$pathWarnings = Robots::warnings(['disallow' => $badPaths]);
		expect(5, count($pathWarnings), 'warnings: one per invalid disallow path');
		holds(str_contains($pathWarnings[0] ?? '', 'Disallow: "panel"') && str_contains($pathWarnings[0] ?? '', 'left out'), 'warnings: names the path without a leading slash');
		holds(str_contains($pathWarnings[1] ?? '', '"/intern#geheim"'), 'warnings: names the path with #');
		holds(str_contains($pathWarnings[2] ?? '', '"/a\nAllow: /b"') && !str_contains(implode("\n", $pathWarnings), "/a\nAllow"), 'warnings: the line feed of a path is printed as \n, not raw');
		// RFC 9309 §2.2.2 excludes the space from a path (UTF8-1-noctl is %x21 / %x22 / %x24-7F): "/my path" is no rule.
		expect('Disallow: "/my path" left out: a path starts with / and holds no space, # or control character, and is UTF-8', $pathWarnings[4] ?? null, 'warnings: a path with a space is named, the text says what a path is');
		foreach (['/', '/panel', '/a%20b', '/*.pdf$', '/ümlaut', '/"quoted"'] as $path) {
			holds(Robots::validPath($path), "validPath: $path is a path");
		}
		foreach (['', 'panel', '#', '/x#y', "/x\ny", "/x\ry", "/x\x7F", "/x\x1B[2J", '/a b', '/my path', '/ '] as $path) {
			holds(!Robots::validPath($path), 'validPath: ' . json_encode($path) . ' is not');
		}
		// RFC 9309 §2.2 — the file MUST be UTF-8. A Latin-1 byte in a path used to make robots.txt
		// a non-UTF-8 file; every other output scrubs or rejects such bytes.
		foreach (["/caf\xE9", "/\xFF", "/x\xC3"] as $path) {
			holds(!Robots::validPath($path), 'validPath: ' . json_encode($path, JSON_INVALID_UTF8_SUBSTITUTE) . ' (not UTF-8) is not');
		}
		holds(Robots::validPath('/café'), 'validPath: /café in UTF-8 is a path');
		// The warning used to name every rule but UTF-8, and for "/bäckerei" in Latin-1 none of the named ones was true.
		expect(['Disallow: "/b' . "\u{FFFD}" . 'ckerei" left out: a path starts with / and holds no space, # or control character, and is UTF-8'], Robots::warnings(['disallow' => ["/b\xE4ckerei"]]), 'warnings: a path that is not UTF-8 is named with the UTF-8 rule');
		holds(mb_check_encoding(Robots::render(['disallow' => ['/panel', "/caf\xE9"]]), 'UTF-8'), 'render: a path that is not UTF-8 is left out, the file stays UTF-8');
		// The warning shows the path through Text::shown(): a C1 control character (CSI) as an escape, never raw.
		expect(['Disallow: "x\\u009b31m" left out: a path starts with / and holds no space, # or control character, and is UTF-8'], Robots::warnings(['disallow' => ["x\u{9B}31m"]]), 'warnings: a C1 control character in a path without its leading slash is named as an escape');
		// The lines used to be split with `\R` in byte mode, where it also matches
		// NEL = 0x85 — the second byte of "Å" and of "内" — so "Disallow: /Åland/" came
		// out as "Disallow: /\xC3" and a junk line "land/".
		$nel = Robots::render(['extra' => ['# 案内', 'Disallow: /Åland/']]);
		expect(true, mb_check_encoding($nel, 'UTF-8'), 'render: extra lines with a 0x85 byte inside a character stay UTF-8');
		expect("User-agent: *\nAllow: /\n\n# 案内\nDisallow: /Åland/\n", $nel, 'render: "Disallow: /Åland/" is one whole line, no junk line');
		expect('/Åland', Robots::parse("User-agent: *\nDisallow: /Åland\n")['groups'][0]['rules'][0]['path'] ?? null, 'parse: a path with "Å" is read whole');
		expect(['/内', '/Ņ'], array_map(fn (array $rule): string => $rule['path'], Robots::parse("User-agent: *\r\nDisallow: /内\rDisallow: /Ņ")['groups'][0]['rules']), 'parse: CRLF and CR still split, 0x85 inside a character does not');
		// parse()
		$sample = "User-agent: *\nDisallow: /panel\n\nSitemap: https://x/sitemap.xml\n";
		expect(
			['groups' => [['agents' => ['*'], 'rules' => [['type' => 'disallow', 'path' => '/panel']]]],
				'sitemaps' => ['https://x/sitemap.xml']],
			Robots::parse($sample),
			'parse reads groups and sitemaps (SPEC §3.3 example)'
		);
		$rendered = Robots::render(['disallow' => ['/panel'], 'agents' => ['GPTBot' => false, 'ChatGPT-User' => true],
			'sitemap' => 'https://x/sitemap.xml']);
		$parsed = Robots::parse($rendered);
		expect(3, count($parsed['groups']), 'round trip: three groups');
		expect(['GPTBot'], $parsed['groups'][1]['agents'], 'round trip: blocked group agent');
		expect([['type' => 'disallow', 'path' => '/']], $parsed['groups'][1]['rules'], 'round trip: blocked group rule');
		expect(['ChatGPT-User'], $parsed['groups'][2]['agents'], 'round trip: allowed group agent');
		expect(
			[['type' => 'allow', 'path' => '/'], ['type' => 'disallow', 'path' => '/panel']],
			$parsed['groups'][2]['rules'],
			'round trip: allowed group repeats the * disallows'
		);
		expect(['https://x/sitemap.xml'], $parsed['sitemaps'], 'round trip: sitemap');
		$commented = "# header comment\nuser-agent: Foo # trailing\nALLOW: /\nDisallow:/private\n\nSITEMAP: https://x/a.xml\n# end\n";
		expect(
			['groups' => [['agents' => ['Foo'], 'rules' => [
				['type' => 'allow', 'path' => '/'], ['type' => 'disallow', 'path' => '/private'],
			]]], 'sitemaps' => ['https://x/a.xml']],
			Robots::parse($commented),
			'parse ignores comments and matches field names case-insensitively'
		);
		$shared = "User-agent: A\nUser-agent: B\nDisallow: /x\n";
		expect(
			[['agents' => ['A', 'B'], 'rules' => [['type' => 'disallow', 'path' => '/x']]]],
			Robots::parse($shared)['groups'],
			'consecutive User-agent lines share one group'
		);
		expect(['groups' => [], 'sitemaps' => []], Robots::parse(''), 'empty text parses to nothing');
		// Agent names are case-insensitive (RFC 9309 §2.2.1): an override in another
		// spelling replaces the known agent instead of adding a second group.
		expect(false, Robots::agents('allowAll', ['gptbot' => false])['GPTBot'] ?? null, 'override "gptbot" replaces the preset\'s GPTBot');
		holds(!array_key_exists('gptbot', Robots::agents('allowAll', ['gptbot' => false])), 'override "gptbot" adds no second spelling');
		expect(['FooBot' => true], Robots::agents(null, ['FooBot' => false, 'foobot' => true]), 'two spellings of an unknown agent are one agent, the last wins');
		$groupsFor = function (string $text, string $agent): array {
			$rules = [];
			foreach (Robots::parse($text)['groups'] as $group) {
				foreach ($group['agents'] as $name) {
					if (strcasecmp($name, $agent) === 0) {
						$rules[] = $group['rules'];
					}
				}
			}
			return $rules;
		}
		;
		$mixed = Robots::render(['disallow' => ['/panel'], 'agents' => Robots::agents('allowAll', ['gptbot' => false])]);
		expect([[['type' => 'disallow', 'path' => '/']]], $groupsFor($mixed, 'GPTBot'), 'allowAll + gptbot false: GPTBot has one group, and it blocks /');
		expect(
			["Blocks Claude-User (user): agents in the user's service and AI search engines should stay allowed"],
			Robots::warnings(['agents' => Robots::agents(null, ['claude-user' => false])]),
			'blocking "claude-user" warns like Claude-User'
		);
		expect(
			["Blocks claude-user (user): agents in the user's service and AI search engines should stay allowed"],
			Robots::warnings(['agents' => ['claude-user' => false]]),
			'warnings: role found case-insensitively for an agent map not built by agents()'
		);
		// RFC 9309: a blank line does not end a group.
		expect(
			[['agents' => ['a'], 'rules' => [['type' => 'disallow', 'path' => '/x']]]],
			Robots::parse("User-agent: a\n\nDisallow: /x")['groups'],
			'parse: a rule after a blank line still belongs to the group'
		);
		expect(
			[['agents' => ['a', 'b'], 'rules' => [['type' => 'disallow', 'path' => '/']]]],
			Robots::parse("User-agent: a\n\nUser-agent: b\nDisallow: /\n")['groups'],
			'parse: User-agent lines separated by a blank line share one group'
		);
		expect(
			[['agents' => ['*'], 'rules' => [['type' => 'allow', 'path' => '/'], ['type' => 'disallow', 'path' => '/intern']]]],
			Robots::parse(Robots::render(['extra' => ['Disallow: /intern']]))['groups'],
			'parse: without agents the extra block is part of the * group'
		);
	}
}
