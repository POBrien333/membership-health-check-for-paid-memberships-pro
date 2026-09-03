<?php
/**
 * The health checks.
 *
 * Every check here earned its place by finding something real on a live site:
 * five members with free access (one running four years), three orphaned roles,
 * sixty-nine members missing the default role, and a test discount code four
 * fifths of the way through its use limit.
 *
 * All checks are read-only. Deciding what to do about a finding needs human
 * judgement — a zero-value order can be a deliberate comp or a PayPal payment
 * taken outside the gateway, and only you can tell which.
 *
 * @package MembershipHealthCheck
 */

namespace MembershipHealthCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every check, and the shape they all return.
 */
final class Checks {

	public const SEVERITY_HIGH   = 'high';
	public const SEVERITY_MEDIUM = 'medium';
	public const SEVERITY_INFO   = 'info';

	/**
	 * Address endings reserved by RFC 2606 and RFC 6761 for documentation and
	 * testing. None can be delivered to, on any network, so an account using one
	 * is a test account regardless of what the site is or who runs it. This is
	 * the only part of the match that needs no local knowledge.
	 */
	private const RESERVED_SUFFIXES = array(
		'@example.com',
		'@example.net',
		'@example.org',
		'@example.edu',
		'.example.com',
		'.example.net',
		'.example.org',
		'.example',
		'.invalid',
		'.localhost',
		'.test',
	);

	/**
	 * Run every check.
	 *
	 * @return array<int,array> One result set per check.
	 */
	public static function run_all(): array {
		return array_merge( self::members(), self::gateway() );
	}

	/**
	 * Checks about people: who holds access they should not, and who is
	 * miscategorised in a way that hides them from your own queries.
	 *
	 * @return array<int,array> One result set per check.
	 */
	public static function members(): array {
		return array(
			self::unbilled_access(),
			self::access_without_subscription(),
			self::pending_payments(),
			self::stalled_subscriptions(),
			self::orphaned_roles(),
			self::members_missing_default_role(),
			self::ghost_memberships(),
			self::discount_codes_running_out(),
			self::expiring_soon(),
			self::test_accounts(),
		);
	}

	/**
	 * Checks about the link to the payment gateway rather than about any one
	 * member. Separated because the question is different: the checks above ask
	 * who is affected, this one asks whether the plumbing is sound.
	 *
	 * @return array<int,array> One result set per check.
	 */
	public static function gateway(): array {
		return array(
			self::webhook_health(),
		);
	}

