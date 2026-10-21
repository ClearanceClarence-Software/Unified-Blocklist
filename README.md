# Unified Blocklist

One blocklist for WordPress comments and forms. Syncs community spam lists, keeps your own terms and exceptions, and logs every match so false positives are easy to find and fix.

**Requires:** WordPress 6.4+, PHP 7.4+ · **Tested with:** WordPress 7.1, Contact Form 7 6.2, Formidable Forms 7.0 · **License:** GPL-2.0-or-later

## Why

WordPress's built-in "Disallowed Comment Keys" only covers comments and has to be maintained by hand. If you paste a 66,000-term community list into it:

- about 0.9 MB is added to autoloaded options, so every page load carries it,
- every comment takes about 0.9 s to check, because core runs one regex per term per field,
- matches go straight to Trash, and nothing records why.

With the same list, Unified Blocklist adds nothing to autoload, checks a comment in about 6 ms, and logs every match.

## What it covers

| Where | What happens on a match |
| --- | --- |
| Comments | Spam (default), held for moderation, or Trash |
| Contact Form 7 | CF7's own spam path: spam message shown, no email sent |
| Gravity Forms | Entry saved under Entries > Spam with a note |
| WPForms | Rejected with your message, nothing saved |
| Formidable Forms | Rejected with your message, nothing saved |

## Features

- **Sources:** any number of plain-text lists, one term per line. Each keeps its last good copy, and conditional requests (ETag / Last-Modified) avoid re-downloading unchanged lists.
- **Safe syncing:** HTTP errors, HTML error pages, empty lists and lists that suddenly shrink by more than half are rejected. Requests go through `wp_safe_remote_get()`, so local and private addresses are refused.
- **Your terms and exceptions**, plus the option to include the core Discussion list so it applies to forms too.
- **Matching:** substring (compatible with community lists) or whole-word. Ignores case, HTML entities and zero-width characters.
- **Log** with field, term and excerpt. An **Allow** button on every entry adds the term to exceptions.
- **Monitor only** mode for a dry run on a live site.
- **Test** tab and `wp unified-blocklist test` to check any text.
- **Dashboard widget:** matches for the last 7 and 30 days, a per-day chart, the most matched terms, and any issue that needs attention (empty list, failing source, Monitor only).
- **Cleanup** for sites that ran Dynamic Blacklist Updater.

## For agencies

```php
// wp-config.php: manage sources centrally. The field becomes read-only in the admin.
define( 'UNIFIED_BLOCKLIST_SOURCES', array(
	'https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt',
	'https://example.com/agency-list.txt',
) );
```

```bash
wp unified-blocklist sync [--accept-smaller]
wp unified-blocklist status
wp unified-blocklist test "text to check"
wp unified-blocklist rebuild
```

### Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `unified_blocklist_settings` | filter | Override any setting from code |
| `unified_blocklist_terms` | filter | Final normalized term list |
| `unified_blocklist_fields` | filter | Fields checked for one submission (`$fields, $context`) |
| `unified_blocklist_enforce` | filter | Overrule a match (`$enforce, $hit, $context, $fields`) |
| `unified_blocklist_matched` | action | Fires on every match (`$hit, $context, $enforce`) |

## Storage

| Data | Where | Autoloaded |
| --- | --- | --- |
| Settings | `unified_blocklist_settings` (about 0.5 KB) | yes |
| Per-source copies | `unified_blocklist_src_*` | no |
| Compiled list | `unified_blocklist_compiled` | no (object-cached) |
| Log | `{prefix}unified_blocklist_log` table | — |

Uninstalling removes all of it. IP addresses are never logged, and excerpts can be turned off.

## A note on community lists

The default source is [splorp/wordpress-comment-blacklist](https://github.com/splorp/wordpress-comment-blacklist). It is English-centric and matches substrings, so for example it contains the Norwegian word *billig* ("cheap") and common praise phrases such as "the great article". On non-English sites, start in **Monitor only** mode and use the log to build your exceptions.

## Development

The admin screen is a React app built with `@wordpress/scripts`, the same setup and design system as [Restbench](https://github.com/ClearanceClarence/restbench). The PHP engine in `src/` works without it; the app only talks to the REST API under `/wp-json/unified-blocklist/v1`.

```bash
npm install
npm start          # watch build while developing
npm run typecheck
npm run lint:js
npm run zip        # build + dist/unified-blocklist-<version>.zip
```

| Folder | What's in it |
| --- | --- |
| `src/` | PHP: engine, integrations, REST API, admin mount |
| `app/` | React admin (TypeScript + SCSS) |
| `build/` | Compiled app (generated, not committed) |
| `licenses/` | Font licenses (Atkinson Hyperlegible, OFL) |

The admin theme (light or dark) is a per-user preference, like in Restbench.
