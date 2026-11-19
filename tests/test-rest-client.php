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
 * Tests for the Moodle Web Services REST client.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Rest_Client against the fake Moodle.
 */
class Test_Moopress_Rest_Client extends Moopress_Test_Case {

	/**
	 * A client with no site URL or token refuses to call.
	 *
	 * @return void
	 */
	public function test_unconfigured_client_throws(): void {
		$client = new Moopress_Rest_Client( '', '' );
		$this->assertFalse( $client->is_configured() );
		$this->expectException( Moopress_Exception::class );
		$client->call( 'core_webservice_get_site_info' );
	}

	/**
	 * A successful call returns the decoded Moodle response.
	 *
	 * @return void
	 */
	public function test_get_site_info_returns_site_name(): void {
		$this->moodle->set( 'core_webservice_get_site_info', [ 'sitename' => 'Test Site' ] );
		$client = new Moopress_Rest_Client();
		$info   = $client->get_site_info();
		$this->assertSame( 'Test Site', $info['sitename'] );
	}

	/**
	 * A Moodle web-service fault (HTTP 200 with an exception) throws with its message.
	 *
	 * @return void
	 */
	public function test_moodle_fault_throws_with_message(): void {
		$this->moodle->set_fault(
			'core_webservice_get_site_info',
			'webservice_access_exception',
			'Access to the function is not allowed'
		);
		$client = new Moopress_Rest_Client();
		$this->expectException( Moopress_Exception::class );
		$this->expectExceptionMessage( 'Access to the function is not allowed' );
		$client->get_site_info();
	}

	/**
	 * A WordPress transport error throws.
	 *
	 * @return void
	 */
	public function test_http_error_throws(): void {
		$this->moodle->set_http_error( 'Connection timed out' );
		$client = new Moopress_Rest_Client();
		$this->expectException( Moopress_Exception::class );
		$client->get_site_info();
	}

	/**
	 * The token is redacted before the call is written to the audit log.
	 *
	 * @return void
	 */
	public function test_token_is_redacted_in_the_audit_log(): void {
		$this->moodle->set( 'core_webservice_get_site_info', [ 'sitename' => 'Test Site' ] );
		$client = new Moopress_Rest_Client();
		$client->get_site_info();
		$rows = Moopress_Logger::recent( 5 );
		$this->assertNotEmpty( $rows );
		$this->assertStringContainsString( 'wstoken=[redacted]', $rows[0]['request'] );
		$this->assertStringNotContainsString( 'test-token-abc123', $rows[0]['request'] );
	}

	/**
	 * An HTTP 200 that is not valid JSON (e.g. a maintenance page) throws, not a silent empty result.
	 *
	 * @return void
	 */
	public function test_non_json_response_throws(): void {
		$this->moodle->set_raw( 'core_webservice_get_site_info', '<html><body>Service unavailable</body></html>' );
		$client = new Moopress_Rest_Client();
		$this->expectException( Moopress_Exception::class );
		$client->get_site_info();
	}

	/**
	 * A valid JSON null (a void function's response) is returned, not treated as an error.
	 *
	 * @return void
	 */
	public function test_valid_null_response_is_not_an_error(): void {
		$this->moodle->set( 'enrol_manual_enrol_users', null );
		$client = new Moopress_Rest_Client();
		$this->assertNull( $client->call( 'enrol_manual_enrol_users', [ 'enrolments' => [] ] ) );
	}
}
