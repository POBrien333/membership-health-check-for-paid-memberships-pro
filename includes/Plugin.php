<?php
/**
 * Bootstrap.
 *
 * @package MembershipHealthCheck
 */

namespace MembershipHealthCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap: wires the plugin into the admin and WP-CLI, and nowhere else.
 */
final class Plugin {

	/**
	 * Admin and WP-CLI only, by design.
	 *
	 * This plugin reads reports; it has no front-end role. Nothing at all is
	 * registered on a public page view, so a busy site pays nothing for having
	 * it installed. There is also no cron: the checks run when you ask for them.
	 */
	public static function init(): void {
		if ( is_admin() ) {
			AdminPage::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'membership-health', array( Cli::class, 'run' ) );
		}
	}

	/**
	 * Whether Paid Memberships Pro is present.
	 *
	 * Everything here reads PMPro's tables, so without it there is nothing to do.
	 */
	public static function pmpro_active(): bool {
		return defined( 'PMPRO_VERSION' ) || function_exists( 'pmpro_getMembershipLevelForUser' );
	}
}
