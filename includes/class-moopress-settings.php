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
 * Plugin settings: the Moodle site URL, the service-account token and feature flags.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings, stored in one option.
 */
class Moopress_Settings {

	/** The option that holds every setting. */
	const OPTION = 'moopress_settings';

	/**
	 * The default settings, merged under whatever is stored.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'base_url'           => '',
			'token'              => '',
			// Nothing is written to Moodle while this is on: enrolment changes are logged only.
			'dry_run'            => true,
			'log_enabled'        => true,
			// Keep full request and response bodies (for debugging only).
			'log_capture'        => false,
			'log_retention_days' => 30,
			// How a WordPress user is matched to a Moodle user: email, idnumber or username.
			'match_by'           => 'email',
			// When matching by idnumber: the WordPress user-meta key that holds it.
			'match_meta_key'     => 'moopress_idnumber',
			// Enrol WooCommerce buyers into mapped Moodle courses.
			'enrol_enabled'      => false,
			// The Moodle role id to enrol buyers with (5 is the default student role).
			'student_roleid'     => 5,
			// Create a Moodle user for a buyer who has none yet.
			'provision_users'    => false,
			// The Moodle auth method for a provisioned user.
			'provision_auth'     => 'manual',
		];
	}

	/**
	 * Every setting, defaults filled in.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Value if the setting is absent.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge new values into the stored settings.
	 *
	 * @param array $values Settings to change.
	 * @return void
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Whether a Moodle site URL and a token are both set.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== (string) self::get( 'base_url' ) && '' !== (string) self::get( 'token' );
	}

	/**
	 * The Moodle REST endpoint, derived from the configured site URL.
	 *
	 * @return string
	 */
	public static function rest_endpoint(): string {
		$base = untrailingslashit( (string) self::get( 'base_url' ) );
		return '' === $base ? '' : $base . '/webservice/rest/server.php';
	}
}
