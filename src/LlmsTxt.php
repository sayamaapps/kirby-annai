<?php

namespace Annai;

/**
 * llms.txt per llmstxt.org (SPEC.md §3.5).
 *
 * Plain arrays in, markdown out. Every title, note and name from the Panel
 * goes through plain() (no markup, no links), the summary through summary();
 * the labels come from I18n (§5), so the German file is the
 * German text and nothing is hard-coded here.
 */
final class LlmsTxt
{
    /** Link targets the summary may keep (lower case prefixes): web, mail, phone, same site. */
    private const LINK_TARGETS = ['http://', 'https://', 'mailto:', 'tel:', '/', '#'];

    /**
     * Tag-shaped text: "<" followed by a letter, "/", "!" or "?" — the only
     * starts at which CommonMark opens HTML or an autolink — up to the next ">".
     * "<6", "<3", "<-" and "<=" are not tags. An unclosed one ("Kinder <zehn
     * Jahre") keeps its text; only its "<" goes (OPENER, after the loop).
     */
    private const TAG = '/<(?=[A-Za-z\/!?])[^>]*>/u';

    /**
     * Runs of "<" left before a letter, "/", "!" or "?": "<<img" must not become
     * "<img". Anchored at the first "<" of a run (the lookbehind) and possessive:
     * "<+" tried from every "<" of a run and backtracked it when no letter
     * followed, quadratic in the run (4 s for 20,000 "<", 73 s for 50,000, in the
     * H1, the summary, every title and note; "&lt;" × 50,000 decodes into it).
     */
    private const OPENER = '/(?<!<)<++(?=[A-Za-z\/!?])/u';

    /** An e-mail address in angle brackets, kept as the bare address instead of being stripped as a tag. */
    private const BRACKETED_EMAIL = '/<([A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)>/u';

    /** C0 and C1 control characters except whitespace (a tab, a vertical tab or NEL separates words). */
    private const CONTROL = '/(?!\s)[\x{00}-\x{1F}\x{7F}-\x{9F}]/u';

