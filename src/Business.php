<?php

namespace Sayamaapps\Annai;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\Url;
use Kirby\Content\Field;
use Noren\Schedule;

/**
 * The facts of the business: name, address, phone, hours from Noren, the
 * JSON-LD document and the head input (docs/SPEC.md §3.8). Kirby side: reads the
 * site and the location page, Noren's hours, and hands plain arrays to the
 * Kirby-free layer (Text, JsonLd, Head). Annai never computes hours itself.
 */
final class Business
{
	private const LOCATION_KEYS = ['street', 'postalCode', 'city', 'phone', 'fax', 'email'];

	/** @var ?\WeakMap<Site, array{page: ?Page, index: bool}> byTemplate() per site object */
	private static \WeakMap|null $byTemplate = null;

	/**
	 * Is a unit at the business, so that its department @id is the bare area key
	 * (`#cafe`)? A unit with an address of its own that differs (`place`) is not.
	 * Otherwise it is when its page is the business page, when Noren has only
	 * the site schedule (no page), or when its page carries no address of its
	 * own: the hours live on one page, the address on `annai.location` or in
	 * site.txt (also with `fn () => null`, no business page at all). Only a page
	 * with an address of its own — even the business's — keeps its page id in
	 * the fragment (`#b-cafe`), so two pages with the same area key never share
	 * an @id. The fragment used to carry the page id whenever the Noren page was
	 * not the business page, and a changed @id is a new entity for Google.
	 */
	private static function atBusiness(App $kirby, Page|null $page, Page|null $business, array|null $place): bool
	{
		if ($place !== null) {
			return false;
		}
		if ($page === null || ($business !== null && $page->id() === $business->id())) {
			return true;
		}
		$own = self::place($kirby, $page);
		return $own['street'] === '' && $own['city'] === '';
	}

	/** Blocks as [[from, to], …] integers. @return list<array{0: int, 1: int}> */
	private static function blocks(array $blocks): array
	{
		$result = [];
		foreach ($blocks as $block) {
			if (is_array($block) && count($block) >= 2) {
				$result[] = [(int)$block[0], (int)$block[1]];
			}
		}
		return $result;
	}

	/**
	 * The template search of location(), once per site object (one request):
	 * the top-level pages, then their children, and only when neither holds a
	 * page with the location template the whole $site->index(). Building the
	 * index reads every page folder (seven to ten times the time of a page view
	 * on a site with 2,000 posts); the business page is almost always at the top.
	 * 'index' says whether the whole index was searched.
	 *
	 * @return array{page: ?Page, index: bool}
	 */
	private static function byTemplate(App $kirby): array
	{
		$site = $kirby->site();
		self::$byTemplate ??= new \WeakMap();
		if (isset(self::$byTemplate[$site])) {
			return self::$byTemplate[$site];
		}

		$templates = Options::locationTemplates($kirby);
		$first = static function (iterable $pages) use ($templates): Page|null {
			foreach ($pages as $page) {
				if (!Pages::isDraft($page) && in_array($page->intendedTemplate()->name(), $templates, true)) {
					return $page;
				}
			}
			return null;
		};

		$children = $site->children();
		$page = $first($children) ?? $first($children->children());
		$index = false;
		if ($page === null) {
			$index = true;
			$page = $first($site->index());
		}
		return self::$byTemplate[$site] = ['page' => $page, 'index' => $index];
	}

	/**
	 * Canonical (§3.7.2): $page->url(), never query parameters; hook
	 * `annai.canonical` first. On a multi-language site a page without content
	 * in the current language (Kirby renders it with the default language's
	 * content) points to the language version that has the content
	 * (Pages::contentUrl()): it used to point to itself, a duplicate of the
	 * default language's page in every language it is not translated into.
	 * The hook keeps its precedence; its URL goes through Text::line() like
	 * every other head text.
	 */
	private static function canonical(App $kirby, Page $page): string
	{
		$hook = Options::hook($kirby, 'canonical');
		if ($hook !== null) {
			$value = Options::call($kirby, 'canonical', $hook, [$page], null, 'using the page URL');
			if ($value === null) {
				return self::pageCanonical($kirby, $page);
			}
			if (is_string($value) && Text::line($value) !== '') {
				return Text::line($value);
			}
			Options::warn($kirby, 'Annai: annai.canonical must return a URL string — using the page URL.');
		}
		return self::pageCanonical($kirby, $page);
	}

