<?php

/**
 * English strings — the default and the fallback for missing keys.
 * `{name}` placeholders are filled in by Annai\I18n::t(). tests/I18nTest.php
 * checks that every language file has the same keys and placeholders as this
 * one. Keys are the contract (docs/SPEC.md §5); never rename one without a
 * CHANGELOG entry. The check tool and the CLI are English only and do not
 * use this table.
 */
return [
	// Weekdays, ISO-8601 numbering (1 = Monday). I18n::day(0) is Monday.
	'day.1' => 'Monday', 'day.2' => 'Tuesday', 'day.3' => 'Wednesday', 'day.4' => 'Thursday',
	'day.5' => 'Friday', 'day.6' => 'Saturday', 'day.7' => 'Sunday',

	// PHP date() format for I18n::date().
	'format.date' => 'j M Y',

	// llms.txt (LlmsTxt::render(), docs/SPEC.md §3.5).
	'llms.contact' => 'Contact',
	'llms.address' => 'Address',
	'llms.phone' => 'Phone',
	'llms.email' => 'Email',
	'llms.action' => 'Main action',
	'llms.hours' => 'Opening hours',
	'llms.hoursLine' => '{hours}',
	'llms.closed' => 'closed',
	'llms.holidaysClosed' => 'Public holidays: closed',
	'llms.onlyHours' => 'only {hours}',
	'llms.range' => '{from} to {to}',
	'llms.events' => 'Events',
	'llms.cancelled' => 'cancelled',
	'llms.pages' => 'Pages',
	'llms.optional' => 'Optional',
	'llms.home' => 'Home',
];
