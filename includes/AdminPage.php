<?php
/**
 * The report screen.
 *
 * @package MembershipHealthCheck
 */

namespace MembershipHealthCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The report screen under PMPro's own menu.
 */
final class AdminPage {

	public const SLUG = 'membership-health-check';

	public const CAPABILITY = 'manage_options';

	/** PMPro's top-level menu. */
	private const PMPRO_MENU = 'pmpro-dashboard';

	/**
	 * Register the screen. Admin only; nothing here runs on a front-end request.
	 */
	public static function init(): void {
		// Late, so PMPro's own menu exists to hang off.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Hang the page off PMPro's menu, or off Tools if PMPro has not built one.
	 */
	public static function register_menu(): void {
		global $admin_page_hooks;

		$parent = isset( $admin_page_hooks[ self::PMPRO_MENU ] ) ? self::PMPRO_MENU : 'tools.php';

		add_submenu_page(
			$parent,
			__( 'Membership Health Check', 'membership-health-check-for-paid-memberships-pro' ),
			__( 'Health Check', 'membership-health-check-for-paid-memberships-pro' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the stylesheet on this screen only.
	 *
	 * @param string $hook_suffix Screen the admin is currently rendering.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'mhc-admin', MHCHECK_URL . 'assets/admin.css', array(), MHCHECK_VERSION );
	}

	/**
	 * The report views, in the order they appear.
	 *
	 * @return array<string,string> Tab slug to label.
	 */
	private static function tabs(): array {
		return array(
			'members'  => __( 'Members', 'membership-health-check-for-paid-memberships-pro' ),
			'webhooks' => __( 'Webhooks', 'membership-health-check-for-paid-memberships-pro' ),
		);
	}

	/**
	 * Which report the reader is looking at.
	 *
	 * The value is checked against the tab list rather than sanitised and trusted,
	 * so anything unrecognised falls back to the members view.
	 */
	private static function current_tab(): string {
		// Switching between two read-only reports changes nothing, so there is no
		// state for a nonce to protect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'members';
	}

	/**
	 * Draw the tab strip.
	 *
	 * @param string $current Slug of the open tab.
	 */
	private static function render_tabs( string $current ): void {
		$base = menu_page_url( self::SLUG, false );

		echo '<nav class="nav-tab-wrapper wp-clearfix">';

		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( add_query_arg( 'tab', $slug, $base ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Render the open tab's report.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'membership-health-check-for-paid-memberships-pro' ) );
		}

		echo '<div class="wrap mhc">';
		// The version sits in the heading rather than the footer: knowing which
		// build produced a finding matters most at the moment you are reading it.
		printf(
			'<h1>%1$s <span class="mhc-version">%2$s</span></h1>',
			esc_html__( 'Membership Health Check', 'membership-health-check-for-paid-memberships-pro' ),
			esc_html( MHCHECK_VERSION )
		);

		if ( ! Plugin::pmpro_active() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Paid Memberships Pro is not active, so there is nothing to check.', 'membership-health-check-for-paid-memberships-pro' )
				. '</p></div></div>';
			return;
		}

		$environment = Plugin::environment_note();

		if ( '' !== $environment ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $environment ) . '</p></div>';
		}

		$tab = self::current_tab();

		self::render_tabs( $tab );

		echo '<p class="mhc-intro">'
			. esc_html__( 'Read-only. Nothing on this page changes your data — it reports what looks wrong so you can decide what to do about it.', 'membership-health-check-for-paid-memberships-pro' )
			. '</p>';

		// Only the open tab runs. There is no reason to query members while you are
		// looking at the gateway, and it keeps each view as quick as the whole page
		// used to be.
		$started = microtime( true );
		$results = 'webhooks' === $tab ? Checks::gateway() : Checks::members();
		$elapsed = microtime( true ) - $started;

		$problems = array_sum(
			array_map(
				static function ( $r ) {
					return Checks::SEVERITY_INFO === $r['severity'] ? 0 : $r['count'];
				},
				$results
			)
		);

		// The webhook report counts event types, not people, so it says so.
		if ( ! $problems ) {
			$headline = __( 'Nothing needs attention.', 'membership-health-check-for-paid-memberships-pro' );
		} elseif ( 'webhooks' === $tab ) {
			$headline = sprintf(
				/* translators: %d: number of webhook event types needing attention */
				_n( '%d event type needs attention.', '%d event types need attention.', $problems, 'membership-health-check-for-paid-memberships-pro' ),
				$problems
			);
		} else {
			$headline = sprintf(
				/* translators: %d: number of accounts needing attention */
				_n( '%d account needs attention.', '%d accounts need attention.', $problems, 'membership-health-check-for-paid-memberships-pro' ),
				$problems
			);
		}

		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong></p></div>',
			$problems ? 'warning' : 'success',
			esc_html( $headline )
		);

		foreach ( $results as $r ) {
			self::render_check( $r );
		}

		printf(
			'<p class="mhc-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of checks, 2: seconds taken */
					_n(
						'%1$d check run in %2$.2f seconds. Nothing runs in the background; this page only works when you open it.',
						'%1$d checks run in %2$.2f seconds. Nothing runs in the background; this page only works when you open it.',
						count( $results ),
						'membership-health-check-for-paid-memberships-pro'
					),
					count( $results ),
					$elapsed
				)
			)
		);

