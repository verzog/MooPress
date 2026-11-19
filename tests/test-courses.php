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
 * Tests for the catalogue and my-courses shortcodes.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Courses against the fake Moodle.
 */
class Test_Moopress_Courses extends Moopress_Test_Case {

	/**
	 * The catalogue lists only visible courses, excluding the site course.
	 *
	 * @return void
	 */
	public function test_catalogue_lists_visible_courses_only(): void {
		$this->moodle->set(
			'core_course_get_courses_by_field',
			[
				'courses' => [
					[
						'id'       => 1,
						'fullname' => 'Site',
						'visible'  => 1,
					],
					[
						'id'       => 2,
						'fullname' => 'Intro',
						'visible'  => 1,
					],
					[
						'id'       => 3,
						'fullname' => 'Hidden',
						'visible'  => 0,
					],
				],
			]
		);
		$courses = Moopress_Courses::courses();
		$this->assertCount( 1, $courses );
		$this->assertSame( 2, (int) $courses[0]['id'] );
	}

	/**
	 * The catalogue shortcode renders a course name and a link to Moodle.
	 *
	 * @return void
	 */
	public function test_catalogue_shortcode_renders_name_and_link(): void {
		$this->moodle->set(
			'core_course_get_courses_by_field',
			[
				'courses' => [
					[
						'id'       => 2,
						'fullname' => 'Intro',
						'visible'  => 1,
					],
				],
			]
		);
		$html = Moopress_Courses::catalogue_shortcode( [] );
		$this->assertStringContainsString( 'Intro', $html );
		$this->assertStringContainsString( 'course/view.php?id=2', $html );
	}

	/**
	 * My courses returns the matched member's enrolled courses.
	 *
	 * @return void
	 */
	public function test_my_courses_returns_enrolled_courses(): void {
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		$this->moodle->set(
			'core_enrol_get_users_courses',
			[
				[
					'id'       => 2,
					'fullname' => 'Intro',
					'progress' => 50,
				],
			]
		);
		$courses = Moopress_Courses::user_courses( $uid );
		$this->assertCount( 1, $courses );
		$this->assertSame( 'Intro', $courses[0]['fullname'] );
	}

	/**
	 * A user with no Moodle match has no courses.
	 *
	 * @return void
	 */
	public function test_my_courses_empty_without_a_match(): void {
		$uid = self::factory()->user->create( [ 'user_email' => 'nomatch@example.org' ] );
		$this->moodle->set( 'core_user_get_users_by_field', [] );
		$this->assertSame( [], Moopress_Courses::user_courses( $uid ) );
	}

	/**
	 * Signed out, the my-courses shortcode shows a sign-in prompt.
	 *
	 * @return void
	 */
	public function test_my_courses_shortcode_prompts_sign_in_when_logged_out(): void {
		wp_set_current_user( 0 );
		$html = Moopress_Courses::my_courses_shortcode( [] );
		$this->assertStringContainsString( 'Sign in', $html );
	}

	/**
	 * Signed in, the my-courses shortcode shows the course and its progress.
	 *
	 * @return void
	 */
	public function test_my_courses_shortcode_shows_progress(): void {
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		wp_set_current_user( $uid );
		$this->moodle->set(
			'core_enrol_get_users_courses',
			[
				[
					'id'       => 2,
					'fullname' => 'Intro',
					'progress' => 50,
				],
			]
		);
		$html = Moopress_Courses::my_courses_shortcode( [] );
		$this->assertStringContainsString( 'Intro', $html );
		$this->assertStringContainsString( '50% complete', $html );
		$this->assertStringContainsString( 'width:50%', $html );
	}
}