	/**
	 * Meta description source (§3.7.2): page intro field, else site description; excerpted.
	 * The fields are rendered with kirbytext() first: Markdown and Kirbytags (`**x**`, `(link: …)`) become plain text, not raw syntax.
	 * The hook `annai.description` replaces the source (still excerpted); null, an
	 * error or a result that is not text falls back to the fields. The hook delivers
	 * plain text, not HTML: it is escaped before Text::excerpt() strips tags and
	 * decodes entities, so "Kinder <6 Jahre …" keeps its text (strip_tags() ate
	 * everything from the "<" on) and "&amp;" stays as the hook wrote it.
	 *
	 * `annai.descriptionFallback`: the chain is intro, then the page's description
	 * field, then the site description (fallbackSource()). The hook is not
	 * affected: what it writes is what the description says.
	 */
	private static function description(App $kirby, Page $page, array $facts): string
	{
		$hook = Options::hook($kirby, 'description');
		$source = $hook !== null
			? self::hookText($kirby, 'description', Options::call($kirby, 'description', $hook, [$page, $facts], null))
			: null;
		if ($source !== null) {
			$source = Text::esc($source);
		} else {
			$fields = Options::fields($kirby);
			$intro = $page->content()->get($fields['intro']);
			if (Options::descriptionFallback($kirby)) {
				$source = self::fallbackSource($kirby, $page, $fields);
			} else {
				$source = self::kirbytext($intro->isEmpty() ? $kirby->site()->content()->get($fields['description']) : $intro);
			}
		}
		return Text::excerpt($source, Options::descriptionLength($kirby));
	}

	/**
	 * Text::phone() of a raw field value. A field that is not empty and gives no number is
	 * logged with its reason (Text::phoneProblem(); SPEC §3.2, the input contract) — a site problem in the check that says which field to fix. The value is
	 * shown as stored (Text::shown()), so a line break or a tab in it is visible.
	 */
	private static function e164(App $kirby, string $raw, string $country, string $field): string
	{
		$e164 = Text::phone($raw, $country);
		if ($e164 !== '') {
			return $e164;
		}
		$value = Text::shown($raw);
		$message = match (Text::phoneProblem($raw, $country)) {
			'characters' => 'field ' . $field . ' holds more than the number (' . $value
				. ') — nothing is published as E.164; write only the number: digits, spaces, a leading +, "/", "-" and parentheses. Notes go into another field.',
			'invalid' => 'field ' . $field . ' holds no valid phone number (' . $value
				. ') — nothing is published as E.164; write the whole number with its area code (a national number counts for +' . $country
				. ', annai.phoneCountry) or with its country code (+…).',
			'library' => 'phone numbers need giggsey/libphonenumber-for-php-lite, which is not installed — nothing is published as E.164; install Annai with Composer (README, Installation).',
			default => null,
		};
		if ($message !== null) {
			Options::warn($kirby, 'Annai: ' . $message);
		}
		return $e164;
	}

	/**
	 * The JSON-LD document only when it encodes. Options::call() catches what
	 * a hook throws, not what its result does later: a node with NAN or INF
	 * (or one nested deeper than 512 levels) made JsonLd::script() throw in
	 * the snippet and took the page down. Here it is logged and left out, so
	 * the page renders; Check::page() reads jsonLd() itself and reports it.
	 */
	private static function encodable(App $kirby, Page $page, array|null $document): array|null
	{
		if ($document === null) {
			return null;
		}
		try {
			JsonLd::encode($document);
			return $document;
		} catch (\Throwable $error) {
			Options::warn($kirby, 'Annai: the JSON-LD of ' . $page->id() . ' cannot be encoded (' . $error::class . ': ' . $error->getMessage() . ') — left out.');
			return null;
		}
	}

