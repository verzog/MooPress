<?php
// This file is part of MooPress, a WordPress and Moodle bridge.
//
// MooPress is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// MooPress is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with MooPress.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin bootstrap: hooks, activation and scheduled clean-up.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
class Moopress_Plugin {

	/** Daily audit-log clean-up hook. */
	const CLEANUP_HOOK = 'moopress_cleanup_log';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CLEANUP_HOOK, [ 'Moopress_Logger', 'purge' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ] );
		Moopress_Enrolment::init();
		Moopress_Source_Purchase::init();
		Moopress_Source_Membership::init();
		Moopress_Entitlement::init();
		Moopress_Courses::init();
		if ( is_admin() ) {
			Moopress_Admin::init();
		}
	}

	/**
	 * Activation: create the log table and schedule the daily clean-up.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Moopress_Logger::install();
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Deactivation: stop the scheduled clean-up (settings and log are kept).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		// Drop any queued reconcile jobs: nothing is sent while the plugin is inactive.
		wp_unschedule_hook( Moopress_Entitlement::RECONCILE_USER_HOOK );
		wp_clear_scheduled_hook( Moopress_Entitlement::RECONCILE_ALL_HOOK );
	}

	/**
	 * Create or update the log table after a plugin update (activation does not run then).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'moopress_db_version' ) !== Moopress_Logger::DB_VERSION ) {
			Moopress_Logger::install();
		}
	}
}
