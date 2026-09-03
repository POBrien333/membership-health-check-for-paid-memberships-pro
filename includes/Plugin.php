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

	/**
	 * A warning when the report is running somewhere its answers cannot be trusted.
	 *
	 * Every date-based check compares a stored date against the current clock. On
	 * a copy restored from a backup those two disagree by the age of the copy, so
	 * payments taken since the snapshot look missing, cancellations look ignored,
	 * and members who have left look like leaks. The findings are accurate
	 * statements about the database in front of them and worthless as statements
	 * about the business.
	 *
	 * This relies on WP_ENVIRONMENT_TYPE, which not every host sets. A staging
	 * site that reports itself as production will still mislead, so the webhook
	 * check names a restored copy as one explanation for billing having gone
	 * quiet.
	 *
	 * @return string Empty when there is nothing worth saying.
	 */
	public static function environment_note(): string {
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( 'production' === $type ) {
			return '';
		}

		return sprintf(
			/* translators: %s: WordPress environment type, such as staging or development */
			__( 'This is a %s environment. Date-based checks compare stored dates against the current clock, so on a copy restored from a backup, payments taken since the snapshot look missing and cancellations look ignored. Read these findings as questions about this database, not about your live site.', 'membership-health-check-for-paid-memberships-pro' ),
			$type
		);
	}
}