	/**
	 * Everything the outputs need, read once per call (no memoisation across requests):
	 *   ['name'        => brand field else $site->title(),         // Text::line()
	 *    'tagline'     => tagline field,
	 *    'description' => site description field rendered with kirbytext() and reduced to plain text
	 *                     (Text::plain(): no Markdown, Kirbytag or HTML), NOT excerpted; the JSON-LD
	 *                     description and the llms.txt summary,
	 *    'url'         => Kirby's base URL (Pages::base(), the same in every language),
	 *    'image'       => absolute URL string | null   (resolveImage() for the home page),
	 *    'phone'       => display string (Text::line()), 'phoneE164' => Text::phone(raw field, phoneCountry),
	 *                     '' with a log line when the field is not empty but gives no number (e164():
	 *                     more than the number, no valid number, libphonenumber missing; SPEC §3.2),
	 *    'fax'         => …, 'faxE164' => …, 'email' => …,
	 *    'street' => …, 'postalCode' => …, 'city' => …, 'country' => Options country ('DE'),
	 *    'geo'         => ['lat' => 52.52, 'lng' => 13.41] | null   (Options::geo()),
	 *    'sameAs'      => list<string>: Options sameAs (list or Closure) + profile fields (§6), '' dropped, unique,
	 *    'action'      => ['type' => 'OrderAction', 'name' => 'Online bestellen', 'target' => 'https://…'] | null,
	 *    'type'        => 'LocalBusiness' | 'GroceryStore' | false,
	 *    'entityId'    => 'business' (Options),
	 *    'units'       => units($kirby), or [] with $withUnits false (see showsUnits())]
	 * [] when Annai is off.
	 */
	public static function facts(App $kirby, bool $withUnits = true): array
	{
		if (!Options::active($kirby)) {
			return [];
		}

		$site = $kirby->site();
		$fields = Options::fields($kirby);
		$location = self::location($kirby);
		// Only the location keys (§6) look at the location page first; brand, tagline and description are site fields.
		$read = static fn (string $key): string => self::value(
			$kirby,
			in_array($key, self::LOCATION_KEYS, true) ? $location : null,
			(string)$fields[$key]
		);

		$name = Text::line($read('brand'));
		if ($name === '') {
			$name = Text::line((string)$site->title());
		}

		// The raw value goes to Text::phone(): a line break is a character the input
		// contract does not allow (SPEC §3.2); Text::line() makes the display string.
		$phoneRaw = $read('phone');
		$faxRaw = $read('fax');
		$phone = Text::line($phoneRaw);
		$fax = Text::line($faxRaw);
		$country = Options::phoneCountry($kirby);

		$home = $site->homePage();
		$image = null;
		if ($home instanceof Page) {
			$resolved = self::resolveImage($kirby, $home);
			$image = $resolved['url'] ?? null;
		}

		// sameAs takes URLs only: a handle ("@testhof") or a host without a scheme
		// ("facebook.com/testhof") went into the JSON-LD as written and is no link.
		$candidates = [];
		foreach (Options::sameAs($kirby) as $url) {
			$candidates[] = [$url, 'annai.sameAs'];
		}
		foreach ($fields['profiles'] as $profile) {
			$candidates[] = [self::value($kirby, $location, $profile), 'field ' . $profile];
		}
		$sameAs = [];
		foreach ($candidates as [$url, $source]) {
			$url = trim(Text::utf8((string)$url));
			if ($url === '') {
				continue;
			}
			$shown = Text::shown($url);
			if (preg_match('#^https?://#i', $url) !== 1) {
				Options::warn($kirby, 'Annai: sameAs entry ' . $shown . ' (' . $source . ') is not an http(s) URL — left out.');
				continue;
			}
			// A URL holds no whitespace and no control character (C0, DEL, C1):
			// an escape sequence from a Panel field used to reach the JSON-LD raw.
			if (preg_match('/[\x{00}-\x{20}\x{7F}-\x{9F}]/u', $url) === 1) {
				Options::warn($kirby, 'Annai: sameAs entry ' . $shown . ' (' . $source . ') holds a space or a control character — left out.');
				continue;
			}
			$sameAs[] = $url;
		}
		$sameAs = array_values(array_unique($sameAs));

		return [
			'name'        => $name,
			'tagline'     => Text::line($read('tagline')),
			// Rendered like the meta description: "**Bio**" and "(link: …)" went into
			// the JSON-LD and the llms.txt summary as written. Plain text, not shortened.
			'description' => Text::plain(self::kirbytext($site->content()->get((string)$fields['description']))),
			// The base URL, not $site->url(): on a multi-language site that is the
			// current language's URL, and the business @id must not change with it.
			'url'         => Pages::base($kirby),
			'image'       => $image,
			'phone'       => $phone,
			'phoneE164'   => $phone !== '' ? self::e164($kirby, $phoneRaw, $country, (string)$fields['phone']) : '',
			'fax'         => $fax,
			'faxE164'     => $fax !== '' ? self::e164($kirby, $faxRaw, $country, (string)$fields['fax']) : '',
			'email'       => Text::line($read('email')),
			'street'      => Text::line($read('street')),
			'postalCode'  => Text::line($read('postalCode')),
			'city'        => Text::line($read('city')),
			'country'     => Options::country($kirby),
			'geo'         => Options::geo($kirby),
			'sameAs'      => $sameAs,
			'action'      => Options::action($kirby),
			'type'        => Options::type($kirby),
			'entityId'    => Options::entityId($kirby),
			'units'       => $withUnits ? self::units($kirby) : [],
		];
	}

