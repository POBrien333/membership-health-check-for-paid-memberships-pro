![Ten read-only checks across billing, subscriptions, webhooks, roles and discount codes, shown as a
report where each check is either clean or carrying a count](.github/banner.png)

# Membership Health Check for Paid Memberships Pro

Finds members who still have access but stopped paying — plus the quieter data drift that
accumulates in any long-running membership site.

**Read-only.** It reports; it never changes anything. Every finding needs a human decision, because
a €0 order can be a deliberate comp *or* a payment you took by bank transfer, and only you know
which.

> Not affiliated with or endorsed by Stranger Studios, the makers of Paid Memberships Pro.

## Why it exists

On the site this was written for, an afternoon of manual SQL turned up:

- a member on a 6-month plan whose **last payment was September 2021** — four years of free access
- a subscription PMPro still listed as `active` whose next payment was due **983 days ago**, quietly
  unbilled since 2023
- three former members still carrying their old membership role
- **69 active members missing the site's default role**, invisible to every role-based query
- a 100%-off internal test code at 80 of its 100 uses, still valid

None of it was visible in the admin. Every one of those became a check.

## The checks

| Check | Severity | What it means |
|---|---|---|
| Members with access but nothing billing | high | Subscription ended, membership never closed, no end date — indefinite free access |
| Members with access and no subscription behind it | info | Never had one at all — with the discount code that usually explains why |
| Payments due but not received | medium | Scheduled, nothing back from the gateway — a card that declined, expired or was stopped |
| Subscriptions the gateway stopped charging | high | Still marked `active`, but the next payment date passed and no charge followed |
| Stripe webhook delivery | high | When each event type last arrived, whether billing has gone quiet, and what share of endings were lost |
| Level roles left on former members | medium | PMPro Roles missed the cleanup, usually via a bulk cancellation |
| Members without the default role | medium | PMPro Roles replaces it unless the level grants it too — silently breaks role queries |
| Memberships for deleted users | info | Orphaned rows that inflate every member count |
| Discount codes near their limit or expiry | info | A forgotten 100% code, or a test code about to break your checkout tests |
| Memberships ending in 30 days | info | Not a fault — the last window where a conversation still changes the outcome |
| Test accounts holding memberships | info | Test signups distorting your numbers |

The `high` checks are the ones that cost money.

## The distinction that matters

A cancelled subscription **with** a future end date is a member winding down correctly — they paid
through to a date and lapse on their own. A cancelled subscription **without** one is a leak.

That difference is the whole basis of the first check, and it's why "cancelled subscriptions" alone
is a useless signal.

## What the webhook check adds

PMPro already shows a Webhook History under **Settings → Payment → Stripe**, and the health check
mirrors it so the whole picture stays on one screen. But that table cannot answer the question you
actually have.

PMPro stores only the *most recent* timestamp per event type and overwrites it on every delivery.
That tells you the endpoint is alive. It cannot tell you whether every event arrived — and on the
site this was written for, three subscriptions leaked over twelve months while every event type
still read as recently received. `customer.subscription.deleted`, the event whose loss causes the
leak, showed as received five weeks *after* the most recent leak.

So the check adds two things PMPro's table structurally cannot give you:

- **Silence**, measured against the site's own billing rhythm rather than a fixed number of days.
  It takes the widest gap between order days over the past year and allows twice that, so a
  quiet site and a busy one both get a threshold that means something.
- **Delivery loss**, measured from the damage instead of from the gateway: of the subscriptions
  that ended in the past year, how many left a membership open with nothing billing. That shape
  only occurs when the event announcing the end never arrived, and it is the only evidence that
  survives once PMPro has overwritten its timestamp.

Neither needs a Stripe API call. The whole check is local option and table reads.

## Usage

**Memberships → Health Check**, which has two tabs:

- **Members** — the ten checks about people.
- **Webhooks** — the gateway link, including PMPro's per-event last-received times.

Only the open tab runs its queries, so each view costs about what one check costs.

Or over SSH, where every check runs at once:

```bash
wp membership-health                      # readable summary
wp membership-health --format=csv         # every finding, flat
wp membership-health --format=json        # same, as JSON
wp membership-health --format=count       # bare number, for monitoring
wp membership-health --check=webhook_health
```

`--format=count` exits with a plain number, so it drops into a cron one-liner that emails you only
when it's non-zero.

## Cost

Nothing runs in the background. No cron, no front-end code, no options written. The checks execute
only when you open the page or run the command, so a busy site pays nothing for having this
installed.

