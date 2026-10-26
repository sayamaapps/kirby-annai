<?php

use Sayamaapps\Annai\Check;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The check tool: Check::page(), Check::site(), render(), exitCode() and the
 * bin/annai-check CLI (docs/SPEC.md §9.11). Kirby-booting; the CLI runs in a child
 * process against a throwaway site.
 *
 *   vendor/bin/phpunit tests/CheckTest.php
 */
#[CoversNothing]
final class CheckTest extends TestCase
{
	public function testSuite(): void
	{
		$intro = 'Gemüse vom eigenen Feld, Eier vom Hof und Brot aus der Region – jeden Samstag frisch im Hofladen.';
		// The fixture keeps its address in site.txt and has no business page: `fn () => null` says so,
		// else the check reports the index search (Check::LOCATION_PROBLEM, tested below).
		$base = ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null];
		$goodSite = [
			'marke'       => 'Testhof',
			'zusatz'      => 'Bio-Hofladen',
			'beschreibung' => 'Gemüse vom eigenen Feld, Eier vom Hof und ein Café mit Terrasse in Musterdorf.',
			'email'       => 'hof@example.test',
			'telefon'     => '030 / 34567000',
		];
		/** Writes the site fields (content/site.txt) for the next Kirby boot. */
		$writeSite = function (string $tmp, array $fields) use ($goodSite): void {
			annai_page($tmp, '', 'site', $fields === [] ? $goodSite : $fields);
		}
		;
		/** A minimal, well-formed page entry for the synthetic render/exit-code checks. */
		$pageEntry = function (array $problems = []): array {
			return [
				'url' => 'https://example.test/', 'id' => 'home', 'template' => 'home', 'indexable' => true,
				'robots' => null, 'title' => ['text' => 'Testhof', 'length' => 7],
				'description' => ['text' => 'Gemüse vom Hof.', 'length' => 16], 'canonical' => 'https://example.test/',
				'image' => null, 'alternates' => 0, 'jsonld' => ['types' => ['GroceryStore'], 'valid' => true],
				'problems' => $problems,
			];
		}
		;
		/** Runs bin/annai-check with these arguments: [exit code, stdout, stderr]. */
		$runCheck = function (array $arguments): array {
			$command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/annai-check'], $arguments);
			$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			if (!is_resource($process)) {
				return [-1, '', 'proc_open failed'];
			}
			$stdout = (string)stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$stderr = (string)stream_get_contents($pipes[2]);
			fclose($pipes[2]);
			return [proc_close($process), $stdout, $stderr];
		}
		;
		/* --------------------------------------------------------------------------
		 * Page report: a good home page, a good subpage, and each problem wording.
		 * -------------------------------------------------------------------------- */

