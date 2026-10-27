<?php
/**
 * Shared test helpers. Loaded by tests/bootstrap.php, so every test class sees
 * them. The checks are PHPUnit assertions: expect() compares strictly, holds()
 * asserts a truth. A failing check fails the test method and PHPUnit prints
 * the label.
 */

use PHPUnit\Framework\Assert;

/** Strict comparison ($expected === $actual). */
function expect(mixed $expected, mixed $actual, string $what): void
{
	Assert::assertSame($expected, $actual, $what);
}

function holds(bool $ok, string $what): void
{
	Assert::assertTrue($ok, $what);
}

/**
 * Counts as a passed check, like before the port. The reason stays in the
 * source next to the call; a skipped PHPUnit test would hide the other checks
 * of the method.
 */
function skip(string $what, string $why): void
{
	holds(true, $what . ' (skipped: ' . $why . ')');
}

/** Only these keys of an array, in this order. */
function only(array $array, array $keys): array
{
	$result = [];
	foreach ($keys as $key) {
		$result[$key] = $array[$key] ?? null;
	}
	return $result;
}

/* --------------------------------------------------------------------------
 * Kirby fixtures (need vendor/: composer install).
 * -------------------------------------------------------------------------- */

/** Path of the sibling package, or null: ANNAI_NOREN_PATH / ANNAI_KOYOMI_PATH, else ../kirby-<name>. */
function annai_sibling(string $name): string|null
{
	$env = getenv('ANNAI_' . strtoupper($name) . '_PATH');
	$candidates = [$env !== false ? $env : null, dirname(__DIR__, 2) . '/kirby-' . $name];
	foreach ($candidates as $path) {
		if (is_string($path) && $path !== '' && is_file($path . '/index.php')) {
			return realpath($path) ?: $path;
		}
	}
	return null;
}

/**
 * A throwaway site directory with the package symlinked as
 * site/plugins/annai, a default template and a home page. Returns its path;
 * remove it with annai_remove_tree() in a finally block.
 *
 * $with: sibling packages to symlink as well, e.g. ['noren', 'koyomi']. A
 * missing sibling is silently left out — test with annai_sibling() first.
 *
 * Templates print the head snippet so AgentReady-style checks can read the
 * JSON-LD from the rendered page.
 */
function annai_site(array $with = []): string
{
	$base = dirname(__DIR__);
	if (!is_file($base . '/vendor/autoload.php')) {
		fwrite(STDERR, "Composer dependencies missing; run composer install first\n");
		exit(1);
	}
	require_once $base . '/vendor/autoload.php';

	$tmp = sys_get_temp_dir() . '/annai-kirby-' . bin2hex(random_bytes(6));
	foreach (['public', 'content/home', 'content/error', 'site/config', 'site/plugins', 'site/snippets', 'site/templates', 'site/blueprints/pages', 'storage'] as $dir) {
		mkdir($tmp . '/' . $dir, 0700, true);
	}
	symlink($base, $tmp . '/site/plugins/annai');
	foreach ($with as $name) {
		$path = annai_sibling($name);
		if ($path !== null) {
			symlink($path, $tmp . '/site/plugins/' . $name);
		}
	}
	$template = '<html><head><?php snippet("annai/head") ?></head><body>Fixture</body></html>';
	foreach (['default', 'home', 'betrieb', 'weiter', 'rechtstext'] as $name) {
		file_put_contents($tmp . '/site/templates/' . $name . '.php', $template);
	}
	file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
	file_put_contents($tmp . '/content/home/home.txt', "Title: Home\n");
	file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");
	return $tmp;
}

/**
 * A fresh Kirby instance on an annai_site() directory with these options.
 * Snippet paths are cached per process by Kirby, so the cache is reset.
 * The site URL is http://example.invalid unless $options says otherwise.
 */
