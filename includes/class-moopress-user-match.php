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
 * Match a WordPress user to a Moodle user, and remember the result.
 *
 * The resolved Moodle user id is cached in user meta so it is not looked up on
 * every call. The matching key is configurable: the WordPress user's email,
 * their username, or an "ID number" read from a WordPress user field (e.g. the
 * iMIS ID set by single sign-on) matched against Moodle's idnumber. The ID
 * number is the stable key while iMIS is in the picture; email is the default
 * and always available.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and caches the Moodle user id for a WordPress user.
 */
class Moopress_User_Match {

	/** User meta key holding the resolved Moodle user id. */
	const META_MOODLE_ID = 'moopress_moodle_user_id';

	/**
	 * The Moodle user id for a WordPress user, from the cache or by asking Moodle.
	 *
	 * @param int  $wp_user_id WordPress user ID.
	 * @param bool $refresh    Ignore the cached value and look the user up again.
	 * @return int|null The Moodle user id, or null when there is no match.
	 */
	public static function moodle_user_id( int $wp_user_id, bool $refresh = false ): ?int {
		if ( ! $refresh ) {
			$cached = get_user_meta( $wp_user_id, self::META_MOODLE_ID, true );
			if ( '' !== $cached ) {
				return (int) $cached;
			}
		}
		return self::resolve( $wp_user_id );
	}

	/**
	 * Look the user up in Moodle and cache the result.
	 *
	 * A Moodle error is swallowed (the REST client logs it) and treated as "no
	 * match" so that matching never throws into a caller.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return int|null The Moodle user id, or null when there is no match.
	 */
	public static function resolve( int $wp_user_id ): ?int {
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return null;
		}
		$match = self::match_target( $user );
		if ( '' === $match['value'] ) {
			return null;
		}
		try {
			$found = ( new Moopress_Rest_Client() )->call(
				'core_user_get_users_by_field',
				[
					'field'  => $match['field'],
					'values' => [ $match['value'] ],
				]
			);
		} catch ( Moopress_Exception $e ) {
			return null;
		}
		if ( is_array( $found ) && isset( $found[0]['id'] ) ) {
			$moodle_id = (int) $found[0]['id'];
			update_user_meta( $wp_user_id, self::META_MOODLE_ID, $moodle_id );
			return $moodle_id;
		}
		return null;
	}

	/**
	 * The Moodle field and the WordPress-side value to match on, per the setting.
	 *
	 * @param WP_User $user WordPress user.
	 * @return array {field:string, value:string}
	 */
	public static function match_target( WP_User $user ): array {
		$by = (string) Moopress_Settings::get( 'match_by', 'email' );
		if ( 'idnumber' === $by ) {
			$key   = (string) Moopress_Settings::get( 'match_meta_key', 'moopress_idnumber' );
			$value = '' !== $key ? (string) get_user_meta( $user->ID, $key, true ) : '';
			return [
				'field' => 'idnumber',
				'value' => $value,
			];
		}
		if ( 'username' === $by ) {
			return [
				'field' => 'username',
				'value' => (string) $user->user_login,
			];
		}
		return [
			'field' => 'email',
			'value' => (string) $user->user_email,
		];
	}

	/**
	 * Forget the cached Moodle user id for a WordPress user.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return void
	 */
	public static function forget( int $wp_user_id ): void {
		delete_user_meta( $wp_user_id, self::META_MOODLE_ID );
	}
}