		echo '</div>';
	}

	/**
	 * One check: its verdict, its findings, and its status panel if it has one.
	 *
	 * @param array $r One check result.
	 */
	private static function render_check( array $r ): void {
		$clean = ( 0 === $r['count'] );
		$state = $clean ? 'ok' : $r['severity'];

		printf(
			'<div class="mhc-check mhc-check--%1$s"><h2><span class="mhc-badge mhc-badge--%1$s">%2$s</span> %3$s</h2>',
			esc_attr( $state ),
			esc_html( $clean ? __( 'OK', 'membership-health-check-for-paid-memberships-pro' ) : (string) $r['count'] ),
			esc_html( $r['label'] )
		);

		echo '<p class="mhc-muted">' . esc_html( $clean ? $r['good'] : $r['explain'] ) . '</p>';

		// Checks may add their own columns between the level and the finding —
		// evidence that belongs in a column of its own rather than buried in a
		// sentence, such as the discount code that explains a membership.
		$extra = empty( $r['columns'] ) ? array() : $r['columns'];

		if ( ! $clean ) {
			echo '<table class="wp-list-table widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Who', 'membership-health-check-for-paid-memberships-pro' ) . '</th>';
			echo '<th>' . esc_html__( 'Level', 'membership-health-check-for-paid-memberships-pro' ) . '</th>';

			foreach ( $extra as $heading ) {
				echo '<th>' . esc_html( $heading ) . '</th>';
			}

			echo '<th>' . esc_html__( 'What was found', 'membership-health-check-for-paid-memberships-pro' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $r['rows'] as $row ) {
				$uid = (int) ( $row['user_id'] ?? 0 );

				echo '<tr><td>';

				if ( $uid > 0 ) {
					printf(
						'<a href="%1$s"><strong>%2$s</strong></a><br><span class="mhc-muted">%3$s</span>',
						esc_url( get_edit_user_link( $uid ) ),
						esc_html( (string) ( $row['display_name'] ?? ( '#' . $uid ) ) ),
						esc_html( (string) ( $row['user_email'] ?? '' ) )
					);
				} else {
					echo '<strong>' . esc_html( (string) ( $row['display_name'] ?? '—' ) ) . '</strong>';
				}

				echo '</td><td>' . esc_html( '' === (string) ( $row['level'] ?? '' ) ? '—' : (string) $row['level'] ) . '</td>';

				foreach ( array_keys( $extra ) as $key ) {
					$value = (string) ( $row[ $key ] ?? '' );
					echo '<td>' . esc_html( '' === $value ? '—' : $value ) . '</td>';
				}

				echo '<td>' . esc_html( (string) ( $row['detail'] ?? '' ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}

		// A standing status panel, shown whether or not the check found a fault.
		// Only the webhook check has one: the last-received times are worth seeing
		// even when nothing is wrong, which is the whole reason for mirroring them.
		if ( ! empty( $r['table']['rows'] ) ) {
			echo '<table class="wp-list-table widefat striped mhc-status"><thead><tr>';

			foreach ( $r['table']['columns'] as $heading ) {
				echo '<th>' . esc_html( $heading ) . '</th>';
			}

			echo '</tr></thead><tbody>';

			foreach ( $r['table']['rows'] as $row ) {
				echo '<tr>';

				foreach ( array_keys( $r['table']['columns'] ) as $key ) {
					echo '<td>' . esc_html( (string) ( $row[ $key ] ?? '' ) ) . '</td>';
				}

				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
