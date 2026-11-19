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
 * Audit log of every call made to Moodle, with the service-account token redacted.
 *
 * Modelled on wormiss's logger (WordPress), itself adapted from local_membershipbridge
 * (Moodle). Rows live in the {prefix}moopress_log table; old rows are purged daily by
 * WP-Cron.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes, reads and prunes the audit log.
 */
class Moopress_Logger {

	/** A call that succeeded. */
	const STATUS_SUCCESS = 'success';

	/** A call that failed. */
	const STATUS_ERROR = 'error';

	/** Database schema version, bumped when the table changes. */
	const DB_VERSION = '1';

	/**
	 * The log table name, with the site prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moopress_log';
	}

	/**
	 * Create or update the log table (on activation).
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				wsfunction varchar(191) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				error text NULL,
				request longtext NULL,
				response longtext NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created (created),
				KEY user_id (user_id)
			) {$charset};"
		);
		update_option( 'moopress_db_version', self::DB_VERSION, false );
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) Moopress_Settings::get( 'log_enabled' );
	}

	/**
	 * Whether full request and response bodies are kept (for debugging only).
	 *
	 * @return bool
	 */
	public static function is_capturing(): bool {
		return (bool) Moopress_Settings::get( 'log_capture' );
	}

	/**
	 * Record one call to Moodle. Never throws: a logging failure must not break a page.
	 *
	 * @param string      $wsfunction Web service function, e.g. "core_webservice_get_site_info".
	 * @param string      $status     STATUS_SUCCESS or STATUS_ERROR.
	 * @param string|null $error      Short error reason.
	 * @param string|null $request    Request body (kept only when capturing).
	 * @param string|null $response   Response body (kept only when capturing).
	 * @return void
	 */
	public static function record_call(
		string $wsfunction,
		string $status,
		?string $error = null,
		?string $request = null,
		?string $response = null
	): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		global $wpdb;
		$capture = self::is_capturing();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom log table; no WordPress API exists.
		$wpdb->insert(
			self::table(),
			[
				'created'    => current_time( 'mysql', true ),
				'wsfunction' => substr( self::redact( $wsfunction ), 0, 191 ),
				'status'     => $status,
				// An error can echo a rejected token, so it is redacted too.
				'error'      => null === $error ? null : self::redact( $error ),
				'request'    => ( $capture && null !== $request ) ? self::redact( $request ) : null,
				'response'   => ( $capture && null !== $response ) ? self::redact( $response ) : null,
				'user_id'    => get_current_user_id(),
			]
		);
	}

	/**
	 * Remove the Moodle token from text before it is stored or shown.
	 *
	 * Strips the form-encoded and JSON wstoken, and generic bearer tokens.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function redact( string $text ): string {
		$text = (string) preg_replace( '/(\bwstoken=)[^&\s]*/i', '${1}[redacted]', $text );
		$text = (string) preg_replace( '/("wstoken"\s*:\s*")[^"]*(")/i', '${1}[redacted]${2}', $text );
		return (string) preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-~+\/=]+/i', '${1}[redacted]', $text );
	}

	/**
	 * The most recent log rows, newest first.
	 *
	 * @param int $limit  Rows per page.
	 * @param int $offset Rows to skip.
	 * @return array[]
	 */
	public static function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom log table, read on an admin page only.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $limit, $offset ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Log rows about one WordPress user (privacy export).
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $limit   Rows.
	 * @param int $offset  Rows to skip.
	 * @return array[]
	 */
	public static function for_person( int $user_id, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom log table; privacy export.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d',
				self::table(),
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete log rows about one WordPress user (privacy erasure).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Rows deleted.
	 */
	public static function delete_for_person( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom log table; privacy erasure.
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', self::table(), $user_id )
		);
	}

	/**
	 * Number of rows in the log.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom log table, read on an admin page only.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Delete rows older than the retention period (daily cron).
	 *
	 * @param int|null $days Days to keep; defaults to the setting.
	 * @return int Rows deleted.
	 */
	public static function purge( ?int $days = null ): int {
		global $wpdb;
		$days   = null === $days ? (int) Moopress_Settings::get( 'log_retention_days' ) : $days;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom log table; scheduled cleanup.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %s', self::table(), $cutoff ) );
	}
}