	/**
	 * The chain of `annai.descriptionFallback` (rendered, not excerpted): the page's
	 * intro, else the page's description field (the same field name as the site's,
	 * `annai.fields` description), else the site description. Each step gives way to
	 * the next one only while it is shorter than Check::DESCRIPTION_MIN and the next
	 * one is longer, so the minimum applies to every step. A text that is shorter than
	 * the minimum and has no longer successor stays as it is.
	 *
	 * @param array<string, mixed> $fields Options::fields()
	 */
	private static function fallbackSource(App $kirby, Page $page, array $fields): string
	{
		$source = self::kirbytext($page->content()->get($fields['intro']));
		$steps = [
			$page->content()->get($fields['description']),
			$kirby->site()->content()->get($fields['description']),
		];
		foreach ($steps as $field) {
			if (mb_strlen(Text::plain($source)) >= Check::DESCRIPTION_MIN) {
				break;
			}
			$next = self::kirbytext($field);
			if (mb_strlen(Text::plain($next)) > mb_strlen(Text::plain($source))) {
				$source = $next;
			}
		}
		return $source;
	}

	/**
	 * One business field by its English key (§6): the location page's field when it is not
	 * empty, else the site field of the same (configured) name, trimmed. '' when both are empty.
	 * A site that keeps address and phone in site.txt needs no location page.
	 */
	public static function field(App $kirby, string $key): string
	{
		$fields = Options::fields($kirby);
		$name = $fields[$key] ?? $key;
		if (!is_string($name)) {
			return '';
		}
		$location = in_array($key, self::LOCATION_KEYS, true) ? self::location($kirby) : null;
		return self::value($kirby, $location, $name);
	}

	/**
	 * Head::build() input for a page (§3.7.1), with the rules of §3.7.2:
	 *   title, description (excerpted), canonical, robots (Pages::robots($kirby, $page)),
	 *   ogType 'website', siteName = facts name, locale = Options::locale($kirby),
	 *   image = resolveImage($kirby, $page), twitterCard = Options twitterCard,
	 *   alternates = Pages::alternates($kirby, $page), jsonld = jsonLd($kirby, $page).
	 * The facts carry the units only where the head shows them (showsUnits()): the
	 * title and description hooks see `units` [] on another page without a jsonld hook.
	 * [] when Annai is off.
	 */
	public static function head(App $kirby, Page $page): array
	{
		if (!Options::active($kirby)) {
			return [];
		}

		$facts = self::facts($kirby, self::showsUnits($kirby, $page));

		return [
			'title'       => self::title($kirby, $page, $facts),
			'description' => self::description($kirby, $page, $facts),
			'canonical'   => self::canonical($kirby, $page),
			'robots'      => Pages::robots($kirby, $page),
			'ogType'      => 'website',
			'siteName'    => $facts['name'],
			'locale'      => Options::locale($kirby),
			'image'       => self::resolveImage($kirby, $page),
			'twitterCard' => Options::twitterCard($kirby),
			'alternates'  => Pages::alternates($kirby, $page),
			'jsonld'      => self::encodable($kirby, $page, self::jsonLd($kirby, $page, $facts)),
		];
	}

	/**
	 * A hook's text result: a string, a number or a Stringable (a Kirby Field)
	 * as string; null stays null (the default applies); anything else (an
	 * array, a plain object) is reported and gives null.
	 */
	private static function hookText(App $kirby, string $key, mixed $value): string|null
	{
		if ($value === null) {
			return null;
		}
		if (is_string($value) || is_int($value) || is_float($value) || $value instanceof \Stringable) {
			return (string)$value;
		}
		Options::warn($kirby, 'Annai: annai.' . $key . ' must return a string, not ' . get_debug_type($value) . ' — using the default.');
		return null;
	}