	/**
	 * The shape every check returns.
	 *
	 * @param string $id       Stable identifier, used by --check=.
	 * @param string $label    Human-readable name of the check.
	 * @param string $severity One of the SEVERITY_ constants.
	 * @param string $explain  What the findings mean, shown when there are some.
	 * @param array  $rows     The findings. An empty array means the check passed.
	 * @param string $good     What passing means, shown when there are no findings.
	 * @param array  $table    Optional standing status panel, shown either way.
	 * @param array  $columns  Optional extra findings columns, as key => heading.
	 */
	private static function result( string $id, string $label, string $severity, string $explain, array $rows, string $good, array $table = array(), array $columns = array() ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'severity' => $severity,
			'explain'  => $explain,
			'rows'     => $rows,
			'count'    => count( $rows ),
			'good'     => $good,
			'table'    => $table,
			'columns'  => $columns,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Active membership, no active subscription, and no end date.
	 *
	 * The dangerous shape. A member who cancels normally keeps an end date and
	 * lapses on their own; one with no end date and nothing billing has
	 * indefinite free access. Members who never had a subscription at all are
	 * excluded — those are comps, which are usually deliberate.
	 */
	public static function unbilled_access(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		$rows = $wpdb->get_results(
			"SELECT mu.user_id, u.user_email, u.display_name, mu.membership_id AS level,
			        mu.startdate, MAX(s.enddate) AS sub_ended
			 FROM {$wpdb->prefix}pmpro_memberships_users mu
			 INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id
			 INNER JOIN {$wpdb->prefix}pmpro_subscriptions s ON s.user_id = mu.user_id
			 WHERE mu.status = 'active'
			   AND ( mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' )
			   AND NOT EXISTS (
			         SELECT 1 FROM {$wpdb->prefix}pmpro_subscriptions a
			         WHERE a.user_id = mu.user_id AND a.status = 'active'
			       )
			 GROUP BY mu.user_id, u.user_email, u.display_name, mu.membership_id, mu.startdate
			 ORDER BY sub_ended ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$r['detail'] = sprintf(
				/* translators: %s: date the subscription ended */
				__( 'subscription ended %s, membership still open with no end date', 'membership-health-check-for-paid-memberships-pro' ),
				substr( (string) $r['sub_ended'], 0, 10 )
			);
		}
		unset( $r );

		return self::result(
			'unbilled_access',
			__( 'Members with access but nothing billing', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_HIGH,
			__( 'Their subscription ended but the membership was never closed, so access continues indefinitely and free. Usually a gateway webhook that never arrived.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'Every active member either has a live subscription or an end date.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Active members who have never had a subscription at all.
	 *
	 * The other half of the question above. That check needs a subscription to
	 * have existed and ended, so it cannot see anyone who never had one — and
	 * that is where the rest of the gap between your member count and your
	 * subscriber count lives.
	 *
	 * Not a fault, which is why it reports rather than warns. A one-off purchase
	 * or a gift code buys a fixed period with no recurring billing, and that is
	 * exactly what it should look like. So is a comp. The discount code is
	 * usually the whole explanation — on the site this was written for, 33 of
	 * these 35 memberships carried one — so it gets a column of its own rather
	 * than a guess in the description.
	 */
	public static function access_without_subscription(): array {
		global $wpdb;

		// LEFT JOIN, not INNER: a code can be deleted while the memberships it
		// created carry on referencing it. Four rows on the origin site do.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		$rows = $wpdb->get_results(
			"SELECT mu.user_id, u.user_email, u.display_name, mu.membership_id AS level,
			        mu.enddate, mu.code_id, dc.code,
			        ( SELECT MAX( o.timestamp ) FROM {$wpdb->prefix}pmpro_membership_orders o
			           WHERE o.user_id = mu.user_id AND o.status = 'success' ) AS last_paid,
			        ( SELECT o2.total FROM {$wpdb->prefix}pmpro_membership_orders o2
			           WHERE o2.user_id = mu.user_id AND o2.status = 'success'
			           ORDER BY o2.timestamp DESC LIMIT 1 ) AS last_amount
			 FROM {$wpdb->prefix}pmpro_memberships_users mu
			 INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id
			 LEFT JOIN {$wpdb->prefix}pmpro_discount_codes dc ON dc.id = mu.code_id
			 WHERE mu.status = 'active'
			   AND NOT EXISTS (
			         SELECT 1 FROM {$wpdb->prefix}pmpro_subscriptions s
			         WHERE s.user_id = mu.user_id
			       )
			 ORDER BY mu.code_id ASC, u.user_email ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			if ( empty( $r['code'] ) && (int) $r['code_id'] > 0 ) {
				$r['code'] = sprintf(
					/* translators: %d: id of a discount code that no longer exists */
					__( '#%d (deleted)', 'membership-health-check-for-paid-memberships-pro' ),
					(int) $r['code_id']
				);
			}

			$paid = empty( $r['last_paid'] )
				? __( 'no order on record', 'membership-health-check-for-paid-memberships-pro' )
				: sprintf(
					/* translators: 1: amount of the most recent order, 2: date it was taken */
					__( '%1$s on %2$s', 'membership-health-check-for-paid-memberships-pro' ),
					self::money( $r['last_amount'] ),
					substr( (string) $r['last_paid'], 0, 10 )
				);

			$ends = ( empty( $r['enddate'] ) || '0000-00-00 00:00:00' === $r['enddate'] )
				? __( 'no end date', 'membership-health-check-for-paid-memberships-pro' )
				: sprintf(
					/* translators: %s: date the membership ends */
					__( 'access ends %s', 'membership-health-check-for-paid-memberships-pro' ),
					substr( (string) $r['enddate'], 0, 10 )
				);

			$r['detail'] = $paid . ', ' . $ends;
		}
		unset( $r );

		return self::result(
			'access_without_subscription',
			__( 'Members with access and no subscription behind it', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_INFO,
			__( 'These never had a subscription, so the check above cannot see them. Most are deliberate: a one-off purchase or a gift code buys a fixed period outright, and comps and staff accounts have no order behind them at all. The code and the end date normally explain which is which. The row worth a second look is the one with no code, no order and no end date — that is open-ended free access nobody chose.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'Every active member has, or has had, a subscription.', 'membership-health-check-for-paid-memberships-pro' ),
			array(),
			array( 'code' => __( 'Discount code', 'membership-health-check-for-paid-memberships-pro' ) )
		);
	}

	/**
	 * Format an amount the way the rest of the site does.
	 *
	 * @param mixed $amount Order total, as PMPro stores it.
	 */
	private static function money( $amount ): string {
		if ( ! function_exists( 'pmpro_formatPrice' ) ) {
			return number_format_i18n( (float) $amount, 2 );
		}

		// PMPro wraps the currency symbol in markup for some currency positions —
		// a euro placed after the amount comes back as "0.00<sup>&euro;</sup>".
		// Findings are escaped as plain text wherever they are rendered, so that
		// markup would print as literal tags. Reduce it to the characters it means.
		$formatted = (string) pmpro_formatPrice( (float) $amount );

		return trim( html_entity_decode( wp_strip_all_tags( $formatted ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A subscription still marked active whose next payment date has passed.
	 *
	 * The quieter failure: nothing looks wrong in the admin, but the gateway
	 * stopped charging. One of these ran unbilled for nearly three years.
	 */
	public static function stalled_subscriptions(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.user_id, u.user_email, u.display_name, s.membership_level_id AS level,
				        s.next_payment_date, DATEDIFF(NOW(), s.next_payment_date) AS days_overdue
				 FROM {$wpdb->prefix}pmpro_subscriptions s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 WHERE s.status = 'active'
				   AND s.next_payment_date IS NOT NULL
				   AND s.next_payment_date <> '0000-00-00 00:00:00'
				   AND s.next_payment_date < DATE_SUB( NOW(), INTERVAL %d DAY )
				 ORDER BY days_overdue DESC",
				7
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$r['detail'] = sprintf(
				/* translators: 1: number of days, 2: date payment was due */
				__( '%1$d days overdue — payment was due %2$s', 'membership-health-check-for-paid-memberships-pro' ),
				(int) $r['days_overdue'],
				substr( (string) $r['next_payment_date'], 0, 10 )
			);
		}
		unset( $r );

		return self::result(
			'stalled_subscriptions',
			__( 'Subscriptions the gateway stopped charging', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_HIGH,
			__( 'Marked active here, but the next payment date passed long ago and no charge followed. Nothing looks wrong in the admin, which is why these run for years.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'No active subscription is overdue.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}
	/**
	 * Orders that never settled, on members who still have access.
	 *
	 * A card payment that is initiated but not confirmed leaves an order sitting
	 * at `pending`. PMPro then advances the subscription's next payment date on
	 * the strength of that order, so the membership reads as paid for another
	 * cycle and nothing anywhere looks overdue. That is what makes these hard to
	 * see: every other check here keys on the next payment date, and that date has
	 * already moved.
	 *
	 * Which is why this reads order status directly rather than inferring from
	 * dates. Built the other way round first, it could not have found one of them.
	 *
	 * Reported as information, not a fault. Most resolve on their own: the gateway
	 * either takes the money or gives up, and giving up normally closes the
	 * membership without anyone intervening. The value is the view, not an alarm.
	 *
	 * `token` and `review` join `pending` because they are the same shape — a
	 * checkout that stalled part-way, or an order held for fraud review, is access
	 * granted against money that has not landed. `error` is excluded: a failed
	 * payment is a fact the gateway has already reported and acted on.
	 *
	 * The grace is an hour, enough to exclude payments that are genuinely still in
	 * flight without hiding this morning's. Bank debits legitimately sit for days,
	 * so sites taking those will want longer, hence the filter.
	 */
	public static function pending_payments(): array {
		global $wpdb;

		$env   = (string) get_option( 'pmpro_gateway_environment' );
		$env   = '' === $env ? 'live' : $env;
		$grace = max( 0, (int) apply_filters( 'mhcheck_pending_payment_grace_hours', 1 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.user_id, u.user_email, u.display_name,
				        mu.membership_id AS level,
				        o.id AS order_id, o.status AS order_status,
				        o.timestamp, o.total,
				        TIMESTAMPDIFF( HOUR, o.timestamp, NOW() ) AS hours_stuck,
				        s.next_payment_date
				   FROM {$wpdb->prefix}pmpro_membership_orders o
				   INNER JOIN {$wpdb->users} u ON u.ID = o.user_id
				   INNER JOIN {$wpdb->prefix}pmpro_memberships_users mu
				           ON mu.user_id = o.user_id AND mu.status = 'active'
				   LEFT JOIN {$wpdb->prefix}pmpro_subscriptions s
				          ON s.user_id = o.user_id
				         AND s.subscription_transaction_id = o.subscription_transaction_id
				  WHERE o.status IN ( 'pending', 'token', 'review' )
				    AND o.gateway_environment = %s
				    AND o.timestamp < DATE_SUB( NOW(), INTERVAL %d HOUR )
				    AND NOT EXISTS (
				          SELECT 1 FROM {$wpdb->prefix}pmpro_membership_orders o2
				           WHERE o2.user_id = o.user_id
				             AND o2.status = 'success'
				             AND o2.timestamp > o.timestamp
				        )
				  ORDER BY o.timestamp ASC",
				$env,
				$grace
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$stuck = (int) $r['hours_stuck'];

			$age = $stuck < 48
				? sprintf(
					/* translators: %d: whole hours the order has been unsettled */
					_n( 'stuck %d hour', 'stuck %d hours', $stuck, 'membership-health-check-for-paid-memberships-pro' ),
					$stuck
				)
				: sprintf(
					/* translators: %d: whole days the order has been unsettled */
					_n( 'stuck %d day', 'stuck %d days', intdiv( $stuck, 24 ), 'membership-health-check-for-paid-memberships-pro' ),
					intdiv( $stuck, 24 )
				);

			$detail = sprintf(
				/* translators: 1: amount, 2: order status, 3: date the order was raised, 4: how long it has been unsettled */
				__( '%1$s %2$s since %3$s, %4$s', 'membership-health-check-for-paid-memberships-pro' ),
				self::money( $r['total'] ),
				$r['order_status'],
				substr( (string) $r['timestamp'], 0, 10 ),
				$age
			);

			// The part that makes this invisible to everything else: PMPro has
			// already rolled the subscription forward as though it were paid.
			if ( ! empty( $r['next_payment_date'] )
				&& '0000-00-00 00:00:00' !== $r['next_payment_date']
				&& strtotime( (string) $r['next_payment_date'] ) > strtotime( (string) $r['timestamp'] ) ) {
				$detail .= ' — ' . sprintf(
					/* translators: %s: the date the subscription has already been rolled forward to */
					__( 'subscription already rolled on to %s', 'membership-health-check-for-paid-memberships-pro' ),
					substr( (string) $r['next_payment_date'], 0, 10 )
				);
			}

			$r['detail'] = $detail;
		}
		unset( $r );

		return self::result(
			'pending_payments',
			__( 'Payments not yet settled', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_INFO,
			__( 'Money asked for and not yet received: orders raised but not completed, on members who still have access. Usually a card that declined, expired, or was stopped at the bank, and most resolve on their own — the gateway either takes the money or gives up, and a failure normally closes the membership by itself. Listed rather than warned about, because nothing here needs doing. It is worth a look because these appear nowhere else: PMPro advances the subscription to the next cycle on the strength of an unsettled order, so none of them read as overdue.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'Every order raised has either settled or failed outright.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Level roles left on people who no longer hold that membership.
	 */
	public static function orphaned_roles(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT um.user_id, u.user_email, u.display_name, um.meta_value AS caps
				 FROM {$wpdb->usermeta} um
				 INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
				 WHERE um.meta_key = %s
				   AND um.meta_value LIKE %s
				   AND NOT EXISTS (
				         SELECT 1 FROM {$wpdb->prefix}pmpro_memberships_users mu
				         WHERE mu.user_id = um.user_id AND mu.status = 'active'
				       )",
				$wpdb->get_blog_prefix() . 'capabilities',
				'%' . $wpdb->esc_like( 'pmpro_role_' ) . '%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			preg_match_all( '/"(pmpro_role_\d+)"/', (string) $r['caps'], $m );
			$r['detail'] = sprintf(
				/* translators: %s: comma-separated list of role names */
				__( 'still holds %s with no active membership', 'membership-health-check-for-paid-memberships-pro' ),
				implode( ', ', $m[1] ? $m[1] : array( 'pmpro_role_*' ) )
			);
			unset( $r['caps'] );
		}
		unset( $r );

		return self::result(
			'orphaned_roles',
			__( 'Level roles left on former members', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_MEDIUM,
			__( 'The PMPro Roles add-on normally strips the level role when a membership ends. These were missed, usually by a bulk cancellation that skipped the per-user hook.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'No former member is still carrying a level role.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Active members who lack the site default role.
	 *
	 * PMPro Roles replaces the default role unless a level is configured to grant
	 * it too, and the setting only applies on a member's next level change. The
	 * result is members invisible to any role-based query, silently.
	 */
	public static function members_missing_default_role(): array {
		global $wpdb;
		$default = (string) get_option( 'default_role', 'subscriber' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT mu.user_id, u.user_email, u.display_name, mu.membership_id AS level
				 FROM {$wpdb->prefix}pmpro_memberships_users mu
				 INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id
				 INNER JOIN {$wpdb->usermeta} um ON um.user_id = mu.user_id AND um.meta_key = %s
				 WHERE mu.status = 'active'
				   AND um.meta_value NOT LIKE %s
				   AND um.meta_value NOT LIKE %s",
				$wpdb->get_blog_prefix() . 'capabilities',
				'%' . $wpdb->esc_like( '"' . $default . '"' ) . '%',
				'%' . $wpdb->esc_like( '"administrator"' ) . '%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$r['detail'] = sprintf(
				/* translators: %s: the site default role name */
				__( 'does not have the "%s" role', 'membership-health-check-for-paid-memberships-pro' ),
				$default
			);
		}
		unset( $r );

		return self::result(
			'missing_default_role',
			sprintf(
				/* translators: %s: the site default role name */
				__( 'Members without the "%s" role', 'membership-health-check-for-paid-memberships-pro' ),
				$default
			),
			self::SEVERITY_MEDIUM,
			__( 'PMPro Roles replaces the default role unless the level is configured to grant it as well, and that setting only applies on a member\'s next level change. Anything querying users by role will silently skip these people.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'Every active member has the site default role.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Membership rows whose WordPress user no longer exists.
	 */
	public static function ghost_memberships(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT mu.user_id, mu.membership_id AS level, mu.startdate
			 FROM {$wpdb->prefix}pmpro_memberships_users mu
			 LEFT JOIN {$wpdb->users} u ON u.ID = mu.user_id
			 WHERE mu.status = 'active' AND u.ID IS NULL",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$r['user_email']   = '';
			$r['display_name'] = __( '(deleted user)', 'membership-health-check-for-paid-memberships-pro' );
			$r['detail']       = __( 'membership row survives but the WordPress user is gone', 'membership-health-check-for-paid-memberships-pro' );
		}
		unset( $r );

		return self::result(
			'ghost_memberships',
			__( 'Memberships for deleted users', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_INFO,
			__( 'The user was deleted without their membership being closed. Harmless, but it inflates every member count you will ever run.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'Every active membership belongs to a real user.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Discount codes about to expire or run out of uses.
	 *
	 * Cuts both ways: a forgotten 100%-off code left open is a liability, and an
	 * internal test code that quietly hits its limit breaks your checkout tests.
	 */
	public static function discount_codes_running_out(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT c.id, c.code, c.expires, c.uses,
			        ( SELECT COUNT(*) FROM {$wpdb->prefix}pmpro_discount_codes_uses cu WHERE cu.code_id = c.id ) AS times_used
			 FROM {$wpdb->prefix}pmpro_discount_codes c
			 WHERE c.expires = '0000-00-00' OR c.expires >= CURDATE()
			 ORDER BY times_used DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$flagged = array();

		foreach ( $rows as $r ) {
			$uses  = (int) $r['uses'];
			$used  = (int) $r['times_used'];
			$notes = array();

			if ( $uses > 0 && $used >= $uses * 0.8 ) {
				$notes[] = sprintf(
					/* translators: 1: uses so far, 2: total allowed */
					__( '%1$d of %2$d uses consumed', 'membership-health-check-for-paid-memberships-pro' ),
					$used,
					$uses
				);
			}

			$expires = (string) $r['expires'];

			if ( $expires && '0000-00-00' !== $expires && strtotime( $expires ) < strtotime( '+60 days' ) ) {
				/* translators: %s: expiry date */
				$notes[] = sprintf( __( 'expires %s', 'membership-health-check-for-paid-memberships-pro' ), $expires );
			}

			if ( $notes ) {
				$flagged[] = array(
					'user_id'      => 0,
					'user_email'   => '',
					'display_name' => $r['code'],
					'level'        => '',
					'detail'       => implode( ', ', $notes ),
				);
			}
		}

		return self::result(
			'discount_codes',
			__( 'Discount codes near their limit or expiry', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_INFO,
			__( 'Worth knowing in both directions: a promotional code about to lapse, or an internal test code about to hit its use limit and break your checkout testing.', 'membership-health-check-for-paid-memberships-pro' ),
			$flagged,
			__( 'No live discount code is close to its limit or expiry.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Memberships ending within 30 days — the churn pipeline.
	 */
	public static function expiring_soon(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mu.user_id, u.user_email, u.display_name, mu.membership_id AS level, mu.enddate
				 FROM {$wpdb->prefix}pmpro_memberships_users mu
				 INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id
				 WHERE mu.status = 'active'
				   AND mu.enddate IS NOT NULL
				   AND mu.enddate <> '0000-00-00 00:00:00'
				   AND mu.enddate BETWEEN NOW() AND DATE_ADD( NOW(), INTERVAL %d DAY )
				 ORDER BY mu.enddate ASC",
				30
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$r['detail'] = sprintf(
				/* translators: %s: date access ends */
				__( 'access ends %s', 'membership-health-check-for-paid-memberships-pro' ),
				substr( (string) $r['enddate'], 0, 10 )
			);
		}
		unset( $r );

		return self::result(
			'expiring_soon',
			__( 'Memberships ending in the next 30 days', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_INFO,
			__( 'Not a fault — these people cancelled and are using up time they paid for. Listed because it is the last window in which a conversation still changes anything.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'Nobody lapses in the next 30 days.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Test-pattern accounts holding real memberships.
	 *
	 * Two kinds of evidence, deliberately kept apart. The suffixes below are
	 * reserved by RFC 2606 and RFC 6761 and can never belong to a real person on
	 * any site, so they are safe to match without knowing anything about yours.
	 * Everything else is local knowledge: what marks an address as a test on your
	 * site is yours to say, and a wrong guess here quietly accuses a paying member
	 * of being fake. Hence a short default list and a filter.
	 */
	public static function test_accounts(): array {
		global $wpdb;

		// Conventions rather than guesses: a plus-address tag means the same thing
		// almost everywhere. Filter values are matched literally, wherever they
		// appear in the address.
		$patterns = apply_filters( 'mhcheck_test_email_patterns', array( '+test', '+demo' ) );

		$alternatives = array();

		foreach ( (array) $patterns as $needle ) {
			$needle = strtolower( trim( (string) $needle ) );

			if ( '' !== $needle ) {
				$alternatives[] = self::regex_quote( $needle );
			}
		}

		// A mailbox literally called "test", anchored to the front so that
		// latest@ and greatest@ are not swept up with it.
		$alternatives[] = '^test@';

		// Anchored to the end of the address, so a real domain that merely contains
		// the word — testkitchen.com, example-design.com — is left alone.
		foreach ( self::RESERVED_SUFFIXES as $suffix ) {
			$alternatives[] = self::regex_quote( $suffix ) . '$';
		}

		// One static query and one bound value. The whole alternation travels as a
		// parameter rather than as SQL, so nothing a filter returns can reach the
		// query text — there is no assembled SQL here to get wrong.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mu.user_id, u.user_email, u.display_name, mu.membership_id AS level
				 FROM {$wpdb->prefix}pmpro_memberships_users mu
				 INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id
				 WHERE mu.status = 'active'
				   AND LOWER( u.user_email ) REGEXP %s",
				implode( '|', $alternatives )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as &$r ) {
			$r['detail'] = self::why_it_looks_like_a_test( (string) $r['user_email'], (array) $patterns );
		}
		unset( $r );

		return self::result(
			'test_accounts',
			__( 'Test accounts holding active memberships', 'membership-health-check-for-paid-memberships-pro' ),
			self::SEVERITY_INFO,
			__( 'Test signups that were never cleaned up. They distort every member count and every engagement statistic you look at. Add your own conventions with the mhcheck_test_email_patterns filter.', 'membership-health-check-for-paid-memberships-pro' ),
			$rows,
			__( 'No test-pattern address holds an active membership.', 'membership-health-check-for-paid-memberships-pro' )
		);
	}

	/**
	 * Say which rule flagged an address, so the finding can be argued with.
	 *
	 * @param string $email    Address that matched.
	 * @param array  $patterns Site-specific substrings in force.
	 */
	private static function why_it_looks_like_a_test( string $email, array $patterns ): string {
		$email = strtolower( $email );

		foreach ( self::RESERVED_SUFFIXES as $suffix ) {
			if ( substr( $email, -strlen( $suffix ) ) === $suffix ) {
				return sprintf(
					/* translators: %s: an email domain suffix reserved for testing, such as .invalid */
					__( '%s is reserved for testing and cannot be a real address', 'membership-health-check-for-paid-memberships-pro' ),
					$suffix
				);
			}
		}

		if ( 0 === strpos( $email, 'test@' ) ) {
			return __( 'the mailbox is called "test"', 'membership-health-check-for-paid-memberships-pro' );
		}

		foreach ( $patterns as $needle ) {
			$needle = strtolower( trim( (string) $needle ) );

			if ( '' !== $needle && false !== strpos( $email, $needle ) ) {
				return sprintf(
					/* translators: %s: the matched text */
					__( 'address contains "%s"', 'membership-health-check-for-paid-memberships-pro' ),
					$needle
				);
			}
		}

		return __( 'matches a test-account pattern', 'membership-health-check-for-paid-memberships-pro' );
	}

	/**
	 * Escape a literal so it matches itself inside a REGEXP alternation.
	 *
	 * Filter values are plain text, not patterns — somebody adding "+test (old)"
	 * means those characters, not a group. MariaDB and MySQL both accept a
	 * backslash before any of these.
	 *
	 * @param string $value Literal text to match.
	 */
	private static function regex_quote( string $value ): string {
		return (string) preg_replace( '/[\\\\^$.\[\]|()*+?{}]/', '\\\\$0', $value );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Stripe's webhook events: still arriving, and how reliably they arrived.
	 *
	 * PMPro records when each event type was last received and shows it under
	 * Settings → Payment → Stripe. The table below mirrors that, so the whole
	 * picture stays on one screen.
	 *
	 * It then adds what that table structurally cannot show. PMPro keeps only the
	 * most recent timestamp per event type and overwrites it on every delivery,
	 * which answers "is the endpoint alive?" but never "did every event arrive?".
	 * On the site this was written for, three subscriptions leaked over twelve
	 * months while every event type still read as recently received. So the
	 * silence window here is measured against the site's own billing rhythm
	 * instead of a fixed number of days, and delivery loss is measured from the
	 * damage it left behind.
	 */
	public static function webhook_health(): array {
		global $wpdb;

		$label = __( 'Stripe webhook delivery', 'membership-health-check-for-paid-memberships-pro' );

		if ( 'stripe' !== get_option( 'pmpro_gateway' ) ) {
			return self::result(
				'webhook_health',
				$label,
				self::SEVERITY_HIGH,
				'',
				array(),
				__( 'Not applicable — this site does not use the Stripe gateway.', 'membership-health-check-for-paid-memberships-pro' )
			);
		}

		$env  = (string) get_option( 'pmpro_gateway_environment' );
		$env  = '' === $env ? 'live' : $env;
		$flow = (string) get_option( 'pmpro_stripe_payment_flow', 'onsite' );
		$now  = time();

		// The same list PMPro registers with Stripe, through the same filter, so a
		// site that has customised its events still lines up with what it sends.
		$events = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PMPro's own filter, deliberately: matching the list it registers with Stripe is the whole point of this check.
			'pmpro_stripe_webhook_events',
			array(
				'invoice.created',
				'invoice.upcoming',
				'invoice.payment_succeeded',
				'invoice.payment_action_required',
				'customer.subscription.deleted',
				'charge.failed',
				'charge.refunded',
				'checkout.session.completed',
				'checkout.session.async_payment_succeeded',
				'checkout.session.async_payment_failed',
			)
		);
		sort( $events );

		$received   = array();
		$table_rows = array();

		foreach ( $events as $event ) {
			$stamp              = (int) get_option( 'pmpro_stripe_webhook_last_received_' . $env . '_' . $event );
			$received[ $event ] = $stamp;

			// On-site checkout never creates a Checkout Session, so these three can
			// never arrive. PMPro leaves them as a permanent grey "N/A"; saying why
			// beats a blank row that reads like a fault.
			$unused = ( 'onsite' === $flow && 0 === strpos( $event, 'checkout.session.' ) );

			if ( $stamp > 0 ) {
				$when = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp );
				/* translators: %s: human-readable time difference, e.g. "2 days" */
				$status = sprintf( __( '%s ago', 'membership-health-check-for-paid-memberships-pro' ), human_time_diff( $stamp, $now ) );
			} else {
				$when   = '—';
				$status = $unused
					? __( 'not used with on-site checkout', 'membership-health-check-for-paid-memberships-pro' )
					: __( 'never received', 'membership-health-check-for-paid-memberships-pro' );
			}

			$table_rows[] = array(
				'event'         => $event,
				'last_received' => $when,
				'status'        => $status,
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		// Scoped to the live environment, like the options read above: a sandbox
		// subscription says nothing about whether the live webhook is arriving.
		$active_subs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}pmpro_subscriptions
				 WHERE status = 'active' AND gateway_environment = %s",
				$env
			)
		);

		$ended_recently = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}pmpro_subscriptions
				 WHERE status <> 'active'
				   AND gateway_environment = %s
				   AND enddate IS NOT NULL
				   AND enddate <> '0000-00-00 00:00:00'
				   AND enddate >= DATE_SUB( NOW(), INTERVAL 365 DAY )",
				$env
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = array();

		// Only judge events this site actually subscribes to. The list above runs
		// through PMPro's filter, and an event deliberately removed from it is not
		// missing — Stripe was never asked to send it.
		$paid = array_key_exists( 'invoice.payment_succeeded', $received ) ? $received['invoice.payment_succeeded'] : -1;

		if ( $active_subs > 0 && 0 === $paid ) {
			$rows[] = self::event_row(
				'invoice.payment_succeeded',
				sprintf(
					/* translators: %d: number of active subscriptions */
					__( 'never received, yet %d subscriptions are billing — the endpoint has probably never worked', 'membership-health-check-for-paid-memberships-pro' ),
					$active_subs
				)
			);
		} elseif ( $active_subs > 0 && $paid > 0 ) {
			$quietest = self::widest_billing_gap( $env );

			// Without a year of orders behind it there is no way to say what quiet
			// looks like here, and a guessed threshold is worse than none at all.
			if ( $quietest > 0 ) {
				$silent = (int) floor( ( $now - $paid ) / DAY_IN_SECONDS );

				if ( $silent > max( 10, $quietest * 2 ) ) {
					$rows[] = self::event_row(
						'invoice.payment_succeeded',
						sprintf(
							/* translators: 1: days without a payment event, 2: number of active subscriptions, 3: longest normal quiet spell in days */
							__( 'silent %1$d days against %2$d active subscriptions — this site has never before gone quieter than %3$d days. Either delivery has stopped, or this database is a copy restored from a backup.', 'membership-health-check-for-paid-memberships-pro' ),
							$silent,
							$active_subs,
							$quietest
						)
					);
				}
			}
		}

		// Cancellations matter as much as payments: this is the event whose loss
		// leaves a membership open with nothing billing behind it.
		$deleted = array_key_exists( 'customer.subscription.deleted', $received ) ? $received['customer.subscription.deleted'] : -1;

		if ( $ended_recently > 0 && 0 === $deleted ) {
			$rows[] = self::event_row(
				'customer.subscription.deleted',
				sprintf(
					/* translators: %d: number of subscriptions that ended in the past year */
					__( 'never received, yet %d subscriptions ended in the past year — endings are not reaching this site', 'membership-health-check-for-paid-memberships-pro' ),
					$ended_recently
				)
			);
		}

		$summary = __( 'PMPro keeps only the latest timestamp per event type, so a recent one proves the endpoint is alive — not that every event arrived.', 'membership-health-check-for-paid-memberships-pro' )
			. ' ' . self::delivery_loss( $ended_recently, $env );

		return self::result(
			'webhook_health',
			$label,
			self::SEVERITY_HIGH,
			$summary,
			$rows,
			$summary,
			array(
				'columns' => array(
					'event'         => __( 'Event', 'membership-health-check-for-paid-memberships-pro' ),
					'last_received' => __( 'Last received', 'membership-health-check-for-paid-memberships-pro' ),
					'status'        => __( 'Status', 'membership-health-check-for-paid-memberships-pro' ),
				),
				'rows'    => $table_rows,
			)
		);
	}

	/**
	 * A finding about an event type rather than about a person.
	 *
	 * @param string $event  Stripe event type the finding concerns.
	 * @param string $detail What is wrong with it.
	 */
	private static function event_row( string $event, string $detail ): array {
		return array(
			'user_id'      => 0,
			'user_email'   => '',
			'display_name' => $event,
			'level'        => '',
			'detail'       => $detail,
		);
	}

	/**
	 * The longest this site has ever gone between successful orders in a year.
	 *
	 * A fixed threshold is wrong everywhere except the site it was tuned on, so
	 * the silence alarm is built from the site's own record instead. On the site
	 * this was written for the quietest stretch in twelve months was six days,
	 * putting the alarm at twelve.
	 *
	 * @param string $env Gateway environment to measure, live or sandbox.
	 *
	 * @return int Days, or 0 when there is too little history to say.
	 */
	private static function widest_billing_gap( string $env ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		$days = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT DATE( timestamp )
				 FROM {$wpdb->prefix}pmpro_membership_orders
				 WHERE status = 'success'
				   AND gateway_environment = %s
				   AND timestamp >= DATE_SUB( NOW(), INTERVAL 365 DAY )
				 ORDER BY 1",
				$env
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Fewer than three billing days in a year is not a rhythm to measure against.
		if ( count( $days ) < 3 ) {
			return 0;
		}

		$widest = 0;
		$prev   = 0;

		foreach ( $days as $day ) {
			$stamp = strtotime( (string) $day );

			if ( $prev ) {
				$widest = max( $widest, (int) round( ( $stamp - $prev ) / DAY_IN_SECONDS ) );
			}

			$prev = $stamp;
		}

		return $widest;
	}

	/**
	 * How lossy delivery has actually been, stated as a sentence.
	 *
	 * Measured from the damage rather than from the gateway: subscriptions that
	 * ended, against those that ended and left the membership open with no end
	 * date and nothing else billing. That shape occurs when the event announcing
	 * the end never arrived, and it is the only evidence of a dropped webhook
	 * that survives once PMPro has overwritten its last-received timestamp.
	 *
	 * @param int    $ended Subscriptions that ended in the past year.
	 * @param string $env   Gateway environment to measure, live or sandbox.
	 */
	private static function delivery_loss( int $ended, string $env ): string {
		global $wpdb;

		if ( $ended < 1 ) {
			return __( 'No subscription has ended in the past year, so there is nothing yet to measure delivery against.', 'membership-health-check-for-paid-memberships-pro' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names come from $wpdb->prefix and cannot be placeholders.
		$leaked = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT s.id )
				 FROM {$wpdb->prefix}pmpro_subscriptions s
				 INNER JOIN {$wpdb->prefix}pmpro_memberships_users mu
				         ON mu.user_id = s.user_id AND mu.status = 'active'
				 WHERE s.status <> 'active'
				   AND s.gateway_environment = %s
				   AND s.enddate IS NOT NULL
				   AND s.enddate <> '0000-00-00 00:00:00'
				   AND s.enddate >= DATE_SUB( NOW(), INTERVAL 365 DAY )
				   AND ( mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' )
				   AND NOT EXISTS (
				         SELECT 1 FROM {$wpdb->prefix}pmpro_subscriptions a
				         WHERE a.user_id = s.user_id AND a.status = 'active'
				       )",
				$env
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 0 === $leaked ) {
			return sprintf(
				/* translators: %d: number of subscriptions that ended in the past year */
				__( 'All %d subscriptions that ended in the past year closed their membership cleanly.', 'membership-health-check-for-paid-memberships-pro' ),
				$ended
			);
		}

		return sprintf(
			/* translators: 1: subscriptions that leaked, 2: subscriptions that ended, 3: percentage to one decimal place */
			__( 'Of %2$d subscriptions that ended in the past year, %1$d left a membership open with nothing billing — %3$s%% of endings were lost in delivery.', 'membership-health-check-for-paid-memberships-pro' ),
			$leaked,
			$ended,
			number_format_i18n( $leaked / $ended * 100, 1 )
		);
	}
}
