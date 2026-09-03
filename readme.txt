=== Membership Health Check for Paid Memberships Pro ===
Contributors: bluerivergrowth
Tags: paid memberships pro, pmpro, membership, subscriptions, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds members who still have access but stopped paying, plus the quiet data drift that builds up in any long-running Paid Memberships Pro site.

== Description ==

Subscriptions end at the gateway. Sometimes the webhook telling your site never arrives — and the membership stays open. The member keeps full access, nothing looks wrong in the admin, and nobody notices for years.

This plugin finds those, and ten other things that go quietly wrong.

**It is read-only.** It reports what looks wrong so you can decide what to do. It never cancels, deletes or edits anything, because every finding needs judgement: a zero-value order can be a deliberate comp or a payment taken by bank transfer, and only you know which.

**The checks**

* Members with access but nothing billing — subscription ended, membership never closed, no end date
* Members with access and no subscription behind it — never had one at all: comps, staff, and anyone paying you outside the gateway
* Payments not yet settled — orders raised and never completed, on members who still have access; PMPro advances the subscription anyway, so these appear nowhere else
* Subscriptions the gateway stopped charging — still marked active, next payment long overdue
* Stripe webhook delivery — when each event type last arrived, whether billing has gone quiet, and what share of subscription endings were lost
* Level roles left on former members
* Members missing the site's default role, which silently breaks any role-based query
* Memberships belonging to deleted users
* Discount codes near their use limit or expiry
* Memberships ending within 30 days
* Test accounts holding active memberships

**Two tabs**

Members covers the ten checks about people. Webhooks covers the link to the gateway, mirroring the per-event last-received times PMPro records and adding what that table cannot show. Only the open tab runs its queries.

**Costs nothing to have installed**

No cron, no front-end code, no background work. The checks run only when you open the page or run the WP-CLI command.

**WP-CLI**

`wp membership-health`, with `--format=csv`, `--format=json`, `--format=count` or `--check=<id>`.

This plugin is not affiliated with or endorsed by Stranger Studios, the makers of Paid Memberships Pro. "Paid Memberships Pro" is their trademark, used here only to describe compatibility.

== Installation ==

1. Upload to `/wp-content/plugins/` or install through Plugins → Add New.
2. Activate.
3. Go to Memberships → Health Check.

== Frequently Asked Questions ==

= Does it change anything? =

No. Every check is a read-only query. Nothing is cancelled, deleted or edited.

= Will it slow my site down? =

No. There is no front-end code and no scheduled task. The queries run only when you ask for a report.

= Do I need the PMPro Roles add-on? =

No. The two role-related checks simply find nothing without it.

= A member shows as "nothing billing" but they pay me by bank transfer =

Expected, and the report tells you so. A payment taken outside the gateway leaves no subscription to find, so it looks like a comp. "Members with access and no subscription behind it" lists exactly these people and shows the last payment recorded against each one: money on record means somebody is paying you another way, nothing on record means the access really is free. The plugin reports rather than acts because it cannot tell the two apart, and you can.

= Does the webhook check contact Stripe? =

No. It reads the timestamps PMPro already stores when each event arrives, plus your own orders and subscriptions. No API call, no key needed, nothing leaves your server.

= PMPro already shows a Webhook History. Why repeat it? =

Partly so you do not have to leave the report. Mostly because PMPro keeps only the latest timestamp per event type and overwrites it on every delivery, so it proves the endpoint is alive but never that every event arrived. The check adds the two measurements that survive that overwrite: whether billing has gone quiet relative to this site's own rhythm, and what share of subscription endings left a membership open.

== Screenshots ==

1. The Members tab. Members with access but nothing billing, and members with access and no subscription behind it — the latter showing the discount code that usually explains why.
2. Memberships belonging to deleted users, discount codes approaching their use limit, and memberships ending within the next 30 days.
3. Payments raised and never settled, subscriptions the gateway quietly stopped charging, and level roles left behind on former members.
4. The same report on a site with more findings, showing how the discount code column separates a deliberate comp from access nobody chose to give.

Names, email addresses and discount codes in these screenshots are placeholders. The counts, dates and amounts are real.

== Changelog ==

= 0.5.0 =
* New check: "Payments not yet settled". Orders raised and never completed, on members who still have access — usually a card that declined, expired or was stopped at the bank. Reported as information, not a fault: most resolve on their own, and a gateway that gives up normally closes the membership by itself.
* These appear nowhere else. PMPro advances the subscription to the next cycle on the strength of an unsettled order, so the membership reads as paid and nothing anywhere looks overdue. Every other check keys on that date; this one reads order status directly.
* An order counts as unsettled an hour after it was raised, and only for members who still hold an active membership. The grace is filterable through `mhcheck_pending_payment_grace_hours`.
* The report now warns when it is running outside a production environment. Every date-based check compares stored dates against the current clock, so on a staging site restored from a backup, payments taken since the snapshot look missing and cancellations look ignored. The webhook check also names a restored copy as one explanation for billing having gone quiet.

= 0.4.0 =
* New check: "Members with access and no subscription behind it". The existing check needed a subscription to have existed and ended, so it could not see anyone who never had one. That is most of the gap between your member count and your subscriber count.
* Each row shows the discount code used, the most recent order and the end date. The code usually explains the whole thing: a gift code or one-off purchase buys a fixed period outright with no recurring billing, which is correct and expected. The row worth a second look is the one with no code, no order and no end date.
* Codes that have since been deleted still show, as `#id (deleted)`, rather than dropping out of the report.

= 0.3.1 =
* Text domain now matches the plugin slug, so WordPress.org translations will actually load.
* Database queries interpolate `$wpdb->prefix` directly, which Plugin Check can verify as safe.
* Test-account matching is a single static query with one bound parameter instead of an assembled OR list. This also fixed a real false positive: `test@` was matched as a substring, so addresses like `latest@example.org` were flagged as test accounts. It is now anchored to the front of the address.
* Removed the `Requires Plugins` header: Paid Memberships Pro was withdrawn from the WordPress.org directory in October 2024, so the dependency can never resolve there. The plugin still detects a missing PMPro at runtime and says so.

= 0.3.0 =
* The report now has two tabs, Members and Webhooks. Only the open tab runs its queries.
* Breaking: the global prefix is now `MHCHECK_`, and the filter `mhc_test_email_patterns` is now `mhcheck_test_email_patterns`. Rename any add_filter() call you have.
* Test-account matching generalised: addresses ending in a domain reserved by RFC 2606 or RFC 6761 are always flagged, and the match is anchored to the end of the address so a real domain containing "test" is no longer caught. Site-specific conventions belong in the filter.
* Added the `Requires Plugins` header so WordPress 6.5+ blocks activation without Paid Memberships Pro.
* Added PHPCS with WordPress coding standards and a PHP 7.4 compatibility gate; the tree is clean.

= 0.2.0 =
* New check: Stripe webhook delivery. Mirrors PMPro's per-event last-received times, warns when billing goes quiet for longer than the site's own history allows, and measures what share of subscription endings were lost in delivery.
* Documented the existing `--format=json` option, which had been missing from the readme.

= 0.1.0 =
* First release. Eight read-only checks.
