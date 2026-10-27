<?php

namespace Sudare {
	/**
	 * Fake of the preview protection contract: Sudare\Sudare::active(): bool
	 * (request state) and the stateless Sudare\Sudare::protects($base): bool
	 * that Annai asks on the command line. In Sudare 1.0.0 active() is
	 * Config::protects() of the configuration handle() remembered before the
	 * guard decided, so it is true for an exempt path (robots.txt, sitemap.xml,
	 * llms.txt let through to a crawler) as well: $on models that, and there
	 * is no "exempt" case to model (docs/SPEC.md §7).
	 */
	class Sudare
	{
		public static string|null $base = null;

		/** Paths the configuration exempts (Sudare 1.0.0 Config::isExempt()). */
		public static array $exempt = [];
		public static bool $on = false;
		public static bool $protects = false;

		public static function active(): bool
		{
			return self::$on;
		}

		/** The configuration of the guarded request: null when no guard ran (CLI). */
		public static function config(): FakeConfig|null
		{
			return self::$on ? new FakeConfig(self::$exempt) : null;
		}

		public static function protects(string $base, array|null $env = null): bool
		{
			self::$base = $base;
			return self::$protects;
		}
	}

	/** Fake of Sudare\Config: isExempt($path, $method, $sent) on a list of paths. */
	class FakeConfig
	{
		public function __construct(private array $exempt)
		{
		}

		public function isExempt(string $path, string $method = 'GET', string|null $sent = null): bool
		{
			return in_array($path, $this->exempt, true);
		}
	}
}