On a site with ~900 users and ~2,700 orders the full run takes well under a second.

## Requirements

- WordPress 6.0+, PHP 7.4+
- Paid Memberships Pro
- PMPro Roles add-on (optional — the two role checks skip themselves without it)

## Customising

Test-account detection works in two layers.

Two rules are built in and anchored, so they cannot catch a real address:

- Addresses **ending** in a domain reserved by RFC 2606 or RFC 6761 — `@example.com`, `.invalid`,
  `.localhost`, `.test`. Those can never be delivered to on any network. Anchoring means a real
  domain that merely contains the word (`testkitchen.com`, `example-design.com`) is left alone.
- A mailbox **starting** `test@`. Anchored to the front, so `latest@` and `greatest@` are not
  swept up with it.

Everything else is a convention, and conventions are local. The defaults are `+test` and `+demo`;
add your own. Values are matched literally wherever they appear in the address — punctuation is
escaped for you, so `+test (old)` means those characters and not a pattern:

```php
add_filter( 'mhcheck_test_email_patterns', function ( $patterns ) {
    $patterns[] = '+staging';
    $patterns[] = '+internal';
    return $patterns;
} );
```

Deliberately short by default: a wrong guess here quietly accuses a paying member of being fake.

### When a payment counts as late

A payment is reported as pending once it is two hours overdue. That default is measured rather than
guessed — on the site this was written for, seven consecutive renewals each produced their order
within 57 seconds of falling due, so a payment still missing hours later is not in flight.

Sites that bill by invoice, or whose gateway posts in batches, will want longer:

```php
add_filter( 'mhcheck_pending_payment_grace_hours', function () {
    return 48;
} );
```

Past seven days the payment stops being pending and becomes a failed subscription, which is a
different check and a `high` one.

## Development

```bash
composer install
composer lint
```

PHPCS runs the WordPress standard plus a PHP 7.4 compatibility gate matching the plugin header.
The tree is clean — no errors, no warnings, and no baseline.

### Building a release

```bash
composer build
```

Stages a clean copy into `dist/`, honouring `.distignore`, and zips it if PHP has the zip
extension. Point WordPress's Plugin Check at `dist/<slug>/` rather than the working folder,
or it will report the development files as violations.

On Windows, where PHP often lacks the zip extension, follow it with:

```bash
composer zip
```

Do **not** zip the folder with `Compress-Archive`. Windows PowerShell writes ZIP entry names
with backslashes, which the specification forbids; WordPress then fails to find a plugin folder
inside the archive, wraps it in a directory named after the zip file, and installs it as a
separate plugin one level too deep. `bin/zip.ps1` writes the entry names itself and refuses to
finish if any backslash or more than one top-level entry survives.

The archive is deliberately named `membership-health-check-for-paid-memberships-pro.zip`, with
no version. WordPress identifies a plugin by its folder, and names that folder after the zip
whenever it cannot find one inside — so a version in the filename becomes a version in the
installed folder, and every release then installs as a new plugin instead of upgrading. The
version belongs in the plugin header and the changelog, not in a path.

## Changelog

Summarised here; the reasoning behind each change is in [CHANGELOG.md](CHANGELOG.md).

- **0.5.0** — Eleventh check: payments due but not received. PMPro calls these "pending", but there
  is no pending status in the database — it's the scheduled payment, still scheduled, because
  nothing came back from the gateway. Usually a declined or expired card. Reported from two hours
  late until the seven-day mark, where the failed-subscription check takes over.
- **0.4.0** — Tenth check: members holding access with no subscription behind them, shown with the
  discount code that usually explains it. Checks can now contribute their own findings columns.
- **0.3.1** — Text domain matched to the plugin slug, so translations load. Queries rewritten so
  WordPress's Plugin Check can verify them, and a real false positive fixed: `test@` had been
  matching as a substring, flagging addresses like `latest@example.org`.
- **0.3.0** — Split into two tabs, Members and Webhooks, with only the open tab querying.
  Test-account matching generalised to RFC-reserved domains. PHPCS added and the tree cleaned.
  **Breaking:** prefix is now `MHCHECK_`, and the filter is `mhcheck_test_email_patterns`.
- **0.2.0** — Ninth check: Stripe webhook delivery, measured against the site's own billing rhythm
  and its own delivery loss rather than trusted.
- **0.1.0** — First release. Eight read-only checks.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
