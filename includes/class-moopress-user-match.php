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

	/** Lookup outcome: exactly one Moodle user matched. */
	const OUTCOME_MATCHED = 'matched';

	/** Lookup outcome: Moodle answered with no matching user. */
	const OUTCOME_NONE = 'none';

	/** Lookup outcome: more than one Moodle user matched (ambiguous). */
	const OUTCOME_AMBIGUOUS = 'ambiguous';

	/** Lookup outcome: the lookup could not be completed (no key, or a Moodle error). */
	const OUTCOME_ERROR = 'error';

	/**
	 * The Moodle user id for a WordPress user, from the cache or by asking Moodle.
	 *
	 * @param int  $wp_user_id WordPress user ID.
	 * @param bool $refresh    Ignore the cached value and look the user up again.
	 * @return int|null The Moodle user id, or null when there is no match.
	 */
	public static function moodle_user_id( int $wp_user_id, bool $refresh = false ): ?int {
		return self::lookup( $wp_user_id, $refresh )['id'];
	}

	/**
	 * Look up the Moodle user for a WordPress user, with the outcome.
	 *
	 * The outcome distinguishes a single match, a definitive no-match, an
	 * ambiguous match (several accounts), and a lookup that could not be done
	 * (no key, or a Moodle error) — so a caller can, for example, provision only
	 * on a definitive no-match.
	 *
	 * @param int  $wp_user_id WordPress user ID.
	 * @param bool $refresh    Ignore the cached value and look the user up again.
	 * @return array{outcome:string, id:int|null}
	 */
	public static function lookup( int $wp_user_id, bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_user_meta( $wp_user_id, self::META_MOODLE_ID, true );
			if ( '' !== $cached ) {
				return [
					'outcome' => self::OUTCOME_MATCHED,
					'id'      => (int) $cached,
				];
			}
		}
		return self::lookup_fresh( $wp_user_id );
	}

	/**
	 * Look the user up in Moodle and cache the result.
	 *
	 * Only an unambiguous single match is a reliable identity: Moodle does not
	 * force email or idnumber to be unique, so zero matches or several both count
	 * as no match and clear any stale cached mapping. A transport error, by
	 * contrast, is transient: it is swallowed (the REST client logs it) and leaves
	 * any cached mapping untouched, so matching never throws into a caller.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array{outcome:string, id:int|null}
	 */
	private static function lookup_fresh( int $wp_user_id ): array {
		$error = [
			'outcome' => self::OUTCOME_ERROR,
			'id'      => null,
		];
		$user  = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return $error;
		}
		$match = self::match_target( $user );
		if ( '' === $match['value'] ) {
			return $error;
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
			// Transient failure: leave any cached mapping as it is.
			unset( $e );
			return $error;
		}
		$matches = is_array( $found ) ? $found : [];
		if ( 1 === count( $matches ) && isset( $matches[0]['id'] ) ) {
			$moodle_id = (int) $matches[0]['id'];
			update_user_meta( $wp_user_id, self::META_MOODLE_ID, $moodle_id );
			return [
				'outcome' => self::OUTCOME_MATCHED,
				'id'      => $moodle_id,
			];
		}
		// Moodle answered with no single match: drop any stale mapping and say
		// whether there was none or more than one (an ambiguous identity).
		self::forget( $wp_user_id );
		return [
			'outcome' => count( $matches ) > 1 ? self::OUTCOME_AMBIGUOUS : self::OUTCOME_NONE,
			'id'      => null,
		];
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
