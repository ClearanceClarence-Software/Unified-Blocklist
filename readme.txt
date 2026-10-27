=== Unified Blocklist ===
Contributors: cccode
Tags: spam, blocklist, comments, contact form 7, gravity forms
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One blocklist for comments and forms. Syncs community spam lists, keeps your own terms, and logs every match.

== Description ==

WordPress has a built-in list of disallowed words, but it only covers comments, it has to be maintained by hand, and it is checked with one regular expression per word. Paste a community list of 60,000+ terms into it and every page load carries nearly a megabyte of extra autoloaded options, every comment takes almost a second to check, and nobody can see what was blocked.

Unified Blocklist keeps one list and applies it everywhere:

* **Comments** – matching comments go to Spam (recoverable), Moderation or Trash.
* **Contact Form 7** – uses CF7's own spam handling, so no email is sent.
* **Gravity Forms** – entries are saved under Entries > Spam with a note.
* **WPForms** and **Formidable Forms** – the submission is rejected with your message.

The list is built from:

* **Remote sources** – any plain-text list, one term per line. The plugin ships with no source configured; one click adds the community-maintained [WordPress Comment Blacklist](https://github.com/splorp/wordpress-comment-blacklist). Each source keeps its last good copy, so a failed download, an HTML error page or a list that suddenly shrinks never empties your protection.
* **Your terms** – kept across syncs.
* **Your Discussion settings** – optionally, so the terms you already maintain cover forms too.
* **Exceptions** – terms you never want to block, one click away from any log entry.

= Built to avoid false positives =

* **Log** of every match with the field, the term and a short excerpt, kept for a period you choose.
* **Allow** button on every log entry and test result.
* **Monitor only** mode logs matches without blocking anything, so you can try it on a live site first.
* **Whole-word matching** as an option.
* **Test** tab: paste any message and see exactly which terms it matches and which list they come from.
* **Replay before you rely on it**: run the whole list, or a few new terms before you save them, over your recent approved comments and saved form entries (Gravity Forms, Formidable, Contact Form 7 through Flamingo) and see what it would have stopped.
* **Learns from your moderators**: when someone marks a blocked comment "Not spam", restores or approves it, or marks a Gravity Forms entry as not spam, the term that caught it is suggested as an exception. Nothing is allowed until you click Allow.
* **Quiet log during spam waves**: repeats of the same term in the same place within a minute share one log row with a count.
* **Dashboard widget** with the last 7 and 30 days, a per-day chart, the most matched terms, and anything that needs attention.

= Fast =

The lists live in the plugin's own database table, not in wp_options, and are only read when someone submits a comment or form. Matching uses plain string search on a single normalized copy of the submission, which checks a 66,000-term list in a few milliseconds instead of the nearly one second WordPress core needs.

= For agencies =

* Define sources in `wp-config.php` with `UNIFIED_BLOCKLIST_SOURCES` to manage them centrally.
* WP-CLI: `wp unified-blocklist sync`, `status`, `test "<text>"`, `rebuild`.
* Filters: `unified_blocklist_settings`, `unified_blocklist_terms`, `unified_blocklist_fields`, `unified_blocklist_enforce`; action `unified_blocklist_matched`.

= Stop WordPress checking comments twice =

If Settings › Discussion › Disallowed Comment Keys has lines in it, WordPress checks them itself on every comment, one regular expression per line. One click moves them into Unified Blocklist, where they are checked once and cover your forms too. Lines your downloaded lists already have are not copied, IP addresses stay where WordPress can check them, and the move can be undone.

= Moving from Dynamic Blacklist Updater =

If the site ran Dynamic Blacklist Updater, the Overview tab shows a cleanup card. It can deactivate the old plugin, import its custom terms, empty the very large copies it wrote into your Discussion settings, switch off "Comment must be manually approved" if the old plugin turned it on by mistake, and remove its leftover settings.

= External services =

The plugin downloads blocklists from the URLs an administrator adds under Settings › Unified Blocklist › Sources, on the schedule chosen there. Nothing is downloaded until a source is added, and the plugin ships with none. The only data sent is a request for that file with a generic `UnifiedBlocklist/<version>` user agent; nothing about your site, its users or its content is transmitted.

The list the plugin suggests is the WordPress Comment Blacklist maintained by Grant Hutchinson, served from GitHub: https://github.com/splorp/wordpress-comment-blacklist (list and license: https://github.com/splorp/wordpress-comment-blacklist/blob/master/LICENSE). Adding it is a choice you make on the Sources page.

= Privacy =

The log never stores IP addresses. Excerpts can be turned off. Logged-in users who can moderate comments are never checked and never logged.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings > Unified Blocklist**. Click **Add the community list**, or enter your own list URLs and terms.
3. Optional: switch to **Monitor only** for a week, review the log, then switch back to **Enforce**.

== Frequently Asked Questions ==

= Does this replace Akismet, Antispam Bee or a CAPTCHA? =

No. Keyword lists catch known spam phrases and domains. Use them alongside a behavioral filter or challenge such as Akismet, Antispam Bee, a honeypot or Cloudflare Turnstile.

= Does it change my Discussion settings? =

No. The plugin never writes to Disallowed Comment Keys or Comment Moderation. The only exception is the optional cleanup for Dynamic Blacklist Updater, which you run yourself.

= A legitimate message was blocked. What now? =

Open the **Log** tab, find the entry and click **Allow**. The term is added to your exceptions and stops matching immediately.

= How do I manage sources for many sites? =

Add this to `wp-config.php`:

`define( 'UNIFIED_BLOCKLIST_SOURCES', array( 'https://example.com/our-list.txt' ) );`

The Sources field then becomes read-only on that site.

== Screenshots ==

1. Overview: what is protected, matches per day, and the most matched terms.
2. Activity: every match with the field, the term and an excerpt. Allow a term with one click.
3. Test a message: see which terms a text matches as you type, and why it passes when it does.
4. Protection: Enforce or Monitor only, what happens to comments, and which form plugins are covered.

== Development ==

The files in build/ are compiled from TypeScript and SCSS but not minified, so you can read them as they are.

The source code and build setup are on GitHub: https://github.com/ClearanceClarence-Software/Unified-Blocklist

To build it yourself you need Node 20 or newer:

    npm ci
    npm run build

`npm run zip` makes the same plugin zip that's published here.

== Credits ==

Unified Blocklist bundles the Bricolage Grotesque font (Copyright 2022 The Bricolage Grotesque Project Authors) and the Atkinson Hyperlegible Next and Atkinson Hyperlegible Mono fonts (Copyright 2020-2024 The Atkinson Hyperlegible Next / Mono Project Authors), all licensed under the SIL Open Font License 1.1. The license text is in the licenses/ folder. The fonts are served from the plugin folder, not from Google.

The default list is the community-maintained WordPress Comment Blacklist by Grant Hutchinson (splorp): https://github.com/splorp/wordpress-comment-blacklist

== Changelog ==

= 1.1.0 =
* New: replay the list, or new terms before saving, over recent approved comments and form entries.
* New: suggestions to allow a term when a moderator rescues a message it stopped.
* New: move the Discussion settings list into the plugin in one click, with undo.
* Changed: repeated matches within a minute share one log row with a count.
* Development: end-to-end tests with real Contact Form 7, Flamingo, WPForms Lite and Formidable forms in wp-env.

= 1.0.0 =
* First release.