		$tmp = annai_site();
		try {
			$writeSite($tmp, $goodSite);
			annai_page($tmp, 'laden', 'default', ['title' => 'Hofladen', 'einleitung' => $intro]);

			$kirby = annai_kirby($tmp, $base);
			$home = Check::page($kirby, $kirby->page('home'));
			expect([], $home['problems'], 'good home page: no problems');
			expect('Testhof · Bio-Hofladen', $home['title']['text'], 'home title is brand · tagline');
			expect(['GroceryStore'], $home['jsonld']['types'], 'home: schema type from annai.type');
			expect(true, $home['jsonld']['valid'], 'home: JSON-LD is valid');
			expect(true, $home['indexable'], 'home is indexable');
			expect(null, $home['robots'], 'home: no robots meta');
			expect('http://example.invalid', $home['canonical'], 'home canonical is the page URL');
			expect('home', $home['id'], 'home: id');

			$laden = Check::page($kirby, $kirby->page('laden'));
			expect([], $laden['problems'], 'good subpage: no problems');
			expect('Hofladen · Testhof', $laden['title']['text'], 'subpage title is title · brand');
			expect([], $laden['jsonld']['types'], 'subpage: no JSON-LD types');
			expect($intro, $laden['description']['text'], 'subpage description comes from einleitung');

			// Title longer than 60 characters.
			$writeSite($tmp, array_merge($goodSite, ['marke' => str_repeat('Hofladen Testhof ', 4)]));
			$kirby = annai_kirby($tmp, $base);
			expect(['title longer than 60 characters'], Check::page($kirby, $kirby->page('home'))['problems'], 'title longer than 60 characters');

			// Description missing, short and long.
			$writeSite($tmp, array_diff_key($goodSite, ['beschreibung' => true]));
			$kirby = annai_kirby($tmp, $base);
			expect(['description missing'], Check::page($kirby, $kirby->page('home'))['problems'], 'description missing');

			$writeSite($tmp, array_merge($goodSite, ['beschreibung' => 'Kurz und gut.']));
			$kirby = annai_kirby($tmp, $base);
			expect(['description shorter than 50 characters'], Check::page($kirby, $kirby->page('home'))['problems'], 'description shorter than 50 characters');

			// The limit follows `annai.descriptionLength` (155 + 5 = 160 by default).
			// With a length of 200 the check used to report the 192 characters it had asked for as
			// "longer than 160".
			$writeSite($tmp, array_merge($goodSite, ['beschreibung' => str_repeat('Gemüse vom Hof. ', 12)]));
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.descriptionLength' => 200]));
			$long = Check::page($kirby, $kirby->page('home'));
			expect(191, $long['description']['length'], 'descriptionLength 200: the description has 191 characters (fixture check)');
			expect([], $long['problems'], 'descriptionLength 200: 192 characters are no problem');
			expect(160, Check::descriptionMax(annai_kirby($tmp, $base)), 'description limit: 160 by default (155 + 5)');
			expect(205, Check::descriptionMax($kirby), 'description limit: descriptionLength + 5');
			expect('description longer than 205 characters', Check::descriptionProblem($kirby), 'description limit: the problem names the limit that applies');

			// Canonical from a hook that points elsewhere.
			$writeSite($tmp, $goodSite);
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.canonical' => fn ($page) => 'https://other.example/']));
			expect(['canonical differs from the page URL'], Check::page($kirby, $kirby->page('home'))['problems'], 'canonical differs from the page URL');

			// Share image over plain http.
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.image' => 'http://example.invalid/teilen.jpg']));
			expect(['og:image is not an absolute https URL'], Check::page($kirby, $kirby->page('home'))['problems'], 'og:image over http is a problem');

			// A JSON-LD node that cannot be encoded (NAN; invalid UTF-8 is substituted, not an error).
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.jsonld' => fn ($page, $nodes, $facts) => array_merge($nodes, [['@type' => 'Thing', 'value' => NAN]])]));
			$broken = Check::page($kirby, $kirby->page('home'));
			expect(['JSON-LD is not valid JSON'], $broken['problems'], 'NAN in JSON-LD is a problem');
			expect(false, $broken['jsonld']['valid'], 'invalid JSON-LD: valid false');
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.jsonld' => fn ($page, $nodes, $facts) => array_merge($nodes, [['@type' => 'Thing', 'name' => "bad \xB1 byte"]])]));
			expect(true, Check::page($kirby, $kirby->page('home'))['jsonld']['valid'], 'invalid UTF-8 in JSON-LD is substituted: valid');

			// The preview (every page noindex, robots.txt closed) is one site problem,
			// not one per page, and the robots warnings are those of the configuration
			// that applies once the preview ends.
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.noindex' => true, 'annai.robots.agents' => ['Claude-User' => false]]));
			expect([], Check::page($kirby, $kirby->page('home'))['problems'], 'preview: no per-page noindex problem');
			expect('noindex, nofollow, noarchive', Check::page($kirby, $kirby->page('home'))['robots'], 'preview: the page report still shows the robots meta');
			$report = Check::site($kirby);
			expect([Check::PREVIEW_PROBLEM], $report['problems'], 'preview: reported once as a site problem');
			expect('preview on: every page carries noindex', Check::PREVIEW_PROBLEM, 'preview: fixed wording');
			expect(true, $report['preview'], 'preview: flag set');
			expect(true, $report['hidden'], 'preview: explicit annai.noindex true → hidden');
			holds(str_contains(Check::render($report), "\npreview: on (everything closed)\n"), 'preview: render says everything closed when hidden');
			expect("User-agent: *\nDisallow: /\n", $report['robots']['text'], 'preview: robots.txt text as served');
			expect(1, count($report['robots']['warnings']), 'preview: one robots warning, for the configuration after the preview');
			holds(str_contains((string)($report['robots']['warnings'][0] ?? ''), 'Blocks Claude-User'), 'preview: the blocked Claude-User is still warned about');
			holds(!in_array('Disallow: / for everyone', $report['robots']['warnings'], true), 'preview: no "Disallow: / for everyone" (that is the preview itself)');
			expect([], array_merge(...array_map(fn ($p) => $p['problems'], $report['pages'])), 'preview: no page problems');
			expect(1, Check::exitCode($report), 'preview: exit code 1');
			holds(substr_count(Check::render($report), 'noindex') === 1 + count($report['pages']), 'preview: render names the noindex once per page line plus once as the site problem');

			/* ----------------------------------------------------------------------
			 * Site report.
			 * ---------------------------------------------------------------------- */

			$writeSite($tmp, $goodSite);
			$kirby = annai_kirby($tmp, $base);
			$report = Check::site($kirby);
			expect([], $report['problems'], 'good site: no site problems');
			expect(2, $report['sitemap']['count'], 'sitemap counts home and laden (error excluded)');
			expect(1, $report['sitemap']['chunks'], 'one sitemap file');
			expect(true, $report['sitemap']['lastmod'], 'every sitemap entry has lastmod');
			holds(in_array('Pages', $report['llms']['headings'], true), 'llms.txt has a Pages heading');
			holds(in_array('Contact', $report['llms']['headings'], true), 'llms.txt has a Contact heading (email set)');
			expect(false, $report['preview'], 'preview is off');
			holds($report['llms']['length'] > 0, 'llms.txt has content');
			holds(str_contains($report['robots']['text'], 'Sitemap: http://example.invalid/sitemap.xml'), 'robots.txt names the sitemap');
			expect(['home', 'laden'], array_map(fn ($p) => $p['id'], $report['pages']), 'site report lists home and laden');

			expect([], $report['notes'], 'good site with a host: no notes');

			// Without a host in Kirby's URL (the CLI without --url) every URL in the report
			// is a path ("canonical: /laden"); the report says so once, as a note that is
			// printed but not counted — the exit code stays what the problems make it.
			$report = Check::site(annai_kirby($tmp, array_merge($base, ['url' => '/'])));
			expect([Check::NO_HOST_NOTE], $report['notes'], 'no host: one note');
			holds(str_starts_with(Check::NO_HOST_NOTE, 'no host') && str_contains(Check::NO_HOST_NOTE, 'pass --url'), 'no host: the note says what to do');
			expect([], $report['problems'], 'no host: no site problem');
			expect(0, Check::exitCode($report), 'no host: exit code unchanged (0)');
			expect('/laden', $report['pages'][1]['canonical'] ?? null, 'no host: the canonical is a path (fixture check)');
			holds(str_contains(Check::render($report), "\n  ~ " . Check::NO_HOST_NOTE . "\n"), 'no host: render prints the note with a "~" prefix, not as a "!" problem');
			holds(str_contains(Check::render($report), '2 pages, 0 problems'), 'no host: the summary counts no problem for it');

			// Every phone and fax field with its raw value and its result (SPEC §3.2, the input
			// contract): printed as a note, so a person sees each field before the
			// go-live; a field that gives no number is a configuration problem as well.
			$report = Check::site(annai_kirby($tmp, $base));
			expect([['field' => 'phone', 'raw' => '030 / 34567000', 'e164' => '+493034567000', 'problem' => null]], $report['phones'] ?? null, 'phones: the phone field as stored and as E.164');
			holds(str_contains(Check::render($report), "\npreview: off\n  ~ phone: \"030 / 34567000\" → +493034567000\n"), 'phones: render prints a "~" line after the preview line');
			$writeSite($tmp, array_merge($goodSite, ['telefon' => "(0049) 30\u{A0}34567000", 'fax' => "Fax\x1b folgt"]));
			$report = Check::site(annai_kirby($tmp, $base));
			expect([
				['field' => 'phone', 'raw' => "(0049) 30\u{A0}34567000", 'e164' => '+493034567000', 'problem' => null],
				['field' => 'fax', 'raw' => "Fax\x1b folgt", 'e164' => null, 'problem' => 'characters'],
			], $report['phones'] ?? null, 'phones: the raw values; a no-break space is within the contract, a note gives no number and its reason');
			$text = Check::render($report);
			holds(str_contains($text, "  ~ fax: \"Fax\\u001b folgt\" → (none: more than the number)\n") && !str_contains($text, "\x1b"), 'phones: a field without a number is printed with its reason, the control character as an escape');
			expect(
				['configuration: field fax holds more than the number ("Fax\u001b folgt") — nothing is published as E.164; write only the number: digits, spaces, a leading +, "/", "-" and parentheses. Notes go into another field.'],
				$report['problems'],
				'phones: the fax field outside the contract is one configuration problem, the phone lines themselves are none'
			);
			expect(1, Check::exitCode($report), 'phones: the configuration problem sets the exit code');
			expect(Check::exitCode($report), Check::exitCode(['phones' => []] + $report), 'phones: the "~" lines do not change the exit code');
			holds(str_contains($text, '2 pages, 1 problems'), 'phones: the summary counts the configuration problem only');
			$writeSite($tmp, array_merge($goodSite, ['telefon' => '34567']));
			$report = Check::site(annai_kirby($tmp, $base));
			expect([['field' => 'phone', 'raw' => '34567', 'e164' => null, 'problem' => 'invalid']], $report['phones'] ?? null, 'phones: a number without its area code is "invalid"');
			holds(str_contains(Check::render($report), "  ~ phone: \"34567\" → (none: no valid number)\n"), 'phones: render prints "no valid number"');
			// A C1 control character (CSI) in the field: an escape in the line and in the problem, never raw.
			$writeSite($tmp, array_merge($goodSite, ['telefon' => "030\u{9B}34567000"]));
			$report = Check::site(annai_kirby($tmp, $base));
			holds(str_contains(Check::render($report), "  ~ phone: \"030\\u009b34567000\" → (none: more than the number)\n"), 'phones: a C1 control character is printed as an escape');
			holds(str_contains($report['problems'][0] ?? '', '("030\\u009b34567000")') && !str_contains(implode('', $report['problems']), "\u{9B}"), 'phones: and the problem (the log line) holds the escape, not the character');
			$writeSite($tmp, array_diff_key($goodSite, ['telefon' => true]));
			expect([], Check::site(annai_kirby($tmp, $base))['phones'] ?? null, 'phones: an empty phone field is no line');
			holds(!str_contains(Check::render(Check::site(annai_kirby($tmp, $base))), '  ~ phone'), 'phones: and render prints none');
			$writeSite($tmp, $goodSite);

			// Robots warning for a blocked user agent.
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.robots.agents' => ['Claude-User' => false]]));
			$report = Check::site($kirby);
			expect(1, count($report['robots']['warnings']), 'blocked Claude-User gives one robots warning');
			holds(str_contains((string)($report['robots']['warnings'][0] ?? ''), 'Claude-User'), 'the warning names Claude-User');
			expect(1, Check::exitCode($report), 'a robots warning makes the exit code 1');

			// Only selected pages; an unknown id is a site problem.
			$report = Check::site($kirby, ['laden']);
			expect(['laden'], array_map(fn ($p) => $p['id'], $report['pages']), 'only the selected page');
			$report = Check::site($kirby, ['nope']);
			expect(['no indexable page with id: nope'], $report['problems'], 'unknown page id is reported');

			// Duplicate titles: a second subpage with the same title.
			annai_page($tmp, 'cafe', 'default', ['title' => 'Hofladen', 'einleitung' => $intro]);
			$kirby = annai_kirby($tmp, $base);
			$report = Check::site($kirby);
			$duplicates = array_values(array_filter($report['problems'], fn ($p) => str_starts_with($p, 'duplicate title: Hofladen · Testhof (')));
			expect(1, count($duplicates), 'duplicate title is reported once');
			holds(isset($duplicates[0]) && str_contains($duplicates[0], 'cafe') && str_contains($duplicates[0], 'laden'), 'duplicate title names both page ids');
			expect(1, Check::exitCode($report), 'duplicate title gives exit code 1');
			unlink($tmp . '/content/cafe/default.txt');
			rmdir($tmp . '/content/cafe');

			// The old plugin still installed.
			mkdir($tmp . '/site/plugins/suchmaschinen', 0700, true);
			$kirby = annai_kirby($tmp, $base);
			$report = Check::site($kirby);
			expect([Check::OLD_PLUGIN_PROBLEM], $report['problems'], 'old suchmaschinen plugin is reported');
			rmdir($tmp . '/site/plugins/suchmaschinen');

			// A static robots.txt, sitemap.xml or llms.txt in the web root is served by
			// the web server before Kirby runs (.htaccess !-f): the route is dead.
			foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $name) {
				file_put_contents($tmp . '/public/' . $name, 'static');
				$report = Check::site(annai_kirby($tmp, $base));
				expect([sprintf(Check::STATIC_FILE_PROBLEM, $name)], $report['problems'], "static public/$name is reported");
				holds(str_contains($report['problems'][0] ?? '', $name) && str_contains($report['problems'][0] ?? '', 'remove'), "static public/$name: the problem names the file and says what to do");
				expect(1, Check::exitCode($report), "static public/$name gives exit code 1");
				unlink($tmp . '/public/' . $name);
			}
			expect([], Check::site(annai_kirby($tmp, $base))['problems'], 'without static files: no site problem');