    /** Percent-encoding of the characters that would end or break a Markdown link target. */
    private const URL_ESCAPES = [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E'];

    /**
     * An inline Markdown link or image: [label](target "title"). The label may
     * hold one level of brackets, the target is <…> or has balanced parentheses,
     * up to three levels (CommonMark's minimum). Possessive and not recursive: a
     * recursive label re-entered at every "[" took seconds for "[a " × 8000 on
     * every llms.txt request; a bare target with one backtracking frame per
     * character exhausted PCRE's JIT stack at about 8,000 characters (a data:
     * URI) and dropped the summary; recursive parentheses rescanned the rest of
     * the text from every "[a](" (20 s for 25,000 without JIT).
     */
    private const LINK = '/\[(?<label>(?:[^\[\]]++|\[[^\[\]]*+\])*+)\]\(\s*(?:<(?<angle>[^<>]*)>|(?<bare>(?:[^\s()]++|\((?:[^\s()]++|\((?:[^\s()]++|\([^\s()]*+\))*+\))*+\))*+))(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^()]*\)))?\s*\)/u';

    /** @param array<string,mixed> $model SPEC.md §3.5 shape. */
    public static function render(array $model): string
    {
        $blocks = [self::heading((string) ($model['title'] ?? ''))];

        $summary = self::summary((string) ($model['summary'] ?? ''));
        if ($summary !== '') {
            $blocks[] = '> ' . $summary;
        }
        foreach ((array) ($model['intro'] ?? []) as $paragraph) {
            $paragraph = Text::line((string) $paragraph);
            if ($paragraph !== '') {
                $blocks[] = $paragraph;
            }
        }

        $contact = self::contactLines((array) ($model['contact'] ?? []));
        if ($contact !== []) {
            $blocks[] = self::block(I18n::t('llms.contact'), implode("\n", $contact));
        }

        $units = array_values((array) ($model['units'] ?? []));
        if ($units !== []) {
            $parts = [];
            $several = count($units) > 1;
            foreach ($units as $unit) {
                $unit = (array) $unit;
                $body = implode("\n", self::unitLines($unit));
                // A unit at another location: its own address and phone above its hours,
                // as its department in the JSON-LD has them.
                $place = self::placeLines((array) ($unit['place'] ?? []));
                if ($place !== []) {
                    $body = implode("\n", $place) . "\n\n" . $body;
                }
                // A unit without a name gets no heading: "### " alone is an empty heading in Markdown.
                $name = $several ? self::plain((string) ($unit['name'] ?? '')) : '';
                if ($name !== '') {
                    $body = '### ' . $name . "\n\n" . $body;
                }
                $parts[] = $body;
            }
            $blocks[] = self::block(I18n::t('llms.hours'), implode("\n\n", $parts));
        }

        $events = [];
        foreach ((array) ($model['events'] ?? []) as $event) {
            $events[] = self::eventLine((array) $event);
        }
        if ($events !== []) {
            $blocks[] = self::block(I18n::t('llms.events'), implode("\n", $events));
        }

        foreach ((array) ($model['sections'] ?? []) as $section) {
            $section = (array) $section;
            $lines = [];
            foreach ((array) ($section['lines'] ?? []) as $line) {
                $line = Text::line((string) $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            if ($lines !== []) {
                $blocks[] = self::block(Text::line((string) ($section['title'] ?? '')), implode("\n", $lines));
            }
        }

        $pages = [];
        foreach ((array) ($model['pages'] ?? []) as $page) {
            $pages[] = self::entryLine((array) $page);
        }
        $blocks[] = self::block(I18n::t('llms.pages'), implode("\n", $pages));

        $optional = [];
        foreach ((array) ($model['optional'] ?? []) as $entry) {
            $optional[] = self::entryLine((array) $entry);
        }
        if ($optional !== []) {
            $blocks[] = self::block(I18n::t('llms.optional'), implode("\n", $optional));
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * The H1 "# Name", the name through plain() like every Panel text. Also the
     * whole file while Pages::hidden() (Routes::llmsText()).
     */
    public static function heading(string $name): string
    {
        return '# ' . self::plain($name);
    }

    /** Day lines for one unit (the "- Montag: …" block incl. holidays and closures), used by render(). @return list<string> */
    public static function unitLines(array $unit): array
    {
        $lines = [];
        $days = (array) ($unit['days'] ?? []);
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $day = (array) ($days[$weekday] ?? []);
            $blocks = (array) ($day['blocks'] ?? []);
            $value = $blocks === []
                ? I18n::t('llms.closed')
                : I18n::t('llms.hoursLine', ['hours' => self::ranges($blocks)]);
            $note = self::plain((string) ($day['note'] ?? ''));
            $lines[] = '- ' . I18n::day($weekday) . ': ' . $value . ($note !== '' ? ' (' . $note . ')' : '');
        }

        if (!empty($unit['holidays'])) {
            $lines[] = '- ' . I18n::t('llms.holidaysClosed');
        }

        foreach ((array) ($unit['closures'] ?? []) as $closure) {
            $closure = (array) $closure;
            $from = trim((string) ($closure['from'] ?? ''));
            $to = trim((string) ($closure['to'] ?? ''));
            if ($to === '') {
                $to = $from;
            }
            // The site language's date format (I18n::date(): 31.12.2027 on a German site).
            $from = I18n::date($from);
            $to = I18n::date($to);
            $date = $from === $to ? $from : I18n::t('llms.range', ['from' => $from, 'to' => $to]);
            $label = self::plain((string) ($closure['label'] ?? ''));
            $hours = $closure['hours'] ?? null;
            $value = is_array($hours) && count($hours) === 2
                ? I18n::t('llms.onlyHours', ['hours' => Text::range((int) $hours[0], (int) $hours[1])])
                : I18n::t('llms.closed');
            $note = self::plain((string) ($closure['note'] ?? ''));
            $lines[] = '- ' . $date . ($label !== '' ? ' (' . $label . ')' : '') . ': ' . $value
                . ($note !== '' ? ', ' . $note : '');
        }

        return $lines;
    }

    /** @return list<string> */
    private static function contactLines(array $contact): array
    {
        $lines = [];

        $address = self::plain((string) ($contact['address'] ?? ''));
        if ($address !== '') {
            $lines[] = '- ' . I18n::t('llms.address') . ': ' . $address;
        }

        $phone = self::plain((string) ($contact['phone'] ?? ''));
        $e164 = trim((string) ($contact['phoneE164'] ?? ''));
        if ($phone !== '' || $e164 !== '') {
            $value = $phone !== '' ? $phone : $e164;
            if ($phone !== '' && $e164 !== '') {
                // The tel: URI, so an agent gets the dialable target, not only the digits.
                $value .= ' (tel:' . $e164 . ')';
            }
            $lines[] = '- ' . I18n::t('llms.phone') . ': ' . $value;
        }

        $email = self::plain((string) ($contact['email'] ?? ''));
        if ($email !== '') {
            $lines[] = '- ' . I18n::t('llms.email') . ': ' . $email;
        }

        $action = $contact['action'] ?? null;
        if (is_array($action)) {
            $target = Text::line((string) ($action['target'] ?? ''));
            if ($target !== '') {
                $name = self::plain((string) ($action['name'] ?? ''));
                $lines[] = '- ' . ($name !== '' ? $name : I18n::t('llms.action')) . ': ' . $target;
            }
        }

        // Hook lines like sections and intro: Text::line() (one line, trimmed), no
        // escaping. It used to be rtrim() alone here, against SPEC §3.5.
        foreach ((array) ($contact['extra'] ?? []) as $line) {
            $line = Text::line((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Address and phone lines of a unit's `place` (Business::units(): street,
     * postalCode, city, phone, phoneE164), in the format of the contact lines.
     *
     * @return list<string>
     */
    private static function placeLines(array $place): array
    {
        if ($place === []) {
            return [];
        }
        $address = implode(', ', array_filter([
            (string) ($place['street'] ?? ''),
            trim(($place['postalCode'] ?? '') . ' ' . ($place['city'] ?? '')),
        ], fn (string $part): bool => trim($part) !== ''));
        return self::contactLines([
            'address'   => $address,
            'phone'     => (string) ($place['phone'] ?? ''),
            'phoneE164' => (string) ($place['phoneE164'] ?? ''),
        ]);
    }

    /** "- [Title](url): note" — the note only when it is not empty. */
    private static function entryLine(array $entry): string
    {
        $line = '- ' . self::link((string) ($entry['title'] ?? ''), (string) ($entry['url'] ?? ''));
        $note = self::plain((string) ($entry['note'] ?? ''));
        return $note !== '' ? $line . ': ' . $note : $line;
    }

    /** "- [Title](url): when, venue (abgesagt)" */
    private static function eventLine(array $event): string
    {
        $detail = implode(', ', array_filter([
            self::plain((string) ($event['when'] ?? '')),
            self::plain((string) ($event['venue'] ?? '')),
        ], fn (string $part): bool => $part !== ''));
        if (!empty($event['cancelled'])) {
            $cancelled = '(' . I18n::t('llms.cancelled') . ')';
            $detail = $detail === '' ? $cancelled : $detail . ' ' . $cancelled;
        }
        $line = '- ' . self::link((string) ($event['title'] ?? ''), (string) ($event['url'] ?? ''));
        return $detail !== '' ? $line . ': ' . $detail : $line;
    }

    /**
     * The summary comes raw from the site description (a Panel textarea), and
     * the file reaches agents that render Markdown. Markdown stays, as the
     * site wrote it, but no HTML and no link that is not on the allowlist
     * LINK_TARGETS: a link keeps its target only when the target, after entity
     * decoding and with whitespace and control characters removed, starts with
     * one of them in lower case; otherwise only its label is left
     * (`javascript&#58;`, `JaVaScRiPt:`, `java\tscript:`, `data:`, `file:` …).
     * Everything that is not such a kept link goes through the escaping of
     * plain(), so a reference definition, an autolink or a link the pattern
     * cannot read never becomes a link. When PCRE gives up on the pattern the
     * whole text is escaped: no link, but the summary stays.
     */
    private static function summary(string $text): string
    {
        $text = self::decoded($text);
        $kept = [];
        $linked = preg_replace_callback(self::LINK, function (array $m) use (&$kept): string {
            $target = ($m['angle'] ?? '') !== '' ? $m['angle'] : ($m['bare'] ?? '');
            if (!self::allowedTarget($target)) {
                return $m['label'];   // escaped with the rest of the text below
            }
            $kept[] = '[' . self::escape($m['label']) . '](' . strtr($target, self::URL_ESCAPES) . ')';
            return "\0" . (count($kept) - 1) . "\0";
        }, $text);
        if ($linked === null) {
            // PCRE gave up (backtrack, depth or JIT stack limit, e.g. 50,000 nested
            // parentheses in a target): the text stays, without any link.
            return self::escape($text);
        }
        $text = self::escape($linked);
        // Control characters were removed by decoded(), so NUL marks only the kept links.
        return (string) preg_replace_callback('/\0(\d+)\0/', fn (array $m): string => $kept[(int) $m[1]] ?? '', $text);
    }

    /**
     * Text from the Panel as one Markdown-safe line (titles, notes, names,
     * labels, the address): see decoded() and escape(). Lines from the site's
     * own hooks (`sections`, `intro`, `contact.extra`) go through Text::line()
     * only: one line, trimmed, nothing escaped.
     */
    private static function plain(string $text): string
    {
        return self::escape(self::decoded($text));
    }

    /**
     * Entities decoded, control characters removed and tags stripped in turns
     * until nothing changes, so "&lt;img …&gt;", "&amp;lt;…", "<<img>img …>" or
     * "<scr<script>ipt>" cannot come back as markup, and an unclosed
     * "<img src=x onerror=…" is gone; invalid UTF-8 replaced, whitespace collapsed.
     *
     * Only tag-shaped text is stripped (TAG): a "<" that cannot open HTML stays
     * text ("Kinder <6 Jahre", "a <- b") and escape() turns it into "\<".
     * strip_tags() would drop everything after it. The control characters go
     * before the tag strip: "<\x01img …>" is no tag for TAG, and without the
     * control character it would be one.
     */
    private static function decoded(string $text): string
    {
        $text = Text::utf8($text);
        for ($round = 0; $round < 10; $round++) {
            $next = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $next = (string) preg_replace(self::CONTROL, '', $next);
            $next = (string) preg_replace(self::BRACKETED_EMAIL, '$1', $next);
            $next = (string) preg_replace(self::TAG, '', $next);
            if ($next === $text) {
                break;
            }
            $text = $next;
        }
        // Whatever the rounds left (an unclosed tag, more nesting than rounds):
        // no "<" may still open one.
        $text = (string) preg_replace(self::OPENER, '', $text);
        // Whitespace first (a vertical tab or NEL separates words), then the other control characters.
        return trim((string) preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', '', Text::line($text)));
    }

    /**
     * What the tag strip leaves of "<" and ">" ("1 < 2", "<6") is backslash-escaped,
     * a backslash too ("\\<" from the text would otherwise be an escaped
     * backslash and a raw "<"), "[" and "]" become "(" and ")": no tag,
     * autolink, link or reference can form.
     */
    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '<' => '\\<', '>' => '\\>', '[' => '(', ']' => ')']);
    }

    /** Is a summary link target on the allowlist (LINK_TARGETS)? Compared lower-cased, without whitespace and control characters. */
    private static function allowedTarget(string $target): bool
    {
        $target = strtolower((string) preg_replace('/[\s\x{00}-\x{1F}\x{7F}-\x{9F}]+/u', '', $target));
        foreach (self::LINK_TARGETS as $prefix) {
            if (str_starts_with($target, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Markdown link; "[" and "]" in the title become "(" and ")" so the link text
     * stays intact. Spaces and parentheses in the URL (from a hook) are
     * percent-encoded: a ")" would end the link early. An empty title — a page,
     * an event or a hook entry — takes the URL as its label: "- [](…)" names
     * nothing (llmstxt.org: a list of "[name](url)").
     */
    private static function link(string $title, string $url): string
    {
        $label = self::plain($title);
        $url = strtr(Text::line($url), self::URL_ESCAPES);
        if ($label === '') {
            $label = self::plain($url);
        }
        return $url !== '' ? '[' . $label . '](' . $url . ')' : $label;
    }

    /** "## Heading" followed by a blank line and the body; the heading alone when the body is ''. */
    private static function block(string $heading, string $body): string
    {
        $text = '## ' . $heading;
        return $body === '' ? $text : $text . "\n\n" . $body;
    }

    /** "8:00–12:00, 14:00–18:00" from [[from, to], …] minute pairs. */
    private static function ranges(array $blocks): string
    {
        $ranges = [];
        foreach ($blocks as $block) {
            $ranges[] = Text::range((int) $block[0], (int) $block[1]);
        }
        return implode(', ', $ranges);
    }
}
