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
 * Tests for the admin settings sanitiser and the connection test.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Admin.
 */
class Test_Moopress_Admin extends Moopress_Test_Case {

	/**
	 * A blank token field keeps the saved token.
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_token_when_blank(): void {
		$clean = Moopress_Admin::sanitize(
			[
				'base_url' => 'https://m.example.org',
				'token'    => '',
			]
		);
		$this->assertSame( 'test-token-abc123', $clean['token'] );
		$this->assertSame( 'https://m.example.org', $clean['base_url'] );
	}

	/**
	 * A new token value replaces the saved one.
	 *
	 * @return void
	 */
	public function test_sanitize_updates_token_when_provided(): void {
		$clean = Moopress_Admin::sanitize( [ 'token' => 'newtoken999' ] );
		$this->assertSame( 'newtoken999', $clean['token'] );
	}

	/**
	 * Checkboxes and the retention number are coerced to safe types.
	 *
	 * @return void
	 */
	public function test_sanitize_casts_types(): void {
		$clean = Moopress_Admin::sanitize(
			[
				'dry_run'            => '1',
				'log_retention_days' => '0',
			]
		);
		$this->assertTrue( $clean['dry_run'] );
		$this->assertFalse( $clean['log_enabled'] );
		$this->assertSame( 1, $clean['log_retention_days'] );
	}

	/**
	 * A reachable Moodle reports success with its site name and release.
	 *
	 * @return void
	 */
	public function test_connection_test_reports_success(): void {
		$this->moodle->set(
			'core_webservice_get_site_info',
			[
				'sitename' => 'My Moodle',
				'release'  => '5.1',
			]
		);
		$result = Moopress_Admin::run_connection_test();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'My Moodle', $result['sitename'] );
		$this->assertSame( '5.1', $result['release'] );
	}

	/**
	 * A Moodle fault is reported as a failed connection with its message.
	 *
	 * @return void
	 */
	public function test_connection_test_reports_failure(): void {
		$this->moodle->set_fault( 'core_webservice_get_site_info', 'invalid_token', 'Invalid token - token not found' );
		$result = Moopress_Admin::run_connection_test();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Invalid token', $result['message'] );
	}
}
