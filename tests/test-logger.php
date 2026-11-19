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
 * Tests for the audit logger's redaction of credentials.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Exercises Moopress_Logger::redact.
 */
class Test_Moopress_Logger extends Moopress_Test_Case {

	/**
	 * A form-encoded token and provisioning password are redacted; other fields survive.
	 *
	 * @return void
	 */
	public function test_redact_removes_token_and_password_from_form_body(): void {
		$raw   = 'wstoken=SECRET123&wsfunction=core_user_create_users'
			. '&users%5B0%5D%5Bpassword%5D=Pa%24%24w0rd&users%5B0%5D%5Bemail%5D=a%40b.org';
		$clean = Moopress_Logger::redact( $raw );

		$this->assertStringNotContainsString( 'SECRET123', $clean );
		$this->assertStringNotContainsString( 'Pa%24%24w0rd', $clean );
		$this->assertStringContainsString( 'wstoken=[redacted]', $clean );
		$this->assertStringContainsString( 'password%5D=[redacted]', $clean );
		// A non-secret field is left alone.
		$this->assertStringContainsString( 'a%40b.org', $clean );
	}

	/**
	 * A JSON password is redacted while other JSON fields survive.
	 *
	 * @return void
	 */
	public function test_redact_removes_json_password(): void {
		$clean = Moopress_Logger::redact( '{"password":"secretpw","firstname":"Jo"}' );

		$this->assertStringNotContainsString( 'secretpw', $clean );
		$this->assertStringContainsString( '"firstname":"Jo"', $clean );
	}
}
