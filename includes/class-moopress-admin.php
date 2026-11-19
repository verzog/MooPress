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
 * Admin settings page, with a Moodle connection test.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the MooPress admin menu, settings and the connection test.
 */
class Moopress_Admin {

	/** The admin page slug. */
	const MENU_SLUG = 'moopress';

	/** The settings group for the Settings API. */
	const OPTION_GROUP = 'moopress_group';

	/** The admin-post action for the connection test. */
	const TEST_ACTION = 'moopress_test_connection';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_' . self::TEST_ACTION, [ __CLASS__, 'handle_test' ] );
	}

	/**
	 * Add the top-level MooPress menu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'MooPress', 'moopress' ),
			__( 'MooPress', 'moopress' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render' ],
			'dashicons-welcome-learn-more'
		);
	}

	/**
	 * Register the settings option and its sanitiser.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Moopress_Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'default'           => Moopress_Settings::defaults(),
			]
		);
	}

	/**
	 * Sanitise submitted settings, keeping the saved token when the field is left blank.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = Moopress_Settings::all();

		$clean['base_url'] = esc_url_raw( trim( (string) ( $input['base_url'] ?? '' ) ) );

		$token = trim( (string) ( $input['token'] ?? '' ) );
		if ( '' !== $token ) {
			$clean['token'] = sanitize_text_field( $token );
		}

		$clean['dry_run']            = ! empty( $input['dry_run'] );
		$clean['log_enabled']        = ! empty( $input['log_enabled'] );
		$clean['log_capture']        = ! empty( $input['log_capture'] );
		$clean['log_retention_days'] = max( 1, (int) ( $input['log_retention_days'] ?? 30 ) );

		$by                = (string) ( $input['match_by'] ?? 'email' );
		$clean['match_by'] = in_array( $by, [ 'email', 'idnumber', 'username' ], true ) ? $by : 'email';
		// Keep only characters valid in a user-meta key, but preserve case: an SSO
		// field such as "iMIS_ID" must not be lowercased, or match_target() would
		// read a different, empty key.
		$meta_key                = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( (string) ( $input['match_meta_key'] ?? '' ) ) );
		$clean['match_meta_key'] = '' !== $meta_key ? $meta_key : 'moopress_idnumber';

		$clean['enrol_enabled']   = ! empty( $input['enrol_enabled'] );
		$clean['student_roleid']  = max( 1, (int) ( $input['student_roleid'] ?? 5 ) );
		$clean['provision_users'] = ! empty( $input['provision_users'] );
		$auth                     = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $input['provision_auth'] ?? 'manual' ) ) );
		$clean['provision_auth']  = '' !== $auth ? $auth : 'manual';

		add_settings_error( Moopress_Settings::OPTION, 'moopress_saved', __( 'Settings saved.', 'moopress' ), 'success' );
		return $clean;
	}

	/**
	 * Try to reach Moodle and read its site information.
	 *
	 * @return array {ok:bool, sitename?:string, release?:string, message?:string}
	 */
	public static function run_connection_test(): array {
		try {
			$info = ( new Moopress_Rest_Client() )->get_site_info();
			return [
				'ok'       => true,
				'sitename' => isset( $info['sitename'] ) ? (string) $info['sitename'] : '',
				'release'  => isset( $info['release'] ) ? (string) $info['release'] : '',
			];
		} catch ( Moopress_Exception $e ) {
			return [
				'ok'      => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Handle the Test connection button: run the test and redirect back with the result.
	 *
	 * @return void
	 */
	public static function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'moopress' ) );
		}
		check_admin_referer( self::TEST_ACTION );
		set_transient( self::result_key(), self::run_connection_test(), MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The per-user transient key that carries a test result across the redirect.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'moopress_test_' . get_current_user_id();
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opt        = Moopress_Settings::OPTION;
		$settings   = Moopress_Settings::all();
		$configured = Moopress_Settings::is_configured();
		$has_token  = '' !== (string) $settings['token'];
		$result     = get_transient( self::result_key() );
		if ( false !== $result ) {
			delete_transient( self::result_key() );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MooPress', 'moopress' ); ?></h1>
			<?php settings_errors( $opt ); ?>
			<?php if ( is_array( $result ) && ! empty( $result['ok'] ) ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						$name    = '' !== $result['sitename'] ? $result['sitename'] : __( 'the Moodle site', 'moopress' );
						$release = '' !== $result['release'] ? $result['release'] : '?';
						/* translators: 1: Moodle site name, 2: Moodle release. */
						echo esc_html( sprintf( __( 'Connected to %1$s (Moodle %2$s).', 'moopress' ), $name, $release ) );
						?>
					</p>
				</div>
			<?php elseif ( is_array( $result ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						/* translators: %s: the error reason. */
						echo esc_html( sprintf( __( 'Connection failed: %s', 'moopress' ), (string) ( $result['message'] ?? '' ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="moopress_base_url"><?php echo esc_html__( 'Moodle site URL', 'moopress' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[base_url]" id="moopress_base_url" type="url"
								class="regular-text" value="<?php echo esc_attr( $settings['base_url'] ); ?>"
								placeholder="https://moodle.example.org">
							<p class="description">
								<?php echo esc_html__( 'The address of your Moodle site, without a trailing slash.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="moopress_token"><?php echo esc_html__( 'Web service token', 'moopress' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[token]" id="moopress_token" type="password"
								class="regular-text" value="" autocomplete="new-password">
							<p class="description">
								<?php
								echo $has_token
									? esc_html__( 'A token is saved. Leave blank to keep it, or enter a new one to replace it.', 'moopress' )
									: esc_html__( 'The token for your dedicated Moodle service account.', 'moopress' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Dry run', 'moopress' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[dry_run]" type="checkbox" value="1"
									<?php checked( ! empty( $settings['dry_run'] ) ); ?>>
								<?php echo esc_html__( 'Do not write anything to Moodle; log what would be sent.', 'moopress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Audit log', 'moopress' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[log_enabled]" type="checkbox" value="1"
									<?php checked( ! empty( $settings['log_enabled'] ) ); ?>>
								<?php echo esc_html__( 'Record each call to Moodle (the token is always redacted).', 'moopress' ); ?>
							</label>
							<br>
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[log_capture]" type="checkbox" value="1"
									<?php checked( ! empty( $settings['log_capture'] ) ); ?>>
								<?php echo esc_html__( 'Also keep full request and response bodies (for debugging).', 'moopress' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="moopress_retention"><?php echo esc_html__( 'Keep log entries for', 'moopress' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[log_retention_days]" id="moopress_retention"
								type="number" min="1" class="small-text"
								value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>">
							<?php echo esc_html__( 'days', 'moopress' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="moopress_match_by"><?php echo esc_html__( 'Match users by', 'moopress' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[match_by]" id="moopress_match_by">
								<option value="email" <?php selected( 'email', $settings['match_by'] ); ?>>
									<?php echo esc_html__( 'Email address', 'moopress' ); ?>
								</option>
								<option value="idnumber" <?php selected( 'idnumber', $settings['match_by'] ); ?>>
									<?php echo esc_html__( 'ID number (e.g. iMIS ID)', 'moopress' ); ?>
								</option>
								<option value="username" <?php selected( 'username', $settings['match_by'] ); ?>>
									<?php echo esc_html__( 'Username', 'moopress' ); ?>
								</option>
							</select>
							<p class="description">
								<?php echo esc_html__( 'How a WordPress user is matched to a Moodle user.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="moopress_match_meta_key"><?php echo esc_html__( 'ID number field', 'moopress' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[match_meta_key]" id="moopress_match_meta_key"
								type="text" class="regular-text"
								value="<?php echo esc_attr( $settings['match_meta_key'] ); ?>">
							<p class="description">
								<?php echo esc_html__( 'When matching by ID number: the WordPress user-field key that holds it (e.g. the iMIS ID), matched against Moodle\'s idnumber.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Course enrolment', 'moopress' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[enrol_enabled]" type="checkbox" value="1"
									<?php checked( ! empty( $settings['enrol_enabled'] ) ); ?>>
								<?php echo esc_html__( 'Enrol WooCommerce buyers into the mapped Moodle course when an order is paid (and suspend on refund).', 'moopress' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'A product\'s Moodle course is set on the product edit screen. Nothing is written to Moodle while Dry run is on.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="moopress_student_roleid"><?php echo esc_html__( 'Student role id', 'moopress' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[student_roleid]" id="moopress_student_roleid"
								type="number" min="1" class="small-text"
								value="<?php echo esc_attr( (string) $settings['student_roleid'] ); ?>">
							<p class="description">
								<?php echo esc_html__( 'The Moodle role to enrol buyers with. 5 is the default student role.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Provision users', 'moopress' ); ?></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( $opt ); ?>[provision_users]" type="checkbox" value="1"
									<?php checked( ! empty( $settings['provision_users'] ) ); ?>>
								<?php echo esc_html__( 'Create a Moodle user for a buyer who has none yet.', 'moopress' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'With single sign-on, users are usually created in Moodle on first sign-in; turn this on to enrol buyers before they have signed in.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="moopress_provision_auth"><?php echo esc_html__( 'Provisioned user auth method', 'moopress' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[provision_auth]" id="moopress_provision_auth"
								type="text" class="regular-text"
								value="<?php echo esc_attr( $settings['provision_auth'] ); ?>">
							<p class="description">
								<?php echo esc_html__( 'The Moodle authentication method for a provisioned user, e.g. manual, oidc or saml2.', 'moopress' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Connection', 'moopress' ); ?></h2>
			<p>
				<?php
				echo $configured
					? esc_html__( 'MooPress is configured. Use the button below to check it can reach Moodle.', 'moopress' )
					: esc_html__( 'Enter the Moodle site URL and token above and save before testing the connection.', 'moopress' );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<?php
				submit_button(
					__( 'Test connection', 'moopress' ),
					'secondary',
					'submit',
					false,
					$configured ? [] : [ 'disabled' => 'disabled' ]
				);
				?>
			</form>
		</div>
		<?php
	}
}
