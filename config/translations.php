<?php

use Sayamaapps\Annai\I18n;

// Strings of the plugin for Kirby's translations: every key as t('annai.<key>').
$translations = [];
foreach (I18n::LANGUAGES as $language) {
	foreach (I18n::table($language) as $key => $text) {
		$translations[$language]['annai.' . $key] = $text;
	}
}

return $translations;
