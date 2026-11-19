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
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
		add_action( 'admin_init', [ __CLASS__, 'privacy_policy' ] );
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

	/**
	 * Add the MooPress exporter to Tools > Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['moopress'] = [
			'exporter_friendly_name' => __( 'MooPress (Moodle link and audit log)', 'moopress' ),
			'callback'               => [ __CLASS__, 'export_personal_data' ],
		];
		return $exporters;
	}

	/**
	 * Add the MooPress eraser to Tools > Erase Personal Data.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['moopress'] = [
			'eraser_friendly_name' => __( 'MooPress audit log and Moodle link', 'moopress' ),
			'callback'             => [ __CLASS__, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/**
	 * Export a person's Moodle link and audit-log entries.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$per_page = 100;
		$data     = [];
		if ( 1 === $page ) {
			$moodle_id = get_user_meta( $user->ID, Moopress_User_Match::META_MOODLE_ID, true );
			if ( '' !== (string) $moodle_id ) {
				$data[] = [
					'group_id'    => 'moopress-link',
					'group_label' => __( 'MooPress Moodle link', 'moopress' ),
					'item_id'     => 'moopress-link',
					'data'        => [
						[
							'name'  => __( 'Moodle user id', 'moopress' ),
							'value' => (string) $moodle_id,
						],
					],
				];
			}
		}
		$rows = Moopress_Logger::for_person( $user->ID, $per_page, ( $page - 1 ) * $per_page );
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => 'moopress-log',
				'group_label' => __( 'MooPress audit log', 'moopress' ),
				'item_id'     => 'moopress-log-' . $row['id'],
				'data'        => [
					[
						'name'  => __( 'Time (UTC)', 'moopress' ),
						'value' => $row['created'],
					],
					[
						'name'  => __( 'Request to Moodle', 'moopress' ),
						'value' => $row['wsfunction'],
					],
					[
						'name'  => __( 'Result', 'moopress' ),
						'value' => $row['status'],
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $rows ) < $per_page,
		];
	}

	/**
	 * Erase a person's Moodle link, managed-course list and audit-log entries.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1 (all data goes in one pass).
	 * @return array
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			$removed += Moopress_Logger::delete_for_person( $user->ID );
			if ( delete_user_meta( $user->ID, Moopress_User_Match::META_MOODLE_ID ) ) {
				++$removed;
			}
			if ( delete_user_meta( $user->ID, Moopress_Entitlement::MANAGED_META ) ) {
				++$removed;
			}
		}
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy).
	 *
	 * @return void
	 */
	public static function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'MooPress – Moodle bridge', 'moopress' ),
			'<p>'
				. esc_html__( 'This site links your account to our Moodle learning site to enrol you in courses and show your progress.', 'moopress' )
				. ' ' . esc_html__( 'A log of each request to Moodle (when, what, and whether it worked) is kept for a while; credentials are always removed from it.', 'moopress' )
				. '</p>'
		);
	}
}
