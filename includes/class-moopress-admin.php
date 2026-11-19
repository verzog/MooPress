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