	/** `[lat, lng]` style hours: two integers in minutes, else null. */
	private static function hours(mixed $value): array|null
	{
		if (!is_array($value) || count($value) < 2 || !is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null)) {
			return null;
		}
		return [(int)$value[0], (int)$value[1]];
	}

	/**
	 * The JSON-LD document for a page, or null:
	 *   nodes = []
	 *   if $page->isHomePage() and type !== false: nodes[] = JsonLd::localBusiness(facts)   (skipped when [])
	 *   hook `annai.jsonld` (Closure(Page $page, array $nodes, array $facts): array) may add, replace or remove nodes
	 *   return JsonLd::document(nodes)
	 * null when Annai is off. $facts: facts() when the caller has them already (head());
	 * else facts() with the units only where they are shown (showsUnits()).
	 */
	public static function jsonLd(App $kirby, Page $page, array|null $facts = null): array|null
	{
		if (!Options::active($kirby)) {
			return null;
		}

		$facts ??= self::facts($kirby, self::showsUnits($kirby, $page));
		$nodes = [];
		if ($page->isHomePage() && $facts['type'] !== false) {
			$node = JsonLd::localBusiness($facts);
			if ($node !== []) {
				$nodes[] = $node;
			}
		}

		$hook = Options::hook($kirby, 'jsonld');
		if ($hook !== null) {
			// A throwing hook leaves Annai's own nodes in place.
			$result = Options::call($kirby, 'jsonld', $hook, [$page, $nodes, $facts], $nodes, "using Annai's nodes");
			if (is_array($result)) {
				$nodes = $result;
			} else {
				Options::warn($kirby, 'Annai: annai.jsonld must return an array of nodes — ignored.');
			}
		}

		return JsonLd::document($nodes);
	}

	/**
	 * A field rendered with kirbytext().
	 * Invalid UTF-8 is replaced first: kirbytext() makes "<p></p>" of a text with
	 * one Latin-1 byte. HTML out — Text::excerpt() or Text::plain() make text of it.
	 */
	private static function kirbytext(Field $field): string
	{
		return (string)$field->value(Text::utf8((string)$field->value()))->kirbytext();
	}

	/**
	 * The location page: option `annai.location` (page id string, or Closure(): ?Page),
	 * else $site->norenLocation() when Noren is installed (function_exists('noren_option')) and
	 * `noren.active` is true and `annai.noren` is true, else the first page whose intendedTemplate
	 * is in Options::locationTemplates() ('betrieb' by default): the top level first, then the
	 * second level, then the rest of $site->index() (see byTemplate()).
	 * Drafts, and pages below a draft (Pages::isDraft()), are never returned. null when nothing matches.
	 */
	public static function location(App $kirby): Page|null
	{
		$configured = Options::location($kirby);

		if ($configured instanceof Closure) {
			$page = Options::call($kirby, 'location', $configured, [], null, 'no business page');
			return $page instanceof Page && !Pages::isDraft($page) ? $page : null;
		}

		if (is_string($configured)) {
			$page = $kirby->page($configured);
			if (!$page instanceof Page) {
				Options::warn($kirby, 'Annai: annai.location "' . $configured . '" is not a page — no business page.');
				return null;
			}
			return Pages::isDraft($page) ? null : $page;
		}

		if (self::norenUsed($kirby)) {
			$page = $kirby->site()->norenLocation();
			if ($page instanceof Page && !Pages::isDraft($page)) {
				return $page;
			}
		}

		return self::byTemplate($kirby)['page'];
	}

	/**
	 * Is `noren.locations` set, so that Noren resolves its location pages from
	 * the option instead of walking $site->index()? Noren's own rule: null and
	 * false mean "unset" (Noren\Locations::pages()).
	 */
	private static function norenLocationsSet(App $kirby): bool
	{
		$locations = $kirby->option('noren.locations');
		return $locations !== null && $locations !== false;
	}

	/**
	 * The Noren part of searchesIndex(): Noren in use and `noren.locations`
	 * unset, so Noren walks $site->index() for its location pages, whatever
	 * `annai.location` says. The check reports it with its own text
	 * (Check::NOREN_LOCATIONS_PROBLEM): `annai.location` does not help there.
	 */
	public static function norenSearchesIndex(App $kirby): bool
	{
		return self::norenUsed($kirby) && !self::norenLocationsSet($kirby);
	}

	/* ----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	/** Noren's hours are used: Noren active, its option set, and `annai.noren` not false. */
	private static function norenUsed(App $kirby): bool
	{
		return function_exists('noren_option') &&
			$kirby->option('noren.active') === true &&
			Options::flag($kirby, 'noren', true);
	}

	/** Fields that live on the location page first, then on the site (docs/SPEC.md §6). */
	/**
	 * The address of a unit's location page when it is somewhere else than the
	 * business: only with an address of its own (street or city) that differs
	 * from the business address as facts() publishes it — the business page's
	 * fields, else the site's; the site's alone without a business page
	 * (`annai.location => fn () => null`). A Noren page with hours but no address
	 * (the business address lives on `annai.location` or in site.txt) is at the
	 * business: null. Without a business page every Noren page used to count as
	 * at the business, so a second location became a department with the MAIN
	 * address and phone, and llms.txt lost its address lines.
	 *
	 * @return ?array{street: string, postalCode: string, city: string, phone: string, phoneE164: string}
	 */
	private static function otherPlace(App $kirby, Page|null $page, Page|null $business): array|null
	{
		if ($page === null || ($business !== null && $page->id() === $business->id())) {
			return null;
		}
		$place = self::place($kirby, $page);
		if ($place['street'] === '' && $place['city'] === '') {
			return null;
		}
		// The business address as facts() reads it: the business page's field, else the site's.
		$fields = Options::fields($kirby);
		$home = [];
		foreach (['street', 'postalCode', 'city'] as $key) {
			$home[$key] = Text::line(self::value($kirby, $business, $fields[$key]));
		}
		$same = static fn (array $a): string => mb_strtolower($a['street'] . '|' . $a['postalCode'] . '|' . $a['city']);
		return $same($place) === $same($home) ? null : $place;
	}

	/** Annai's own canonical: the content language's URL on a multi-language site (Pages::contentUrl()), else the page URL. */
	private static function pageCanonical(App $kirby, Page $page): string
	{
		return Pages::contentUrl($kirby, $page) ?? $page->url();
	}

	/** A positive pixel count, else null. */
	private static function pixels(mixed $value): int|null
	{
		return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
	}

	/**
	 * Address and phone of another location page, from its own fields only:
	 * ['street', 'postalCode', 'city', 'phone', 'phoneE164'].
	 *
	 * @return array{street: string, postalCode: string, city: string, phone: string, phoneE164: string}
	 */
	private static function place(App $kirby, Page $page): array
	{
		$fields = Options::fields($kirby);
		$read = static fn (string $key): string => Text::line((string)$page->content()->get($fields[$key]));
		$phoneRaw = (string)$page->content()->get($fields['phone']);
		$phone = Text::line($phoneRaw);
		return [
			'street'     => $read('street'),
			'postalCode' => $read('postalCode'),
			'city'       => $read('city'),
			'phone'      => $phone,
			'phoneE164'  => $phone !== '' ? self::e164($kirby, $phoneRaw, Options::phoneCountry($kirby), (string)$fields['phone']) : '',
		];
	}

	/**
	 * Index of the primary unit in $units, or null. Its hours go into the
	 * business node next to the business address and phone, so it must be a
	 * unit at the business location (`place` null): the one whose area is
	 * `annai.area`, else the first. An `annai.area` that names only an area of
	 * another location is reported; that unit stays a department with its own
	 * address. An `annai.area` that matches no unit at all (a typo, an area
	 * without hours) is reported too — it used to fall back to the first unit
	 * in silence. null when no unit is at the business location.
	 */
	private static function primary(App $kirby, array $units): int|null
	{
		$area = Options::area($kirby);
		$first = null;
		$elsewhere = null;
		$matched = false;
		foreach ($units as $index => $unit) {
			$here = $unit['place'] === null;
			if ($area !== null && $unit['area'] === $area) {
				if ($here) {
					return $index;
				}
				$matched = true;
				$elsewhere ??= $unit['location'];
			}
			if ($here) {
				$first ??= $index;
			}
		}
		if ($elsewhere !== null) {
			Options::warn($kirby, 'Annai: annai.area "' . $area . '" is an area of the location "' . $elsewhere
				. '", not of the business page — it stays a department with its own address; the business node uses the first area of the business page.');
		} elseif ($area !== null && !$matched) {
			Options::warn($kirby, 'Annai: annai.area "' . $area . '" is no area with hours of any Noren location — the business node uses the first area at the business.');
		}
		return $first;
	}

	/**
	 * `annai.image`: null → null; string → absolute URL (http(s) kept, else Kirby url()); array
	 * ['url' => …, 'width' => …, 'height' => …, 'alt' => …] → same with url resolved; Closure(Page): string|array|null.
	 * Returns ['url' => string, 'width' => ?int, 'height' => ?int, 'alt' => string] or null.
	 * The url and the alt go through Text::line(): the alt usually comes from a
	 * Panel field ($page->image()->alt()), and an escape sequence in it used to reach
	 * og:image:alt raw while every other head text was filtered.
	 */
	public static function resolveImage(App $kirby, Page $page): array|null
	{
		$value = Options::get($kirby, 'image');
		if ($value instanceof Closure) {
			$value = Options::call($kirby, 'image', $value, [$page], null, 'no share image');
		}
		if ($value === null || $value === false) {
			return null;
		}

		if (is_string($value)) {
			$url = Text::line($value);
			$width = $height = null;
			$alt = '';
		} elseif (is_array($value)) {
			$url = is_string($value['url'] ?? null) ? Text::line($value['url']) : '';
			$width = self::pixels($value['width'] ?? null);
			$height = self::pixels($value['height'] ?? null);
			$alt = is_string($value['alt'] ?? null) ? Text::line($value['alt']) : '';
		} else {
			Options::warn($kirby, 'Annai: annai.image must be a path, a URL, an array or a Closure — ignored.');
			return null;
		}

		if ($url === '') {
			return null;
		}
		return [
			'url'    => preg_match('#^https?://#i', $url) === 1 ? $url : Url::to($url),
			'width'  => $width,
			'height' => $height,
			'alt'    => $alt,
		];
	}

	/**
	 * Did location() have to search the whole page index (no `annai.location`,
	 * no Noren location, no page with the location template in the top two
	 * levels)? Then every request walks every page; the check tool reports it.
	 *
	 * With Noren and without `noren.locations`, Noren itself finds its location
	 * pages by walking $site->index() (Noren\Locations::pages()), on every page
	 * view that asks for the business page — the cost byTemplate() avoids. That
	 * counts as an index search too (on a site with 2,000 posts the head of every
	 * page took twenty times as long, and the check used to say nothing).
	 * That comes first: `annai.location` spares the business page lookup, not
	 * Noren's walk — units() asks Noren for the hours on the home page, in
	 * llms.txt and with an `annai.jsonld` hook on every page (the check used
	 * to say nothing for a site with `annai.location` and without
	 * `noren.locations`).
	 */
	public static function searchesIndex(App $kirby): bool
	{
		if (self::norenSearchesIndex($kirby)) {
			return true;
		}
		if (Options::location($kirby) !== null) {
			return false;
		}
		if (self::norenUsed($kirby)) {
			$page = $kirby->site()->norenLocation();
			if ($page instanceof Page && !Pages::isDraft($page)) {
				return false;
			}
		}
		return self::byTemplate($kirby)['index'];
	}

	/**
	 * Does the head of this page show the Noren units? Only the home page's
	 * business node (jsonLd()) and the `annai.jsonld` hook, which gets the facts
	 * on every page, carry hours. Noren switches Kirby's page cache off for the
	 * response whenever it computes them (its closures depend on the day), so
	 * with Noren installed EVERY page lost its cache entry: head() → facts() →
	 * units() → norenSchema(). A page that shows no hours leaves Noren alone
	 * and stays cacheable. llms.txt reads facts() with the units.
	 */
	private static function showsUnits(App $kirby, Page $page): bool
	{
		return $page->isHomePage() || Options::hook($kirby, 'jsonld') !== null;
	}

	/** A non-empty string, else null. */
	private static function text(mixed $value): string|null
	{
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}

	/**
	 * Head title (§3.7.2): home page → brand (+ tagline); other page → page title · brand.
	 * The hook `annai.title` replaces the whole rule; when it returns null, throws or
	 * returns something that is not text, the rule applies.
	 */
	private static function title(App $kirby, Page $page, array $facts): string
	{
		$hook = Options::hook($kirby, 'title');
		if ($hook !== null) {
			$title = self::hookText($kirby, 'title', Options::call($kirby, 'title', $hook, [$page, $facts], null));
			if ($title !== null) {
				return Text::line($title);
			}
		}

		$parts = $page->isHomePage()
			? [$facts['name'], $facts['tagline']]
			: [Text::line((string)$page->title()), $facts['name']];

		return implode(' · ', array_values(array_filter($parts, static fn (string $part) => $part !== '')));
	}

	/**
	 * Opening hours from Noren, as plain arrays. [] when Noren is not installed, not active,
	 * `annai.noren` is false, or there are no maintained plans. One entry per $site->norenUnits(null, false)
	 * item — one per area also with `noren.mergeAreas`, whose groups only mean "alike today" and
	 * would drop a later closure of one area from JSON-LD and llms.txt — in Noren's order, minus the units of a draft location page (Pages::isDraft(); logged
	 * once); the primary unit first:
	 *   primary = among the units at the business location (place null), the one whose area
	 *   equals `annai.area`, else the first; no primary when no unit is there (see primary()).
	 *   ['area'     => 'laden' | null, 'location' => 'kontakt' (page id of the unit's Noren location) | null,
	 *    'place'    => null, or for a unit at another location than location():
	 *                  ['street' => …, 'postalCode' => …, 'city' => …, 'phone' => …, 'phoneE164' => …] (that page's own fields),
	 *    'name'     => 'Hofladen' (Noren's `name`); with units of more than one location page Noren's
	 *                  `label` ("Praxis Nord · Praxis"), so two locations with the same area name do not
	 *                  give two identical `### Praxis` headings and department names,
	 *    'primary'  => true,
	 *    'days'     => [0 => ['blocks' => [[480, 720], [840, 1080]], 'note' => 'nur Verkauf' | null], … 6],
	 *                  // $schedule->blocks($weekday) and ->note($weekday), 0 = Monday
	 *    'holidays' => $site->norenHolidays() !== [],
	 *    'closures' => [['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => $schedule->label($closure),
	 *                    'hours' => [510, 720] | null, 'note' => string | null], …]   // ->upcomingClosures(), ALL upcoming (no lead time)
	 *    'schema'   => $locationPage->norenSchema($area)  // ['openingHoursSpecification' => [...], 'specialOpeningHoursSpecification' => [...]]
	 *   ]
	 * Mixing areas into one plan is wrong (laden + cafe hours would merge into one day), so a unit
	 * is never built from norenSchedule(null) when areas exist — norenUnits() already separates them.
	 * Noren switches the page cache off itself when it computes time-dependent data.
	 */
	public static function units(App $kirby): array
	{
		if (!Options::active($kirby) || !self::norenUsed($kirby)) {
			return [];
		}

		$site = $kirby->site();
		$units = array_values((array)$site->norenUnits(null, false));
		if ($units === []) {
			return [];
		}

		$holidays = $site->norenHolidays() !== [];
		$business = self::location($kirby);
		// A draft named in `noren.locations` (Noren's $kirby->page() finds drafts)
		// is as unpublished as any draft (docs/SPEC.md §1): its address, phone and hours
		// used to go into llms.txt and the JSON-LD departments while the page itself
		// was in no sitemap. Filtered before the label rule below, so one
		// visible location keeps the bare area name.
		$units = array_values(array_filter($units, static function (array $unit) use ($kirby): bool {
			$page = $unit['location'] ?? null;
			if ($page instanceof Page && Pages::isDraft($page)) {
				Options::warn($kirby, 'Annai: Noren location "' . $page->id() . '" is a draft — left out.');
				return false;
			}
			return true;
		}));
		// Units of more than one location page: Noren's label names the location too.
		$pages = [];
		foreach ($units as $unit) {
			$pages[($unit['location'] ?? null) instanceof Page ? $unit['location']->id() : ''] = true;
		}
		$several = count($pages) > 1;
		$result = [];
		foreach ($units as $unit) {
			/** @var Schedule $schedule */
			$schedule = $unit['schedule'];

			$days = [];
			for ($weekday = 0; $weekday < 7; $weekday++) {
				$days[$weekday] = [
					'blocks' => self::blocks($schedule->blocks($weekday)),
					'note'   => self::text($schedule->note($weekday)),
				];
			}

			$closures = [];
			foreach ($schedule->upcomingClosures() as $closure) {
				$closures[] = [
					'from'  => (string)$closure['from'],
					'to'    => (string)$closure['to'],
					'label' => $schedule->label($closure),
					'hours' => self::hours($closure['hours'] ?? null),
					'note'  => self::text($closure['note'] ?? null),
				];
			}

			// The unit's own location page: $site->norenSchema() reads the FIRST
			// location only, so a second location's area would get no hours.
			$page = ($unit['location'] ?? null) instanceof Page ? $unit['location'] : null;
			$schema = $page !== null ? $page->norenSchema($unit['area'] ?? null) : $site->norenSchema($unit['area'] ?? null);
			$place = self::otherPlace($kirby, $page, $business);

			$result[] = [
				'area'     => $unit['area'] ?? null,
				'location' => $page?->id(),
				// Address of a unit at another location than the business page (its own
				// fields, no site fallback: the site fields describe the main business).
				'place'    => $place,
				// At the business: its areas keep the bare area key as @id fragment.
				'atBusiness' => self::atBusiness($kirby, $page, $business, $place),
				'name'     => Text::line((string)($several ? ($unit['label'] ?? $unit['name'] ?? '') : ($unit['name'] ?? ''))),
				'primary'  => false,
				'days'     => $days,
				'holidays' => $holidays,
				'closures' => $closures,
				'schema'   => [
					'openingHoursSpecification'        => $schema['openingHoursSpecification'] ?? [],
					'specialOpeningHoursSpecification' => $schema['specialOpeningHoursSpecification'] ?? [],
				],
			];
		}

		$primary = self::primary($kirby, $result);
		if ($primary !== null) {
			$result[$primary]['primary'] = true;
		}

		// The primary unit first, Noren's order kept for the rest.
		usort($result, static fn (array $a, array $b) => (int)$b['primary'] <=> (int)$a['primary']);
		return $result;
	}

	/**
	 * A field value: the location page's (when $location is given and the field is not empty),
	 * else the site's, trimmed.
	 */
	private static function value(App $kirby, Page|null $location, string $name): string
	{
		if ($location !== null) {
			$value = trim((string)$location->content()->get($name));
			if ($value !== '') {
				return $value;
			}
		}
		return trim((string)$kirby->site()->content()->get($name));
	}
}
