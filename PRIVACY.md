# Annai: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## What the visitor's browser does

Nothing towards any third party. The plugin sets no cookies, uses no
`localStorage`, `sessionStorage` or IndexedDB, and loads no script, font, image
or embed from another server. The head of a page contains the title, the
description, the canonical link, the Open Graph and Twitter tags and the
JSON-LD that the server computed for that request, as plain HTML.

The plugin records nothing about the visitor. It does not read the visitor's
IP address, user agent, language header or device clock. The only request
that depends on the visitor is the page itself, and the output does not
change with it (apart from the page language, which is the URL's).

## What the server computes

For each request the server works out, from the site's own content:

- the `<head>` of the page: title, description, canonical URL, robots meta,
  hreflang links, Open Graph and Twitter tags, and the schema.org JSON-LD;
- `robots.txt`, `sitemap.xml` (and its chunks) and `llms.txt`;
- the opening hours and closures, when Noren is installed, and the upcoming
  events, when Koyomi is installed.

Nothing is cached between requests inside the plugin. "Now" is the server's
clock in the configured time zone (`annai.timezone`, by default Europe/Berlin).

## What is stored, and where

The plugin stores nothing itself: no database, no file, no key. The input is the
content of the site (names, addresses, phone numbers, email addresses, opening
hours, descriptions) and the configuration in the site's config file. This is
business information that the operator publishes anyway.

Errors in the configuration (for example an unknown time zone or a malformed
option) are written to the server's error log as a short message. The message
contains no visitor data.

## What is public

`robots.txt`, `sitemap.xml` and `llms.txt` are **public documents**. Anyone and
any crawler can read them, and AI agents read `llms.txt` on purpose. They
contain:

- the URLs of the pages that are not excluded, and the URLs in extra sitemap
  entries;
- the business's name, address, telephone number, email address, opening hours
  and closures, and the URL of the main action;
- the `Disallow:` paths of `robots.txt`. A path listed there is public as well,
  so it must not reveal anything that should stay secret.

The JSON-LD contains the same kind of public business data. The plugin does not
render any personal data of visitors.

## What the operator should check

- Enter only information that the business is willing to publish. Names of
  employees on holiday, private phone numbers and private addresses do not
  belong into the fields the plugin reads, even on a location page.
- Do not write secret paths into `annai.disallow`, `annai.robots.extra` or
  `annai.sitemap.extra`. They are public.
- The web server's access logs are outside the plugin. They are covered by the
  site's general privacy policy.
- Training crawlers can be blocked with `annai.robots.ai`. Blocking does not
  remove content that has already been collected, and it binds only those
  crawlers that respect robots.txt.
- A preview lock keeps a site out of search results while it is protected
  (Annai then answers `noindex` on every page). The lock itself sits in front
  of Kirby, so the routes are behind it, and robots.txt, sitemap.xml and llms.txt
  show the normal content to the requests that get through it. A route the site
  exempts from the lock is readable by the crawler it is exempted for, so the
  page list is visible to it during the preview. `annai.noindex => true` closes
  the files (`Disallow: /`) instead. Annai does not add or remove that protection.