			// Without `marke` and without a site title the name is ''. llms.txt used to begin
			// with an empty "# " (llmstxt.org requires an H1 with the name), the home page had no
			// JSON-LD and no <title>, and the check said only "title missing" per page, not why.
			// Now the check names the cause once, and the H1 falls back to the host.
			$writeSite($tmp, ['beschreibung' => $goodSite['beschreibung']]);
			$kirby = annai_kirby($tmp, $base);
			$report = Check::site($kirby);
			expect([sprintf(Check::NAME_PROBLEM, 'marke')], $report['problems'], 'no site name: one site problem naming the brand field');
			expect('name missing: set the brand field (marke) or the site title', sprintf(Check::NAME_PROBLEM, 'marke'), 'no site name: fixed wording');
			expect(['title missing'], $report['pages'][0]['problems'] ?? null, 'no site name: the home page still reports its missing title');
			expect(1, Check::exitCode($report), 'no site name: exit code 1');
			[, $md] = annai_request($kirby, 'llms.txt');
			holds(str_starts_with($md, "# example.invalid\n"), 'no site name: the llms.txt H1 falls back to the host');
			$kirby = annai_kirby($tmp, array_merge($base, ['annai.fields' => ['brand' => 'firma']]));
			expect([sprintf(Check::NAME_PROBLEM, 'firma')], Check::site($kirby)['problems'], 'no site name: the problem names the configured brand field');
			[, $md] = annai_request(annai_kirby($tmp, array_merge($base, ['url' => '/'])), 'llms.txt');
			holds(str_starts_with($md, "# \n"), 'no site name and no host (the CLI without --url): the H1 stays empty, the check names both');
			$writeSite($tmp, array_diff_key($goodSite, ['marke' => true]) + ['title' => 'Hof Titel']);
			$report = Check::site(annai_kirby($tmp, $base));
			expect([], $report['problems'], 'site title without marke: no name problem');
			expect('Hof Titel · Bio-Hofladen', $report['pages'][0]['title']['text'] ?? null, 'site title without marke: the name is the site title (fixture check)');
			$writeSite($tmp, $goodSite);

