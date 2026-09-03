<?php
/**
 * Plugin Name:       Membership Health Check for Paid Memberships Pro
 * Plugin URI:        https://github.com/POBrien333/membership-health-check-for-paid-memberships-pro
 * Description:       Finds members who still have access but stopped paying, subscriptions the gateway abandoned, orphaned roles and other quiet data drift in Paid Memberships Pro. Reports only — it never changes anything.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bluerivergrowth
 * Author URI:        https://github.com/POBrien333/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       membership-health-check-for-paid-memberships-pro
 *
 * This plugin is not affiliated with or endorsed by Stranger Studios, the makers
 * of Paid Memberships Pro. "Paid Memberships Pro" is their trademark and is used
 * here only to describe what this plugin is compatible with.
 *
 * @package MembershipHealthCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MHCHECK_VERSION', '0.5.0' );
define( 'MHCHECK_FILE', __FILE__ );
define( 'MHCHECK_PATH', plugin_dir_path( __FILE__ ) );
define( 'MHCHECK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal autoloader — no Composer, no build step.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'MembershipHealthCheck\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = MHCHECK_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', array( \MembershipHealthCheck\Plugin::class, 'init' ) );
