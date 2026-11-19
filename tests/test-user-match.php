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
 * Tests for matching a WordPress user to a Moodle user.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_User_Match against the fake Moodle.
 */
class Test_Moopress_User_Match extends Moopress_Test_Case {

	/**
	 * A user is matched by email and the Moodle id is cached.
	 *
	 * @return void
	 */
	public function test_matches_by_email_and_caches(): void {
		$uid = self::factory()->user->create( [ 'user_email' => 'jo@example.org' ] );
		$this->moodle->set(
			'core_user_get_users_by_field',
			[
				[
					'id'    => 42,
					'email' => 'jo@example.org',
				],
			]
		);

		$this->assertSame( 42, Moopress_User_Match::moodle_user_id( $uid ) );
		$this->assertSame( '42', get_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, true ) );

		$last = end( $this->moodle->calls );
		$this->assertSame( 'email', $last['field'] );
		$this->assertSame( [ 'jo@example.org' ], $last['values'] );
	}

	/**
	 * No Moodle user means no id and nothing cached.
	 *
	 * @return void
	 */
	public function test_no_match_returns_null(): void {
		$uid = self::factory()->user->create( [ 'user_email' => 'nobody@example.org' ] );
		$this->moodle->set( 'core_user_get_users_by_field', [] );

		$this->assertNull( Moopress_User_Match::moodle_user_id( $uid ) );
		$this->assertSame( '', get_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, true ) );
	}

	/**
	 * A cached Moodle id is returned without calling Moodle again.
	 *
	 * @return void
	 */
	public function test_cached_value_is_reused(): void {
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 7 );

		$this->assertSame( 7, Moopress_User_Match::moodle_user_id( $uid ) );
		$this->assertCount( 0, $this->moodle->calls );
	}

	/**
	 * Matching by ID number reads the configured user-meta field and queries idnumber.
	 *
	 * @return void
	 */
	public function test_match_by_idnumber_uses_the_meta_field(): void {
		Moopress_Settings::update(
			[
				'match_by'       => 'idnumber',
				'match_meta_key' => 'moopress_idnumber',
			]
		);
		$uid = self::factory()->user->create();
		update_user_meta( $uid, 'moopress_idnumber', 'IMIS123' );
		$this->moodle->set( 'core_user_get_users_by_field', [ [ 'id' => 9 ] ] );

		$this->assertSame( 9, Moopress_User_Match::moodle_user_id( $uid ) );
		$last = end( $this->moodle->calls );
		$this->assertSame( 'idnumber', $last['field'] );
		$this->assertSame( [ 'IMIS123' ], $last['values'] );
	}

	/**
	 * Matching by username queries the WordPress login name.
	 *
	 * @return void
	 */
	public function test_match_by_username_uses_login(): void {
		Moopress_Settings::update( [ 'match_by' => 'username' ] );
		$uid = self::factory()->user->create( [ 'user_login' => 'jo_moodle' ] );
		$this->moodle->set( 'core_user_get_users_by_field', [ [ 'id' => 11 ] ] );

		$this->assertSame( 11, Moopress_User_Match::moodle_user_id( $uid ) );
		$last = end( $this->moodle->calls );
		$this->assertSame( 'username', $last['field'] );
		$this->assertSame( [ 'jo_moodle' ], $last['values'] );
	}
}
