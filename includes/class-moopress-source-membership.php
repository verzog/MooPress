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
 * Entitlement source: a current member gets a bundle of Moodle courses.
 *
 * Membership is read from a WordPress role (the role a membership system such as
 * wormiss already assigns to current members). This keeps MooPress free of any
 * direct iMIS dependency, so the organisation can move off iMIS without changing
 * this bridge: whatever maintains the role keeps the entitlement working.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grants the configured course bundle to users in the membership role.
 */
class Moopress_Source_Membership {

	/**
	 * Register the entitlement filter and role-change triggers.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'moopress_entitled_courses', [ __CLASS__, 'add' ], 10, 2 );
		add_action( 'add_user_role', [ __CLASS__, 'on_role_change' ], 10, 1 );
		add_action( 'remove_user_role', [ __CLASS__, 'on_role_change' ], 10, 1 );
		add_action( 'set_user_role', [ __CLASS__, 'on_role_change' ], 10, 1 );
	}

	/**
	 * Add the membership course bundle for a user who is a current member.
	 *
	 * @param array $courses    Course ids gathered so far.
	 * @param int   $wp_user_id WordPress user ID.
	 * @return array
	 */
	public static function add( array $courses, int $wp_user_id ): array {
		if ( ! Moopress_Settings::get( 'membership_enabled' ) ) {
			return $courses;
		}
		$role = (string) Moopress_Settings::get( 'membership_role' );
		if ( '' === $role || ! self::user_has_role( $wp_user_id, $role ) ) {
			return $courses;
		}
		return array_merge( $courses, self::member_courses() );
	}

	/**
	 * The Moodle courses granted to current members.
	 *
	 * @return int[]
	 */
	public static function member_courses(): array {
		$ids = Moopress_Settings::get( 'membership_courses', [] );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/**
	 * Whether a WordPress user has a role.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $role       Role slug.
	 * @return bool
	 */
	public static function user_has_role( int $wp_user_id, string $role ): bool {
		$user = get_userdata( $wp_user_id );
		return $user instanceof WP_User && in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Re-reconcile a user whose roles changed.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return void
	 */
	public static function on_role_change( $wp_user_id ): void {
		if ( Moopress_Settings::get( 'membership_enabled' ) ) {
			Moopress_Entitlement::schedule_reconcile( (int) $wp_user_id );
		}
	}
}
