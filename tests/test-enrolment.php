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
 * Tests for enrolling WooCommerce buyers into Moodle courses.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Enrolment against the fake Moodle.
 */
class Test_Moopress_Enrolment extends Moopress_Test_Case {

	/**
	 * Enrolling sends Moodle's manual enrolment with the student role and suspend 0.
	 *
	 * @return void
	 */
	public function test_enrol_sends_manual_enrolment_call(): void {
		Moopress_Settings::update( [ 'dry_run' => false ] );
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		$this->moodle->set( 'enrol_manual_enrol_users', null );

		$this->assertTrue( Moopress_Enrolment::enrol( $uid, 101 ) );
		$last = end( $this->moodle->calls );
		$this->assertSame( 'enrol_manual_enrol_users', $last['wsfunction'] );
		$enrolment = $last['enrolments'][0];
		$this->assertSame( 55, (int) $enrolment['userid'] );
		$this->assertSame( 101, (int) $enrolment['courseid'] );
		$this->assertSame( 5, (int) $enrolment['roleid'] );
		$this->assertSame( 0, (int) $enrolment['suspend'] );
	}

	/**
	 * Suspending sends the same call with suspend 1.
	 *
	 * @return void
	 */
	public function test_suspend_sends_suspend_flag(): void {
		Moopress_Settings::update( [ 'dry_run' => false ] );
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		$this->moodle->set( 'enrol_manual_enrol_users', null );

		$this->assertTrue( Moopress_Enrolment::suspend( $uid, 101 ) );
		$last = end( $this->moodle->calls );
		$this->assertSame( 1, (int) $last['enrolments'][0]['suspend'] );
	}

	/**
	 * A dry run logs the intended enrolment and sends nothing to Moodle.
	 *
	 * @return void
	 */
	public function test_enrol_dry_run_sends_nothing(): void {
		$uid = self::factory()->user->create();
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );

		$this->assertTrue( Moopress_Enrolment::enrol( $uid, 101 ) );
		$this->assertCount( 0, $this->moodle->calls );
		$this->assertStringContainsString( 'Dry run', (string) Moopress_Logger::recent( 1 )[0]['error'] );
	}

	/**
	 * With no Moodle user and provisioning off, enrolment is skipped.
	 *
	 * @return void
	 */
	public function test_enrol_skips_when_no_match_and_no_provision(): void {
		Moopress_Settings::update( [ 'dry_run' => false ] );
		$uid = self::factory()->user->create( [ 'user_email' => 'x@example.org' ] );
		$this->moodle->set( 'core_user_get_users_by_field', [] );

		$this->assertFalse( Moopress_Enrolment::enrol( $uid, 101 ) );
		foreach ( $this->moodle->calls as $call ) {
			$this->assertNotSame( 'enrol_manual_enrol_users', $call['wsfunction'] );
		}
	}

	/**
	 * With provisioning on, an unmatched buyer is created in Moodle, then enrolled.
	 *
	 * @return void
	 */
	public function test_provision_creates_user_then_enrols(): void {
		Moopress_Settings::update(
			[
				'dry_run'         => false,
				'provision_users' => true,
			]
		);
		$uid = self::factory()->user->create(
			[
				'user_email' => 'new@example.org',
				'user_login' => 'newbie',
			]
		);
		$this->moodle->set( 'core_user_get_users_by_field', [] );
		$this->moodle->set( 'core_user_create_users', [ [ 'id' => 77 ] ] );
		$this->moodle->set( 'enrol_manual_enrol_users', null );

		$this->assertTrue( Moopress_Enrolment::enrol( $uid, 101 ) );
		$this->assertSame( '77', get_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, true ) );
		$last = end( $this->moodle->calls );
		$this->assertSame( 'enrol_manual_enrol_users', $last['wsfunction'] );
		$this->assertSame( 77, (int) $last['enrolments'][0]['userid'] );
	}

	/**
	 * An ambiguous match is never provisioned (no duplicate account is created).
	 *
	 * @return void
	 */
	public function test_ambiguous_match_is_not_provisioned(): void {
		Moopress_Settings::update(
			[
				'dry_run'         => false,
				'provision_users' => true,
			]
		);
		$uid = self::factory()->user->create( [ 'user_email' => 'dup@example.org' ] );
		$this->moodle->set( 'core_user_get_users_by_field', [ [ 'id' => 1 ], [ 'id' => 2 ] ] );

		$this->assertFalse( Moopress_Enrolment::enrol( $uid, 101 ) );
		$this->assertSame( '', get_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, true ) );
		foreach ( $this->moodle->calls as $call ) {
			$this->assertNotSame( 'core_user_create_users', $call['wsfunction'] );
		}
	}

	/**
	 * A product's course comes from its meta, falling back to its parent product.
	 *
	 * @return void
	 */
	public function test_course_for_product_falls_back_to_parent(): void {
		$parent = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $parent, Moopress_Enrolment::COURSE_META, 202 );
		$variation = self::factory()->post->create(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $parent,
			]
		);
		$this->assertSame( 202, Moopress_Enrolment::course_for_product( $variation ) );

		$none = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$this->assertSame( 0, Moopress_Enrolment::course_for_product( $none ) );
	}

	/**
	 * An order maps its line items to distinct course ids, skipping unmapped products.
	 *
	 * @return void
	 */
	public function test_course_ids_for_order_maps_and_dedupes(): void {
		$p1 = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $p1, Moopress_Enrolment::COURSE_META, 101 );
		$p2 = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $p2, Moopress_Enrolment::COURSE_META, 102 );
		$unmapped = self::factory()->post->create( [ 'post_type' => 'product' ] );

		$order = new Moopress_Test_Order(
			7,
			[
				new Moopress_Test_Order_Item( $p1 ),
				new Moopress_Test_Order_Item( $p2 ),
				new Moopress_Test_Order_Item( $p1 ),
				new Moopress_Test_Order_Item( $unmapped ),
			]
		);
		$ids   = Moopress_Enrolment::course_ids_for_order( $order );
		sort( $ids );
		$this->assertSame( [ 101, 102 ], $ids );
	}
}