			// A broken option is a site problem of the check, not a crash. The
			// messages Options::warn() logs during the run are collected.
			$report = Check::site(annai_kirby($tmp, array_merge($base, ['annai.timezone' => 'Mars/Olympus', 'annai.twitterCard' => 'summary'])));
			expect([sprintf(Check::CONFIGURATION_PROBLEM, "unknown annai.timezone 'Mars/Olympus' — using Europe/Berlin.")], $report['problems'], 'a broken option is one site problem naming the option');
			expect(1, Check::exitCode($report), 'a broken option gives exit code 1');
			holds(str_contains(Check::render($report), '  ! configuration: unknown annai.timezone'), 'a broken option is rendered as a site problem');
			expect([], Check::site(annai_kirby($tmp, $base))['problems'], 'the configuration problem of the previous run is not repeated');

			// The texts for an invalid Disallow entry used to name every rule but UTF-8,
			// so for a Latin-1 path none of the named reasons was true.
			$report = Check::site(annai_kirby($tmp, array_merge($base, ['annai.disallow' => ["/b\xE4ckerei"]])));
			expect([sprintf(Check::CONFIGURATION_PROBLEM, 'annai.disallow entry "/b' . "\u{FFFD}" . 'ckerei" is not a path (starts with /, no space, # or control character, and is UTF-8) — left out of robots.txt.')], $report['problems'], 'a Latin-1 disallow entry: the configuration problem names the UTF-8 rule');
			expect(['Disallow: "/b' . "\u{FFFD}" . 'ckerei" left out: a path starts with / and holds no space, # or control character, and is UTF-8'], $report['robots']['warnings'], 'a Latin-1 disallow entry: the robots warning names the UTF-8 rule');

			// No annai.location and no business page in the top two levels: every
			// request searches the whole index (Business::searchesIndex()).
			$unset = $base;
			unset($unset['annai.location']);
			$report = Check::site(annai_kirby($tmp, $unset));
			expect([Check::LOCATION_PROBLEM], $report['problems'], 'no business page and no annai.location: the index search is reported');
			expect(1, Check::exitCode($report), 'the index search gives exit code 1');
			holds(str_contains(Check::LOCATION_PROBLEM, 'set annai.location'), 'the problem says what to do');
			annai_page($tmp, '9_hof/1_tief/1_kontakt', 'betrieb', ['title' => 'Kontakt']);
			expect([Check::LOCATION_PROBLEM], Check::site(annai_kirby($tmp, $unset))['problems'], 'a business page on the third level is found by the index search, still reported');
			annai_page($tmp, '9_hof/1_kontakt', 'betrieb', ['title' => 'Anfahrt']);
			expect([], Check::site(annai_kirby($tmp, $unset))['problems'], 'a business page on the second level: no index search, no problem');
			annai_remove_tree($tmp . '/content/9_hof');