function annai_kirby(string $tmp, array $options = []): Kirby\Cms\App
{
	Kirby\Template\Snippet::$cache = [];
	return new Kirby\Cms\App([
		'roots' => [
			'base'    => $tmp,
			'index'   => $tmp . '/public',
			'content' => $tmp . '/content',
			'site'    => $tmp . '/site',
			'storage' => $tmp . '/storage',
		],
		// $options first: with `+` the left array wins, and the docblock promises they override the defaults.
		'options' => $options + ['url' => 'http://example.invalid', 'debug' => false, 'cache' => false],
	]);
}

/**
 * Writes content/<dir>/<template>.txt. Array values (structure fields) are
 * YAML-encoded the way the Panel stores them. $language adds the
 * ".<code>" part of a multilang content file name.
 *
 *   annai_page($tmp, '1_kontakt', 'betrieb', ['title' => 'Kontakt', 'strasse' => 'Musterweg 1']);
 *   annai_page($tmp, '', 'site', ['title' => 'Testhof', 'marke' => 'Testhof']);   // content/site.txt
 */
function annai_page(string $tmp, string $dir, string $template, array $fields, string|null $language = null): void
{
	$lines = [];
	foreach ($fields as $name => $value) {
		$text = is_array($value) ? Kirby\Data\Yaml::encode($value) : (string)$value;
		$lines[] = ucfirst($name) . ': ' . (is_array($value) ? "\n\n" . $text : $text);
	}
	$folder = $dir === '' ? $tmp . '/content' : $tmp . '/content/' . $dir;
	@mkdir($folder, 0700, true);
	$file = $template . ($language !== null ? '.' . $language : '') . '.txt';
	file_put_contents($folder . '/' . $file, implode("\n\n----\n\n", $lines) . "\n");
}

/**
 * Calls a route or page through Kirby's router, like a request would:
 *   [$code, $body, $type] = annai_request($kirby, 'robots.txt', 'HEAD');
 * A null response (nothing matched) gives [404, '', null].
 */
function annai_request(Kirby\Cms\App $kirby, string $path, string $method = 'GET'): array
{
	// Kirby's Responder is shared per App instance and keeps the code of the
	// previous render (an error page leaves 404 behind), so it is reset first.
	$reset = function (): void {
		$this->code = null;
		$this->body = null;
		$this->type = null;
		$this->headers = [];
		$this->cache = true;
		$this->expires = null;
	};
	Closure::bind($reset, $kirby->response(), Kirby\Cms\Responder::class)();
	$response = $kirby->render($path, $method);
	if ($response === null) {
		return [404, '', null];
	}
	return [$response->code(), (string)$response->body(), $response->type()];
}

/** JSON-LD blocks of an HTML string, decoded (JSON_THROW_ON_ERROR). @return list<array> */
function annai_jsonld(string $html): array
{
	preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
	return array_map(fn ($block) => json_decode($block, true, 512, JSON_THROW_ON_ERROR), $blocks[1]);
}

/** Runs $fn with the php error_log written to a file in $tmp, then restores the setting; returns the log. */
function annai_with_error_log(string $tmp, callable $fn): string
{
	$previous = ini_get('error_log');
	$file = $tmp . '/php-error.log';
	ini_set('error_log', $file);
	// Options logs each message once per process. The tests share one process
	// now, so the messages of earlier tests are forgotten here (a test seam
	// outside the production code).
	(new ReflectionProperty(Sayamaapps\Annai\Options::class, 'logged'))->setValue(null, []);
	try {
		$fn();
	} finally {
		ini_set('error_log', (string)$previous);
	}
	return is_file($file) ? (string)file_get_contents($file) : '';
}

/**
 * Deletes a throwaway site directory. A symlink is never followed (the package
 * is linked into the site). Kirby's Dir::remove unlinks the links inside it without following them.
 */
function annai_remove_tree(string $path): void
{
	if (!is_dir($path) || is_link($path)) {
		return;
	}
	\Kirby\Filesystem\Dir::remove($path);
}
