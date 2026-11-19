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
 * Tests for the membership entitlement source.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Source_Membership.
 */
class Test_Moopress_Source_Membership extends Moopress_Test_Case {

	/**
	 * A user in the membership role gets the course bundle.
	 *
	 * @return void
	 */
	public function test_member_in_role_gets_courses(): void {
		Moopress_Settings::update(
			[
				'membership_enabled' => true,
				'membership_role'    => 'subscriber',
				'membership_courses' => [ 101, 202 ],
			]
		);
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertSame( [ 101, 202 ], Moopress_Source_Membership::add( [], $uid ) );
	}

	/**
	 * A user not in the role gets nothing.
	 *
	 * @return void
	 */
	public function test_non_member_gets_nothing(): void {
		Moopress_Settings::update(
			[
				'membership_enabled' => true,
				'membership_role'    => 'subscriber',
				'membership_courses' => [ 101 ],
			]
		);
		$uid = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->assertSame( [], Moopress_Source_Membership::add( [], $uid ) );
	}

	/**
	 * When membership is off, the input is returned unchanged.
	 *
	 * @return void
	 */
	public function test_disabled_returns_input_unchanged(): void {
		Moopress_Settings::update(
			[
				'membership_enabled' => false,
				'membership_role'    => 'subscriber',
				'membership_courses' => [ 101 ],
			]
		);
		$uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertSame( [ 5 ], Moopress_Source_Membership::add( [ 5 ], $uid ) );
	}
}