			// With Noren and without `noren.locations`, Noren walks the whole
			// index for its location pages on every page view that asks for the business
			// page — twenty times the head time with 2,000 posts — and the check used to say
			// nothing, because searchesIndex() took Noren's answer as found.
			// The Noren case has its own text — annai.location does not stop
			// Noren's walk, so the general advice ("set annai.location") would mislead.
			holds(str_contains(Check::NOREN_LOCATIONS_PROBLEM, 'set noren.locations'), 'the Noren problem says what to do');
			holds(!str_contains(Check::LOCATION_PROBLEM, 'noren'), 'the location problem leaves Noren to its own text');
			if (annai_sibling('noren') === null) {
				skip('Noren without noren.locations is reported', 'kirby-noren not found next to this package');
			} else {
				$norenTmp = annai_site(['noren']);
				try {
					$writeSite($norenTmp, $goodSite);
					annai_page($norenTmp, '1_kontakt', 'betrieb', ['title' => 'Kontakt', 'strasse' => 'Musterweg 1', 'plz' => '12345', 'ort' => 'Musterstadt',
						'bereiche' => [['schluessel' => 'laden', 'name' => 'Hofladen']],
						'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
					$noren = ['annai.active' => true, 'annai.type' => 'GroceryStore', 'noren.active' => true, 'noren.language' => 'de'];
					expect([Check::NOREN_LOCATIONS_PROBLEM], Check::site(annai_kirby($norenTmp, $noren))['problems'], 'Noren without noren.locations: the index search is reported, although the business page is on the top level');
					expect([], Check::site(annai_kirby($norenTmp, $noren + ['noren.locations' => ['kontakt']]))['problems'], 'Noren with noren.locations: no index search, no problem');
					// annai.location spares Annai's lookup, not Noren's walk for the
					// hours (home page, llms.txt, an annai.jsonld hook) — still reported.
					expect([Check::NOREN_LOCATIONS_PROBLEM], Check::site(annai_kirby($norenTmp, $noren + ['annai.location' => 'kontakt']))['problems'], 'Noren with annai.location but without noren.locations: the index search is still reported');
					expect(true, Sayamaapps\Annai\Business::searchesIndex(annai_kirby($norenTmp, $noren + ['annai.location' => 'kontakt'])), 'searchesIndex(): true for Noren without noren.locations, whatever annai.location says');
					expect([], Check::site(annai_kirby($norenTmp, $noren + ['annai.location' => 'kontakt', 'noren.locations' => ['kontakt']]))['problems'], 'Noren with annai.location and noren.locations: no problem');
				} finally {
					annai_remove_tree($norenTmp);
				}

				// The phone of a further Noren location goes into the JSON-LD
				// departments and llms.txt as well, so the check shows it next to the
				// business's. Two locations as in BusinessTest; location B has two areas — one
				// line for its page, with the page id. Location C holds a line break, so the
				// raw field (as Business::place() reads it) differs from the one-line display
				// value: `raw` must be the stored value.
				$twoTmp = annai_site(['noren']);
				try {
					annai_page($twoTmp, '', 'site', ['title' => 'Praxis', 'marke' => 'Praxis']);
					annai_page($twoTmp, '1_a', 'betrieb', ['title' => 'Standort A', 'strasse' => 'Musterweg 1', 'ort' => 'Musterstadt', 'telefon' => '030 34567001',
						'bereiche' => [['schluessel' => 'laden', 'name' => 'Laden A']],
						'zeiten' => [['bereich' => 'laden', 'tag' => 'Montag', 'von1' => '08:00', 'bis1' => '12:00']]]);
					annai_page($twoTmp, '2_b', 'betrieb', ['title' => 'Standort B', 'strasse' => 'Bahnhofstraße 2', 'ort' => 'Musterdorf', 'telefon' => '030 34567030',
						'bereiche' => [['schluessel' => 'praxis', 'name' => 'Praxis B'], ['schluessel' => 'labor', 'name' => 'Labor B']],
						'zeiten' => [['bereich' => 'praxis', 'tag' => 'Dienstag', 'von1' => '09:00', 'bis1' => '17:00'],
							['bereich' => 'labor', 'tag' => 'Mittwoch', 'von1' => '07:00', 'bis1' => '10:00']]]);
					annai_page($twoTmp, '3_c', 'betrieb', ['title' => 'Standort C', 'strasse' => 'Seeweg 3', 'ort' => 'Musterdorf', 'telefon' => "folgt\nab Mai",
						'zeiten' => [['tag' => 'Freitag', 'von1' => '09:00', 'bis1' => '12:00']]]);
					$report = Check::site(annai_kirby($twoTmp, ['annai.active' => true, 'noren.active' => true, 'noren.language' => 'de', 'noren.locations' => ['a', 'b', 'c']]));
					expect([
						['field' => 'phone', 'raw' => '030 34567001', 'e164' => '+493034567001', 'problem' => null],
						['field' => 'phone', 'page' => 'b', 'raw' => '030 34567030', 'e164' => '+493034567030', 'problem' => null],
						['field' => 'phone', 'page' => 'c', 'raw' => "folgt\nab Mai", 'e164' => null, 'problem' => 'characters'],
					], $report['phones'] ?? null, 'phones: every further location page with its phone, once per page');
					$text = Check::render($report);
					holds(
						str_contains($text, "\n  ~ phone: \"030 34567001\" → +493034567001\n  ~ phone (b): \"030 34567030\" → +493034567030\n  ~ phone (c): \"folgt\\nab Mai\" → (none: more than the number)\n"),
						'phones: render prints a further location as "phone (<page id>)"'
					);
					expect(
						['configuration: field telefon holds more than the number ("folgt\nab Mai") — nothing is published as E.164; write only the number: digits, spaces, a leading +, "/", "-" and parentheses. Notes go into another field.'],
						$report['problems'],
						'phones: a further location\'s phone outside the contract is a configuration problem'
					);
					expect(Check::exitCode($report), Check::exitCode(['phones' => []] + $report), 'phones: a further location\'s line changes no exit code');
				} finally {
					annai_remove_tree($twoTmp);
				}
			}

			// On a multi-language site a page without content in the current
			// language points its canonical to the language version with the content.
			// The check says so as a note, not as "canonical differs from the
			// page URL": the rule is working, nothing is wrong.
			$multiTmp = annai_site();
			try {
				@unlink($multiTmp . '/content/home/home.txt');
				mkdir($multiTmp . '/site/languages', 0700, true);
				file_put_contents($multiTmp . '/site/languages/de.php', "<?php\n\nreturn ['code' => 'de', 'default' => true, 'locale' => 'de_DE.utf8', 'name' => 'Deutsch', 'url' => 'http://example.invalid'];\n");
				file_put_contents($multiTmp . '/site/languages/en.php', "<?php\n\nreturn ['code' => 'en', 'default' => false, 'locale' => 'en_US.utf8', 'name' => 'English', 'url' => 'http://example.invalid/en'];\n");
				annai_page($multiTmp, '', 'site', $goodSite, 'de');
				annai_page($multiTmp, 'home', 'home', ['title' => 'Start'], 'de');
				annai_page($multiTmp, 'home', 'home', ['title' => 'Start'], 'en');
				annai_page($multiTmp, '2_english', 'default', ['title' => 'English only', 'einleitung' => $intro], 'en');
				$kirby = annai_kirby($multiTmp, $base + ['languages' => true]);
				$english = Check::page($kirby, $kirby->page('english'));
				expect('http://example.invalid/en/english', $english['canonical'], 'multilang check: the English-only page checked in German has the English canonical');
				expect([], $english['problems'], 'multilang check: the content-language canonical is no problem');
				expect(['no content in de: canonical points to http://example.invalid/en/english'], $english['notes'] ?? null, 'multilang check: it is a note naming the language and the canonical');
				$home = Check::page($kirby, $kirby->page('home'));
				expect([], $home['notes'] ?? null, 'multilang check: a page with content in the current language has no note');
				expect('http://example.invalid', $home['canonical'], 'multilang check: its canonical is its own URL');
				$report = Check::site($kirby);
				$text = Check::render($report);
				holds(str_contains($text, "  ~ no content in de: canonical points to http://example.invalid/en/english\n"), 'multilang check: the page note is rendered with "  ~ "');
				holds(str_contains($text, "2 pages, 0 problems\n"), 'multilang check: the note is not counted');
				expect(0, Check::exitCode($report), 'multilang check: exit 0');
			} finally {
				annai_remove_tree($multiTmp);
			}

			// Behind a preview the sitemap count is what applies after the
			// go-live. With hreflang on a multilang site Pages::alternates() used to give [] for
			// every page while the preview was on (robots() is never null then), so
			// Pages::entries() counted one entry per page instead of one per language:
			// "sitemap.xml: 2 URLs" behind Sudare, 4 after the go-live.
			$multilang = annai_site();
			try {
				mkdir($multilang . '/site/languages', 0700, true);
				file_put_contents($multilang . '/site/languages/de.php', "<?php\n\nreturn ['code' => 'de', 'default' => true, 'locale' => 'de_DE.utf8', 'name' => 'Deutsch', 'url' => 'http://example.invalid'];\n");
				file_put_contents($multilang . '/site/languages/en.php', "<?php\n\nreturn ['code' => 'en', 'default' => false, 'locale' => 'en_US.utf8', 'name' => 'English', 'url' => 'http://example.invalid/en'];\n");
				unlink($multilang . '/content/home/home.txt');
				annai_page($multilang, '', 'site', $goodSite, 'de');
				annai_page($multilang, 'home', 'home', ['title' => 'Start'], 'de');
				annai_page($multilang, 'home', 'home', ['title' => 'Start'], 'en');
				annai_page($multilang, 'laden', 'default', ['title' => 'Hofladen', 'einleitung' => $intro], 'de');
				annai_page($multilang, 'laden', 'default', ['title' => 'Farm shop', 'einleitung' => $intro], 'en');
				$options = array_merge($base, ['languages' => true, 'annai.hreflang' => true, 'annai.sitemap.chunk' => 3]);
				$public = Check::site(annai_kirby($multilang, $options));
				expect([4, 2], [$public['sitemap']['count'], $public['sitemap']['chunks']], 'hreflang: the public sitemap counts one URL per language (home 2, laden 2) in two files of three');
				$preview = Check::site(annai_kirby($multilang, $options + ['annai.noindex' => true]));
				expect(true, $preview['preview'], 'hreflang + preview: the preview is on (fixture check)');
				expect([4, 2], [$preview['sitemap']['count'], $preview['sitemap']['chunks']], 'hreflang + preview: the check counts the sitemap per language, as served after the go-live');
				expect(0, $preview['pages'][1]['alternates'] ?? null, 'hreflang + preview: the page report still counts the head alternates of the preview (none: every page is noindex)');
			} finally {
				annai_remove_tree($multilang);
			}

			/* ----------------------------------------------------------------------
			 * render() and exitCode() on synthetic reports.
			 * ---------------------------------------------------------------------- */

			$clean = [
				'robots'   => ['text' => "User-agent: *\nAllow: /\n", 'warnings' => []],
				'sitemap'  => ['count' => 1, 'chunks' => 1, 'lastmod' => true],
				'llms'     => ['length' => 40, 'headings' => ['Pages']],
				'preview'  => false,
				'notes'    => [],
				'pages'    => [$pageEntry()],
				'problems' => [],
			];
			expect(0, Check::exitCode($clean), 'clean report: exit code 0');
			$text = Check::render($clean);
			holds(str_contains($text, '1 pages, 0 problems'), 'clean report: summary line');
			holds(!str_contains($text, '  ! ') && !str_contains($text, '  ~ '), 'clean report: no problem and no note lines');
			$noted = $clean;
			$noted['notes'] = [Check::NO_HOST_NOTE];
			expect(0, Check::exitCode($noted), 'a note alone keeps exit code 0');
			holds(str_contains(Check::render($noted), '  ~ no host'), 'a note is rendered');

			$dirty = $clean;
			$dirty['pages'] = [$pageEntry(['title missing'])];
			$dirty['robots']['warnings'] = ['Blocks Claude-User (user): agents in the user\'s service and AI search engines should stay allowed'];
			$dirty['problems'] = ['duplicate title: Hofladen · Testhof (cafe, laden)'];
			expect(1, Check::exitCode($dirty), 'dirty report: exit code 1');
			$text = Check::render($dirty);
			holds(str_contains($text, "  ! title missing\n"), 'render: page problem prefixed');
			holds(str_contains($text, '  ! Blocks Claude-User'), 'render: robots warning prefixed');
			holds(str_contains($text, '  ! duplicate title'), 'render: site problem prefixed');
			holds(str_contains($text, '1 pages, 3 problems'), 'render: summary counts all problems');

			// Panel text reaches the terminal: escape sequences and line feeds in a
			// title, description, id, heading or problem must not survive render().
			$hostile = $clean;
			$hostile['llms']['headings'] = ["Seiten\e]0;owned\x07"];
			$hostile['pages'] = [array_merge($pageEntry(["duplicate title: A\e[2J\n0 pages, 0 problems"]), [
				'id'          => "home\e[31m",
				'title'       => ['text' => "Testhof\e[2J\e[H", 'length' => 13],
				'description' => ['text' => "Gemüse\u{9B}31m vom Hof.\n0 pages, 0 problems", 'length' => 40],
			])];
			$text = Check::render($hostile);
			holds(!preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text), 'render: no C0 control characters from Panel text');
			holds(!str_contains($text, "\u{9B}"), 'render: no C1 control characters (CSI) from Panel text');
			holds(str_contains($text, 'title: "Testhof[2J[H"'), 'render: title kept without its escape bytes');
			holds(str_contains($text, 'Hof. 0 pages, 0 problems"'), 'render: line feed in a value becomes a space');
			holds(preg_match('/^0 pages, 0 problems$/m', $text) === 0, 'render: a value cannot fake the summary line');
			$raw = $clean;
			$raw['pages'] = [array_merge($pageEntry(), ['title' => ['text' => "Hof\x9b2J", 'length' => 6]])];
			holds(!str_contains(Check::render($raw), "\x9b"), 'render: a raw C1 byte (invalid UTF-8) does not survive');

			$siteOnly = $clean;
			$siteOnly['problems'] = [Check::OLD_PLUGIN_PROBLEM];
			expect(1, Check::exitCode($siteOnly), 'site problem alone: exit code 1');
		} finally {
			annai_remove_tree($tmp);
		}
		/* --------------------------------------------------------------------------
		 * bin/annai-check, in a child process.
		 * -------------------------------------------------------------------------- */

