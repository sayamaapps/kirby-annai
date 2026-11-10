<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Annai\Business;

/**
 * The head of the error page: a 404 never names /error as its canonical.
 *   vendor/bin/phpunit tests/ErrorPageTest.php
 */
#[CoversClass(Business::class)]
final class ErrorPageTest extends TestCase
{
	public function testSuite(): void
	{
		// --- an unknown URL renders the error page: no canonical, no og:url -------

		foreach ([[], ['annai.noindexExcluded' => false, 'annai.exclude.templates' => []]] as $options) {
			$this->withSite($options, function ($kirby): void {
				[$code, $body] = annai_request($kirby, 'gibt-es-nicht');
				expect(404, $code, 'unknown URL: status 404');
				holds(str_contains($body, '<title>'), 'unknown URL: the head of the error page is rendered');
				holds(!str_contains($body, 'rel="canonical"'), 'unknown URL: no canonical link');
				holds(!str_contains($body, 'og:url'), 'unknown URL: no og:url');
				holds(!str_contains($body, '/error'), 'unknown URL: nothing points to /error');
			});
		}

		// --- the canonical hook does not bring /error back -------------------------

		$this->withSite(['annai.canonical' => fn ($page): string => 'https://example.com/' . $page->id()], function ($kirby): void {
			$head = Business::head($kirby, $kirby->site()->errorPage());
			expect('', $head['canonical'], 'error page: the canonical hook is not asked');
			$laden = Business::head($kirby, $kirby->page('laden'));
			expect('https://example.com/laden', $laden['canonical'], 'other pages: the canonical hook still applies');
		});

		// --- normal pages keep their canonical and og:url -------------------------

		$this->withSite([], function ($kirby): void {
			[$code, $body] = annai_request($kirby, 'laden');
			expect(200, $code, 'normal page: status 200');
			holds(str_contains($body, '<link rel="canonical" href="http://example.invalid/laden">'), 'normal page: canonical');
			holds(str_contains($body, '<meta property="og:url" content="http://example.invalid/laden">'), 'normal page: og:url');
		});

		// --- no hreflang alternates for the error page either --------------------

		$this->withSite(['languages' => true, 'annai.hreflang' => true, 'annai.noindexExcluded' => false, 'annai.exclude.templates' => []], function ($kirby): void {
			$error = $kirby->site()->errorPage();
			expect([], Sayamaapps\Annai\Pages::alternates($kirby, $error), 'multilang: no alternates for the error page');
			expect('', Business::head($kirby, $error)['canonical'], 'multilang: no canonical for the error page');
			expect(['de', 'en', 'x-default'], array_keys(Sayamaapps\Annai\Pages::alternates($kirby, $kirby->page('laden'))), 'multilang: another page keeps its alternates');
		}, true);

		// --- the check does not call the missing canonical a problem -------------

		$this->withSite(['annai.noindexExcluded' => false, 'annai.exclude.templates' => []], function ($kirby): void {
			$report = Sayamaapps\Annai\Check::page($kirby, $kirby->site()->errorPage());
			expect('', $report['canonical'], 'check: the error page has no canonical');
			holds(!in_array('canonical differs from the page URL', $report['problems'], true), 'check: no canonical problem for the error page');
		});
	}

	/** Boots a site whose error template prints the head snippet. */
	private function withSite(array $options, callable $fn, bool $multilang = false): void
	{
		$tmp = annai_site();
		try {
			file_put_contents($tmp . '/site/templates/error.php', '<html><head><?php snippet("annai/head") ?></head><body>Fixture 404</body></html>');
			if ($multilang) {
				@unlink($tmp . '/content/home/home.txt');
				@unlink($tmp . '/content/error/error.txt');
				mkdir($tmp . '/site/languages', 0700, true);
				file_put_contents($tmp . '/site/languages/de.php', "<?php\n\nreturn ['code' => 'de', 'default' => true, 'locale' => 'de_DE.utf8', 'name' => 'Deutsch', 'url' => 'http://example.invalid'];\n");
				file_put_contents($tmp . '/site/languages/en.php', "<?php\n\nreturn ['code' => 'en', 'default' => false, 'locale' => 'en_US.utf8', 'name' => 'English', 'url' => 'http://example.invalid/en'];\n");
				foreach (['de', 'en'] as $code) {
					annai_page($tmp, 'home', 'home', ['title' => 'Start'], $code);
					annai_page($tmp, 'error', 'error', ['title' => 'Error'], $code);
					annai_page($tmp, 'laden', 'default', ['title' => 'Hofladen'], $code);
				}
			} else {
				annai_page($tmp, 'laden', 'default', ['title' => 'Hofladen']);
			}
			$fn(annai_kirby($tmp, ['annai.active' => true] + $options), $tmp);
		} finally {
			annai_remove_tree($tmp);
		}
	}
}
