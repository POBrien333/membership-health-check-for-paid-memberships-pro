# Changelog

## [0.4.0] — 2026-09-01

Added a tenth check: **Members with access and no subscription behind it**.

`unbilled_access` joins the subscriptions table, so it needs a subscription to
have existed and ended. That makes it blind to everyone who never had one — and
on the site this was written for, that is most of the gap between the member
count and the subscriber count: 35 of 128 active memberships had no subscription
row at all.

They are not all the same thing, which is why the check reports evidence rather
than a verdict — and the evidence that matters turned out to be **the discount
code**. On the origin site 33 of those 35 memberships carried one, so it gets a
column of its own:

| Code | Memberships |
|---|---|
| a 100%-off internal testing code | 27 |
| a gift-card code | 1 |
| a code covering a member who pays another way | 1 |
| one since deleted | 4 |

That single column explains almost the whole set at a glance. A gift code or a
one-off purchase buys a fixed period outright with no recurring billing, which is
correct and expected; the membership carries an end date to match. A comp has no
order behind it at all. Each row also shows the most recent order and the end
date, so the one genuinely worth a look stands out: no code, no order, and no end
date is open-ended free access nobody chose.

The join is a `LEFT JOIN` deliberately. A discount code can be deleted while the
memberships it created keep referencing it — four rows on the origin site do —
and an inner join would silently drop exactly the cases with the least
explanation. Those show as `#2 (deleted)`.

Filed as `info`, because a comp is a decision rather than a fault.

Checks can now contribute their own columns to the findings table. The WP-CLI
output folds them into the finding text instead, so `--format=csv` stays a single
rectangular table across all ten checks.

**Packaging fix.** The 0.3.1 and 0.4.0 archives built before this point installed
one directory too deep and unreadable, as a separate plugin rather than an upgrade.
Windows PowerShell's `Compress-Archive` writes entry names with backslashes, which
the ZIP specification forbids; PHP's unzip then sees a handful of files whose names
contain backslashes rather than a directory tree, finds no plugin folder inside,
and invents one named after the zip. `bin/zip.ps1` now writes entry names
explicitly with forward slashes and refuses to finish if the result has a
backslash anywhere or more than one top-level entry.

The archive is also unversioned now — `membership-health-check-for-paid-memberships-pro.zip`.
WordPress names the destination folder after the zip file whenever it cannot find
a plugin folder inside, so a version in the filename becomes a version in the
installed folder name, and every release installs as a new plugin instead of
upgrading. That is exactly how `…-pro-0.3.1/` and `…-pro-0.4.0/` ended up side by
side. The version lives in the plugin header and this file; it does not belong in
a path.

Delete any earlier install before using this build — the plugin stores no options
and no tables, so nothing is lost.

## [0.3.1] — 2026-09-01

Everything WordPress's own Plugin Check flagged.

- **Text domain now matches the slug**: `membership-health-check-for-paid-memberships-pro`.
  Sixty-seven strings updated. WordPress.org derives the domain from the slug and
  will not serve translations for anything else, so the old short domain would
  have meant no translations at all.
- **Queries interpolate `$wpdb->prefix` directly** instead of a local `$p`.
  Plugin Check cannot trace a prefix through a helper method and reported ten
  unescaped-parameter warnings for it. Same SQL, one fewer indirection, and the
  scanner can now see it is safe. The `prefix()` helper is gone.
- **Test-account matching is one static query again.** The `OR` list assembled
  from a filterable pattern array was the last thing Plugin Check could not
  verify, and fairly: it could not see that the assembled string held only
  placeholders. The whole alternation now travels as a single bound `REGEXP`
  parameter, so no variable reaches the SQL text at all and the suppression for
  it is gone.

  Anchoring came with it, and fixed a real bug: `test@` was matched as a
  substring, so **`latest@example.org` and `greatest@…` were flagged as test
  accounts**. It is now anchored to the front of the address. Filter values are
  still matched literally anywhere in the address, with punctuation escaped, so
  `+test (old)` means those characters rather than a pattern.
- **Removed the `Requires Plugins` header** added in 0.3.0. Paid Memberships Pro
  was permanently withdrawn from the WordPress.org directory on 2024-10-17 at the
  author's request, so the dependency can never resolve there. The runtime check
  already handles a missing PMPro gracefully, which is the better failure anyway.
- Author is now Bluerivergrowth.

Plugin Check also flagged `phpcs.xml.dist` and `BACKGROUND.md`. Neither ships:
both were already excluded by `.distignore`, and the report came from scanning the
development folder rather than a build. There is now a `composer build` that
produces a clean zip to check instead.

## [0.3.0] — 2026-09-01

**Breaking:** the global prefix grew from `MHC_` to `MHCHECK_`, and the filter
`mhc_test_email_patterns` is now `mhcheck_test_email_patterns`. WordPress coding
standards reject prefixes under four characters as too collision-prone, and they
are right. Rename any `add_filter()` call you have.

- The report now has **two tabs**: Members, and Webhooks. Only the open tab runs
  its queries, so each view costs about what a single check costs.
- **Test-account matching generalised.** Addresses ending in a domain reserved by
  RFC 2606 or RFC 6761 are always flagged — they cannot be delivered to, so no
  local knowledge is needed to judge them — and the match is anchored to the end
  of the address, so `testkitchen.com` is no longer caught. Site-specific
  conventions moved out of the defaults and into the filter, where they belong.
- Added the `Requires Plugins: paid-memberships-pro` header, so WordPress 6.5+
  blocks activation without PMPro instead of leaving it to a runtime check.
- Added PHPCS with WordPress standards and a PHP 7.4 compatibility gate
  (`composer lint`). Twenty-two findings fixed; the tree is clean with no
  baseline. The two remaining suppressions are documented where they sit: one for
  a dynamically assembled `IN`-style placeholder list, one for deliberately
  invoking PMPro's own `pmpro_stripe_webhook_events` filter.

## [0.2.0] — 2026-08-31

Added a ninth check: **Stripe webhook delivery**.

PMPro already shows a Webhook History under Settings → Payment → Stripe, and this
mirrors it so the whole picture stays on one screen. It then adds the two things
that table structurally cannot show, because PMPro keeps only the most recent
timestamp per event type and overwrites it on every delivery:

- **Silence**, measured against the site's own billing rhythm — the widest gap
  between order days in the past year, doubled — rather than a fixed threshold.
- **Delivery loss**, measured from the damage: the share of subscription endings
  that left a membership open with nothing billing.

The distinction earned its place the same way the others did. On the site this was
written for, `customer.subscription.deleted` showed as received five weeks *after*
the most recent leak, so the existing panel read green throughout the period three
subscriptions were leaking.

No Stripe API call: the check reads PMPro's stored timestamps and the site's own
tables. Also documented the `--format=json` option, which the CLI already had.

## [0.1.0] — 2026-08-31

First release. Eight read-only checks, each written after finding the
corresponding problem on a live site.