		$bin = dirname(__DIR__) . '/bin/annai-check';
		holds(is_file($bin) && is_executable($bin), 'bin/annai-check is an executable file');
		$tmp = annai_site();
		try {
			// No vendor/ in the throwaway site: a clear usage error, exit 2.
			[$code, , $err] = $runCheck(['--root=' . $tmp]);
			expect(2, $code, 'missing vendor/autoload.php: exit 2');
			holds(str_contains($err, 'vendor/autoload.php'), 'missing vendor: message names the autoloader');

			// Usage errors need no Kirby at all.
			[$code, , $err] = $runCheck(['--bogus']);
			expect(2, $code, 'unknown argument: exit 2');
			holds(str_contains($err, 'usage:'), 'unknown argument: usage line');
			[$code] = $runCheck(['--url=example.test']);
			expect(2, $code, '--url without a scheme: exit 2');
			[$code] = $runCheck(['--root=' . $tmp . '/does-not-exist']);
			expect(2, $code, 'missing root directory: exit 2');
			[$code] = $runCheck(['--page=']);
			expect(2, $code, 'empty --page: exit 2');

			// A site with the package's vendor directory and an Annai config.
			symlink(dirname(__DIR__) . '/vendor', $tmp . '/vendor');
			annai_page($tmp, '', 'site', $goodSite);
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => false];\n");
			[$code, , $err] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			expect(2, $code, 'Annai off: exit 2');
			holds(str_contains($err, 'Annai is off (annai.active)'), 'Annai off: message');

			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null];\n");
			[$code, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			expect(0, $code, 'good site through the CLI: exit 0');
			holds(str_contains($out, 'pages'), 'CLI text report names the pages');
			holds(str_contains($out, '1 pages, 0 problems'), 'CLI summary line');

			[$code, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json']);
			expect(0, $code, 'CLI --json: exit 0');
			$decoded = json_decode($out, true);
			holds(is_array($decoded) && array_key_exists('pages', $decoded), '--json prints a report with pages');
			expect(['GroceryStore'], $decoded['pages'][0]['jsonld']['types'] ?? null, '--json: schema type of the home page');

			// JSON_UNESCAPED_UNICODE prints U+0080–U+009F raw; the CLI escapes them.
			// Text::line() keeps C1 out of every Panel text, so a --page id is the vector.
			[, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json', "--page=nope\u{9B}2J"]);
			holds(str_contains($out, 'nope\u009b2J') && !str_contains($out, "\u{9B}"), '--json: C1 from an argument printed as \u009b');
			expect("no indexable page with id: nope\u{9B}2J", json_decode($out, true)['problems'][0] ?? null, '--json: escaped C1 decodes back to the argument');
			// A C1 control in a Panel field is gone before the report.
			annai_page($tmp, '', 'site', array_merge($goodSite, ['marke' => "Test\u{9B}2Jhof"]));
			[, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json']);
			expect('Test2Jhof · Bio-Hofladen', json_decode($out, true)['pages'][0]['title']['text'] ?? null, '--json: a C1 control in the brand field is removed before the report');
			annai_page($tmp, '', 'site', $goodSite);

			// A llms.txt heading "## 案内" or a robots.extra line with "Å" used to be
			// cut inside the character by the `\R` line split (0x85 is NEL in byte mode):
			// the report held broken UTF-8 and --json ended with exit 3.
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null,\n"
				. "    'annai.robots.extra' => ['# Filialen: Åland'],\n"
				. "    'annai.llms.extra' => function (array \$model): array { \$model['sections'][] = ['title' => '案内', 'lines' => ['- Wegbeschreibung']]; return \$model; }];\n");
			[$code, $out, $err] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json']);
			expect(0, $code, '--json with a 案内 section and an Åland comment: exit 0, not 3 (' . trim($err) . ')');
			$decoded = json_decode($out, true);
			holds(in_array('案内', $decoded['llms']['headings'] ?? [], true), '--json: the heading 案内 is reported whole');
			holds(str_contains($decoded['robots']['text'] ?? '', "# Filialen: Åland\n"), '--json: the robots.extra line with Å is reported whole');
			// A byte that is not UTF-8 in an argument (a Latin-1 page id) is no reason to
			// give up on the report: it is written as U+FFFD, like in the JSON-LD.
			[$code, $out, $err] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json', "--page=caf\xE9"]);
			expect(1, $code, '--json with a Latin-1 --page id: exit 1 (unknown page), not 3 (' . trim($err) . ')');
			expect("no indexable page with id: caf\u{FFFD}", json_decode($out, true)['problems'][0] ?? null, '--json: the Latin-1 byte is written as U+FFFD');
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null];\n");

			// Without --url Kirby has no host on the CLI: the report shows paths and says so
			// once, printed as a note, not counted.
			[$code, $out] = $runCheck(['--root=' . $tmp]);
			expect(0, $code, 'without --url: exit 0, the note is no problem');
			holds(str_contains($out, '  ~ ' . Check::NO_HOST_NOTE . "\n"), 'without --url: the note is printed');
			holds(str_contains($out, "\n  canonical: /\n"), 'without --url: the canonical is a path (fixture check)');
			holds(str_contains($out, '1 pages, 0 problems'), 'without --url: the summary counts no problem');
			[, $out] = $runCheck(['--root=' . $tmp, '--json']);
			expect([Check::NO_HOST_NOTE], json_decode($out, true)['notes'] ?? null, 'without --url --json: notes in the report');
			[, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			// The phone line is a "~" line too, so this looks for the note itself.
			holds(!str_contains($out, '  ~ no host'), 'with --url: no note');
			holds(str_contains($out, "\n  ~ phone: \"030 / 34567000\" → +493034567000\n"), 'CLI: the phone line is printed');
			[, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json']);
			expect([['field' => 'phone', 'raw' => '030 / 34567000', 'e164' => '+493034567000', 'problem' => null]], json_decode($out, true)['phones'] ?? null, 'CLI --json: phone and fax under "phones"');

			// A site whose Composer did not install giggsey/libphonenumber-for-php-lite (Annai copied by
			// hand, README "Installation"): the package's autoloader without the libphonenumber classes.
			// The check runs, publishes no number, and says why in the line and as a problem.
			unlink($tmp . '/vendor');
			mkdir($tmp . '/vendor');
			file_put_contents($tmp . '/vendor/autoload.php', "<?php\n\$loader = require " . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n"
				. "\$loader->unregister();\n"
				. "spl_autoload_register(static function (string \$class) use (\$loader): void {\n"
				. "    if (!str_starts_with(\$class, 'libphonenumber\\\\')) {\n        \$loader->loadClass(\$class);\n    }\n});\n"
				. "return \$loader;\n");
			[$code, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			holds(str_contains($out, "\n  ~ phone: \"030 / 34567000\" → (none: libphonenumber is not installed)\n"), 'CLI without libphonenumber: the phone line says why there is no number');
			holds(
				str_contains($out, '  ! configuration: phone numbers need giggsey/libphonenumber-for-php-lite, which is not installed — nothing is published as E.164; install Annai with Composer (README, Installation).'),
				'CLI without libphonenumber: a configuration problem'
			);
			expect(1, $code, 'CLI without libphonenumber: exit 1');
			[, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--json']);
			expect([['field' => 'phone', 'raw' => '030 / 34567000', 'e164' => null, 'problem' => 'library']], json_decode($out, true)['phones'] ?? null, 'CLI --json without libphonenumber: problem "library"');
			// The same site rendered with Kirby's `debug` on (README "Installation", SPEC §3.2 "The
			// library"): Options::warn() throws the library message on the page instead of logging it.
			$script = $tmp . '/render-debug.php';
			file_put_contents($script, "<?php\nrequire " . var_export($tmp . '/vendor/autoload.php', true) . ";\n"
				. "\$kirby = new Kirby\\Cms\\App(['roots' => ['base' => " . var_export($tmp, true) . ", 'index' => " . var_export($tmp . '/public', true)
				. ", 'content' => " . var_export($tmp . '/content', true) . ", 'site' => " . var_export($tmp . '/site', true)
				. ", 'storage' => " . var_export($tmp . '/storage', true) . "],\n"
				. "    'options' => ['url' => 'http://example.invalid', 'debug' => true, 'cache' => false]]);\n"
				. "try { \$code = \$kirby->render('/')?->code(); echo 'rendered ', \$code; } catch (Throwable \$e) { echo get_class(\$e), ': ', \$e->getMessage(); }\n");
			$process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$rendered = is_resource($process) ? (string)stream_get_contents($pipes[1]) : 'proc_open failed';
			if (is_resource($process)) {
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
			}
			expect(
				'InvalidArgumentException: Annai: phone numbers need giggsey/libphonenumber-for-php-lite, which is not installed — nothing is published as E.164; install Annai with Composer (README, Installation).',
				$rendered,
				'without libphonenumber, debug on: the page throws the library message'
			);
			unlink($script);
			unlink($tmp . '/vendor/autoload.php');
			rmdir($tmp . '/vendor');
			symlink(dirname(__DIR__) . '/vendor', $tmp . '/vendor');

			[$code, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--page=home']);
			expect(0, $code, '--page=home: exit 0');
			holds(str_contains($out, '1 pages'), '--page=home: one page');

			[$code] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', '--page=nope']);
			expect(1, $code, '--page for an unknown id: exit 1');

			[$code, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid', "--page=nope\e[2J"]);
			holds(str_contains($out, 'no indexable page with id: nope[2J') && !str_contains($out, "\e"), '--page with an escape sequence: printed without it');

			// A site behind Sudare: on the CLI no request ran the guard, so Annai asks
			// the stateless Sudare\Sudare::protects($base) (a stub plugin here).
			mkdir($tmp . '/site/plugins/sudare');
			file_put_contents($tmp . '/site/plugins/sudare/index.php', "<?php\nnamespace Sudare;\nfinal class Sudare\n{\n"
				. "    public static function active(): bool { return false; }\n"
				. "    public static function protects(string \$base, ?array \$env = null): bool { return is_file(\$base . '/site/config/sudare.php'); }\n}\n");
			[, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			holds(str_contains($out, "preview: off\n"), 'CLI behind Sudare without configuration: preview off');
			// A blocked user agent must stay visible while the preview is on: it is what applies after the go-live.
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null, 'annai.robots.agents' => ['Claude-User' => false]];\n");
			file_put_contents($tmp . '/site/config/sudare.php', "<?php\nreturn [];\n");
			[$code, $out] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			holds(str_contains($out, "preview: on (noindex, files as live)\n"), 'CLI behind Sudare: protects() true → preview on, files as live (not hidden)');
			holds(str_contains($out, 'sitemap.xml: 1 URLs'), 'CLI behind Sudare: sitemap count is what is served after the preview');
			holds(str_contains($out, 'sections: Contact, Pages'), 'CLI behind Sudare: llms.txt sections are what is served after the preview');
			holds(str_contains($out, '  ! Blocks Claude-User (user)'), 'CLI behind Sudare: the robots warning of the configuration after the preview is shown');
			holds(!str_contains($out, 'Disallow: / for everyone'), 'CLI behind Sudare: the preview is not reported as "Disallow: / for everyone"');
			expect(1, substr_count($out, "  ! preview on: every page carries noindex\n"), 'CLI behind Sudare: the preview is one site problem');
			holds(!str_contains($out, 'indexable page carries noindex'), 'CLI behind Sudare: no per-page noindex problem');
			holds(str_contains($out, "1 pages, 2 problems\n"), 'CLI behind Sudare: summary counts the preview and the robots warning');
			expect(1, $code, 'CLI behind Sudare: exit 1 (preview on, Claude-User blocked)');
			unlink($tmp . '/site/config/sudare.php');
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null];\n");
			unlink($tmp . '/site/plugins/sudare/index.php');
			rmdir($tmp . '/site/plugins/sudare');

			// With Kirby's `debug` on, Options::warn() throws, and the check used to die
			// at the first broken option ("check failed", exit 3, one message). The check
			// boots with debug off, so every broken option is logged and reported as a site
			// problem, the report is complete and the exit code is 1.
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['debug' => true, 'annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null, 'annai.disallow' => ['panel'], 'annai.timezone' => 'Mars/Olympus', 'annai.robots.agents' => ['Claude-User' => false]];\n");
			[$code, $out, $err] = $runCheck(['--root=' . $tmp, '--url=http://example.invalid']);
			expect(1, $code, 'debug on + broken options: exit 1, not 3');
			holds(!str_contains($err, 'check failed'), 'debug on + broken options: no "check failed"');
			holds(str_contains($out, '  ! configuration: annai.disallow entry "panel" is not a path'), 'debug on + broken options: the disallow entry is a site problem');
			holds(str_contains($out, "  ! configuration: unknown annai.timezone 'Mars/Olympus'"), 'debug on + broken options: the timezone is a site problem too (the report did not stop at the first)');
			holds(str_contains($out, '  ! Disallow: "panel" left out') && str_contains($out, '  ! Blocks Claude-User (user)'), 'debug on + broken options: the robots warnings are still there');
			holds(preg_match('/^1 pages, (\d+) problems$/m', $out, $summary) === 1 && (int)$summary[1] >= 4, 'debug on + broken options: the report is complete, the summary counts the configuration problems (' . ($summary[1] ?? '?') . ')');
			file_put_contents($tmp . '/site/config/config.php', "<?php\nreturn ['annai.active' => true, 'annai.type' => 'GroceryStore', 'annai.location' => fn () => null];\n");

			[$code, , $err] = $runCheck(["--bogus\e]0;x\x07"]);
			expect(2, $code, 'unknown argument with escape sequence: exit 2');
			holds(str_contains($err, 'unknown argument: --bogus]0;x') && !str_contains($err, "\e"), 'usage error: argument printed without control characters');
		} finally {
			annai_remove_tree($tmp);
		}
	}
}
