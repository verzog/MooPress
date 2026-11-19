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
 * Tests for the Privacy API export and erase.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises the exporter and eraser in Moopress_Plugin.
 */
class Test_Moopress_Privacy extends Moopress_Test_Case {

	/**
	 * Record one logged Moodle call attributed to a user.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return void
	 */
	private function log_a_call( int $wp_user_id ): void {
		wp_set_current_user( $wp_user_id );
		$this->moodle->set( 'core_webservice_get_site_info', [ 'sitename' => 'X' ] );
		( new Moopress_Rest_Client() )->get_site_info();
	}

	/**
	 * Export returns the user's Moodle link and audit-log rows.
	 *
	 * @return void
	 */
	public function test_export_includes_link_managed_and_log(): void {
		$uid = self::factory()->user->create( [ 'user_email' => 'jo@example.org' ] );
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		update_user_meta( $uid, Moopress_Entitlement::MANAGED_META, [ 101 ] );
		$this->log_a_call( $uid );

		$result = Moopress_Plugin::export_personal_data( 'jo@example.org' );
		$this->assertTrue( $result['done'] );
		$json = (string) wp_json_encode( $result['data'] );
		$this->assertStringContainsString( 'moopress-link', $json );
		$this->assertStringContainsString( 'moopress-log', $json );
		// The managed course list and the captured (redacted) request body are included.
		$this->assertStringContainsString( '101', $json );
		$this->assertStringContainsString( 'Request body', $json );
	}

	/**
	 * Export for an unknown email returns nothing.
	 *
	 * @return void
	 */
	public function test_export_unknown_email_is_empty(): void {
		$result = Moopress_Plugin::export_personal_data( 'nobody@example.org' );
		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Erase removes the log rows, the Moodle link and the managed-course list.
	 *
	 * @return void
	 */
	public function test_erase_removes_log_but_retains_operational_state(): void {
		$uid = self::factory()->user->create( [ 'user_email' => 'jo@example.org' ] );
		update_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, 55 );
		update_user_meta( $uid, Moopress_Entitlement::MANAGED_META, [ 101 ] );
		$this->log_a_call( $uid );

		$result = Moopress_Plugin::erase_personal_data( 'jo@example.org' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		// The audit log is erased; the Moodle link and managed list are kept so
		// reconciliation can still suspend the person's enrolments when they lapse.
		$this->assertEmpty( Moopress_Logger::for_person( $uid ) );
		$this->assertSame( '55', get_user_meta( $uid, Moopress_User_Match::META_MOODLE_ID, true ) );
		$this->assertSame( [ 101 ], get_user_meta( $uid, Moopress_Entitlement::MANAGED_META, true ) );
	}
}
