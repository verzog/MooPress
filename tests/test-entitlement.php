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
 * Tests for the entitlement-reconcile engine.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Entitlement against the fake Moodle, driven by the membership source.
 */
class Test_Moopress_Entitlement extends Moopress_Test_Case {

	/**
	 * Count how many calls to Moodle were for a web-service function.
	 *
	 * @param string $wsfunction Function name.
	 * @return int
	 */
	private function call_count( string $wsfunction ): int {
		$count = 0;
		foreach ( $this->moodle->calls as $call ) {
			if ( $wsfunction === $call['wsfunction'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * A member is enrolled in every course the bundle grants, and they are tracked.
	 *
	 * @return void
	 */
	public function test_reconcile_enrols_member_courses(): void {
		Moopress_Settings::update(
			[
				'dry_run'            => false,
				'enrol_enabled'      => true,
				'membership_enabled' => true,
				'membership_role'    => 'subscriber',
				'membership_courses' => [ 101, 202 ],
			]
		);
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		$this->moodle->set( 'enrol_manual_enrol_users', null );

		Moopress_Entitlement::reconcile_user( $uid );

		$managed = get_user_meta( $uid, Moopress_Entitlement::MANAGED_META, true );
		sort( $managed );
		$this->assertSame( [ 101, 202 ], $managed );
		$this->assertSame( 2, $this->call_count( 'enrol_manual_enrol_users' ) );
	}

	/**
	 * A course no longer entitled is suspended and dropped from the managed list.
	 *
	 * @return void
	 */
	public function test_reconcile_suspends_when_no_longer_entitled(): void {
		Moopress_Settings::update(
			[
				'dry_run'            => false,
				'enrol_enabled'      => true,
				'membership_enabled' => true,
				'membership_role'    => 'subscriber',
				'membership_courses' => [ 101 ],
			]
		);
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		update_user_meta( $uid, Moopress_Entitlement::MANAGED_META, [ 101, 303 ] );
		$this->moodle->set( 'enrol_manual_enrol_users', null );

		Moopress_Entitlement::reconcile_user( $uid );

		$this->assertSame( [ 101 ], get_user_meta( $uid, Moopress_Entitlement::MANAGED_META, true ) );
		$last = end( $this->moodle->calls );
		$this->assertSame( 303, (int) $last['enrolments'][0]['courseid'] );
		$this->assertSame( 1, (int) $last['enrolments'][0]['suspend'] );
	}

	/**
	 * A dry run writes nothing to Moodle and does not change the managed list.
	 *
	 * @return void
	 */
	public function test_dry_run_leaves_managed_unchanged(): void {
		Moopress_Settings::update(
			[
				'enrol_enabled'      => true,
				'membership_enabled' => true,
				'membership_role'    => 'subscriber',
				'membership_courses' => [ 101 ],
			]
		);
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );

		Moopress_Entitlement::reconcile_user( $uid );

		$this->assertSame( '', get_user_meta( $uid, Moopress_Entitlement::MANAGED_META, true ) );
		$this->assertCount( 0, $this->moodle->calls );
	}

	/**
	 * The mass-change guard holds back a sweep that would suspend too many at once.
	 *
	 * @return void
	 */
	public function test_mass_change_guard_holds_back_the_sweep(): void {
		Moopress_Settings::update(
			[
				'dry_run'                => false,
				'enrol_enabled'          => true,
				'membership_enabled'     => false,
				'reconcile_max_suspends' => 1,
			]
		);
		$this->moodle->set( 'enrol_manual_enrol_users', null );
		$uids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$uid = self::factory()->user->create();
			update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 50 + $i );
			update_user_meta( $uid, Moopress_Entitlement::MANAGED_META, [ 500 ] );
			$uids[] = $uid;
		}

		Moopress_Entitlement::reconcile_all();

		$this->assertSame( 0, $this->call_count( 'enrol_manual_enrol_users' ) );
		foreach ( $uids as $uid ) {
			$this->assertSame( [ 500 ], get_user_meta( $uid, Moopress_Entitlement::MANAGED_META, true ) );
		}
	}

	/**
	 * Suspensions are held back when a source (WooCommerce) is unavailable.
	 *
	 * @return void
	 */
	public function test_suspend_held_back_when_source_unavailable(): void {
		// WooCommerce has been seen before but is not active now (not loaded in tests).
		update_option( 'moopress_wc_seen', 1, false );
		Moopress_Settings::update(
			[
				'dry_run'            => false,
				'enrol_enabled'      => true,
				'membership_enabled' => false,
			]
		);
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		update_user_meta( $uid, Moopress_Entitlement::MANAGED_META, [ 303 ] );
		$this->moodle->set( 'enrol_manual_enrol_users', null );

		Moopress_Entitlement::reconcile_user( $uid );

		$this->assertSame( 0, $this->call_count( 'enrol_manual_enrol_users' ) );
		$this->assertSame( [ 303 ], get_user_meta( $uid, Moopress_Entitlement::MANAGED_META, true ) );
	}
}
