# Background: why each check exists

This plugin was not designed in the abstract. Every check was written after finding the
corresponding problem on a live Paid Memberships Pro site — a Spanish swing-dance school running
PMPro alongside an LMS, ~900 users, ~130 active members, Stripe as the gateway, plus the
**PMPro Roles** add-on.

The audit happened on 2026-08-31. What follows is what was found, why, and what was done.

---

## How it started

The trigger was unrelated to billing. The question was *"which students are actually active?"* — and
answering it meant joining learning activity against PMPro membership status. Once membership data
was in view, it became obvious that the membership data itself was wrong.

## Finding 1 — members with access but nothing billing

**5 accounts, one running roughly four years.**

Two distinct failure modes, both caused by Stripe webhooks not reaching the site:

| Shape | PMPro shows | Reality | Count |
|---|---|---|---|
| Subscription cancelled, membership left open with **no end date** | subscription `cancelled` | not billing, access continues forever | 4 |
| **Zombie subscription** | subscription `active` | gateway stopped charging | 1 |

The second is the nastier one: nothing looks wrong in the admin. That account's last payment was
December 2023, its `next_payment_date` was 2024-06-15, and it sat unbilled for **983 days** — about
5 missed cycles at €86, roughly €430. The user was still logging in.

The oldest of the first group last paid in **September 2021**.

**The distinction that makes this detectable:** a cancelled subscription *with* a future end date is
a member winding down correctly — they paid through to a date and lapse on their own. A cancelled
subscription *without* one is a leak. "Cancelled subscriptions" alone is a useless signal; the end
date is what separates the two.

**Failure rate:** 180 subscriptions ended in the preceding 12 months, of which 3 became leaks —
about **1.7%**. Intermittent, not a systematically broken endpoint. That rate argues for periodic
monitoring rather than a webhook rebuild, but it is *not* zero: the most recent occurred six weeks
before the audit.

→ checks `unbilled_access`, `stalled_subscriptions`

## Finding 2 — 69 members invisible to every role-based query

PMPro Roles assigns a role per level (`pmpro_role_3`, `_4`, `_5`). Its level settings decide which
roles a level grants, and on this site **level 3 had "Subscriber" ticked while levels 4 and 5 did
not**.

It does not merely fail to add the default role — it strips it. In `after_all_level_changes()`:

```php
$remove_roles = array_values( array_diff( $old_roles, $new_roles ) );
```

With `old_roles = [subscriber]` and `new_roles = [pmpro_role_4]`, `subscriber` is removed.

Result: **71 of 128 active members had no `subscriber` role** — including *every* 6-month and annual
member. Anything querying users by role skipped them silently. The LMS's own re-engagement email did
exactly that, and would have reached 56 of 128 paying members while mailing **584 lapsed customers**,
who all *do* carry `subscriber` because cancelling restores it via the plugin's no-role fallback.

Also note: ticking the setting only takes effect on a member's **next level change**. Existing
members need a one-off backfill (`$user->add_role()`, which is additive and idempotent).

→ checks `missing_default_role`, `orphaned_roles`

## Finding 3 — orphaned level roles

3 former members still carried `pmpro_role_4/5` with no active membership. Two were cancelled within
two days of each other in July 2023, which suggests a bulk admin action that skipped the per-user
hook.

→ check `orphaned_roles`

## Finding 4 — a 100%-off code with 20 uses left

An internal test code, created in 2021, used to grant staff and testers access without a real
payment. Found at **80 of 100 uses**, unlimited per user, and still valid.

Legitimate and intentional — but two things follow. It will hit its ceiling and break checkout
testing, and 11 real people had been granted permanent free monthly access through it over the
years, which the owner had estimated at "max 5".

→ check `discount_codes_running_out`

## Finding 5 — the numbers were not what they appeared

Of 128 "active members":

- **19** were test accounts, all on plus-addressed variants of two staff addresses, never cleaned up
- **~12** were deliberate comps
- **1** was a PMPro row whose WordPress user had been deleted
- **2** were administrators

Genuinely paying: **~91**. Every retention and engagement statistic was being computed against a
denominator inflated by ~30%.

The PMPro **Subscriptions** screen showed 78 active against 128 active members — which turned out to
be correct, not a bug. Subscriptions records only recurring gateway billing; comps, one-off
purchases, admin grants and anything predating the table (added in PMPro 3.0) never appear. That
also explains cancellation emails firing with no corresponding Subscriptions entry: those emails
trigger on *membership* status change, a different table.

→ checks `test_accounts`, `ghost_memberships`, `expiring_soon`

---

## What was done

- 5 leaking memberships cancelled
- 9 stale comped memberships removed
- 3 orphaned roles cleared
- `subscriber` ticked on levels 4 and 5, then backfilled to 69 existing members
- Reconciliation afterwards: 122 active members = 2 admin + 16 test + 77 billing + 13 winding down
  + 14 comped, **0 unexplained**, and `active_sub` matching the Subscriptions screen exactly

## What this plugin is

Eight read-only checks, so the same audit takes seconds instead of an afternoon.

**Read-only on purpose.** Every fix above needed human judgement: one flagged account was a
collaborator who had simply not been cancelled yet; one €0 order was a real PayPal payment taken
outside the gateway; the 100% discount code was deliberate. A tool that auto-cancelled would have
done damage in all three cases.

**No standing cost.** No cron, no front-end code, no options written. It runs when you open the page
or invoke `wp membership-health`.

## What the audit did not settle

Two things stayed open, and both are the reason the plugin measures rather than assumes.

Webhook delivery was never verified at the gateway itself. A 1.7% loss rate is a symptom, not a
diagnosis, and nothing in the database can tell you whether the endpoint is configured correctly —
only whether events have been arriving. That is why the webhook check reports the last-received
times and the measured loss rate side by side, and claims nothing beyond them.

Comped and one-off memberships were never fully separated from real ones by hand, because the
database cannot distinguish a deliberate comp from a payment taken outside the gateway. That is why
`access_without_subscription` shows the discount code, the last order and the end date, and leaves
the conclusion to a human.
