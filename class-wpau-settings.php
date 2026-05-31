<?php
/**
 * WPAU_Settings file.
 *
 * @package wp-approve-user
 */

/**
 * Settings page, field registration, and sanitization for wp-approve-user.
 *
 * Owns the admin-menu entry, the Settings API registrations, the settings-page
 * render, and the sanitize callback. The plugin's `$options` property still
 * lives on Obenland_Wp_Approve_User because the email/approval flows read it;
 * this class only produces the sanitized array that WP writes back.
 *
 * @since 13
 */
class WPAU_Settings {

	/**
	 * Shared slug for the option name, Settings API group, and menu page.
	 *
	 * Happens to equal the plugin textdomain but is NOT used for translations —
	 * every `__()` / `_x()` call hardcodes the string literal so static analysis
	 * can spot missing textdomain arguments.
	 *
	 * @since 13
	 */
	const SLUG = 'wp-approve-user';

	/**
	 * Slug of the companion "Change From Address" plugin on WordPress.org.
	 *
	 * The settings page surfaces a hint pointing admins at this plugin when they
	 * want to customize the sender of the approval emails — wp-approve-user only
	 * owns the email body, not the From: header.
	 *
	 * @since 14
	 */
	const FROM_ADDRESS_PLUGIN_SLUG = 'change-from-address';

	/**
	 * Plugin file (folder/file) of the companion "Change From Address" plugin.
	 *
	 * @since 14
	 */
	const FROM_ADDRESS_PLUGIN_FILE = 'change-from-address/change-from-address.php';

	/**
	 * User-meta key recording that the From-address hint has been dismissed.
	 *
	 * Per-user so one admin dismissing it doesn't hide it for everyone. Cleared
	 * by uninstall.php alongside the other plugin-owned user meta.
	 *
	 * @since 14
	 */
	const FROM_ADDRESS_HINT_META = 'wp-approve-user-from-address-hint-dismissed';

	/**
	 * Registers menu, Settings API, and page-style hooks.
	 *
	 * @since 13
	 */
	public function register_hooks() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
		}
		add_action( 'admin_init', array( $this, 'register_sections_and_fields' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_from_address_hint' ) );
		add_action( 'admin_print_styles-settings_page_' . self::SLUG, array( $this, 'print_styles' ) );
	}

	/**
	 * Adds the admin menu bubble count and the Approve User settings sub-page.
	 *
	 * @since 13
	 */
	public function register_menu() {
		$plugin = Obenland_Wp_Approve_User::get_instance();

		if ( current_user_can( 'list_users' ) ) {
			global $menu;

			foreach ( $menu as $key => $menu_item ) {
				if ( in_array( 'users.php', $menu_item, true ) ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					$menu[ $key ][0] .= sprintf(
						' <span class="update-plugins count-%1$s"><span class="plugin-count">%1$s</span></span>',
						(int) $plugin->get_pending_count_cached()
					);

					break;
				}
			}
		}

		add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			esc_html__( 'Approve User', 'wp-approve-user' ),
			esc_html__( 'Approve User', 'wp-approve-user' ),
			'promote_users',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the settings, sections, and fields with the Settings API.
	 *
	 * @since 13
	 */
	public function register_sections_and_fields() {
		register_setting(
			self::SLUG,
			'wp-approve-user',
			array( $this, 'sanitize' )
		);

		add_settings_section(
			self::SLUG,
			esc_html__( 'Email contents', 'wp-approve-user' ),
			array( $this, 'section_description_cb' ),
			self::SLUG
		);

		add_settings_field(
			'wp-approve-user[send-approve-email]',
			esc_html__( 'Send Approve Email', 'wp-approve-user' ),
			array( $this, 'checkbox_cb' ),
			self::SLUG,
			self::SLUG,
			array(
				'name'        => 'wpau-send-approve-email',
				'description' => __( 'Send email on approval.', 'wp-approve-user' ),
			)
		);

		add_settings_field(
			'wp-approve-user[approve-email]',
			esc_html__( 'Approve Email', 'wp-approve-user' ),
			array( $this, 'textarea_cb' ),
			self::SLUG,
			self::SLUG,
			array(
				'label_for' => 'wpau-approve-email',
				'name'      => 'wpau-approve-email',
				'setting'   => 'wpau-send-approve-email',
			)
		);

		add_settings_field(
			'wp-approve-user[send-unapprove-email]',
			esc_html__( 'Send Unapprove Email', 'wp-approve-user' ),
			array( $this, 'checkbox_cb' ),
			self::SLUG,
			self::SLUG,
			array(
				'name'        => 'wpau-send-unapprove-email',
				'description' => __( 'Send email on unapproval.', 'wp-approve-user' ),
			)
		);

		add_settings_field(
			'wp-approve-user[unapprove-email]',
			esc_html__( 'Unapprove Email', 'wp-approve-user' ),
			array( $this, 'textarea_cb' ),
			self::SLUG,
			self::SLUG,
			array(
				'label_for' => 'wpau-unapprove-email',
				'name'      => 'wpau-unapprove-email',
				'setting'   => 'wpau-send-unapprove-email',
			)
		);

		add_settings_section(
			'wpau-auto-approve',
			esc_html__( 'Auto-approval rules', 'wp-approve-user' ),
			array( $this, 'auto_approve_section_description_cb' ),
			self::SLUG
		);

		add_settings_field(
			'wp-approve-user[auto-approve-rules]',
			esc_html__( 'Rules', 'wp-approve-user' ),
			array( $this, 'auto_approve_rules_cb' ),
			self::SLUG,
			'wpau-auto-approve'
		);
	}

	/**
	 * Enqueues settings-page styles + JS on the Approve User settings screen.
	 *
	 * Fires on `admin_print_styles-settings_page_wp-approve-user` — the screen
	 * hook WordPress derives from add_submenu_page() above — so the assets
	 * only load on this one page.
	 *
	 * @since 13
	 */
	public function print_styles() {
		$plugin_data = get_plugin_data( __DIR__ . '/wp-approve-user.php', false, false );
		$suffix      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style(
			self::SLUG,
			plugins_url( "/css/settings-page{$suffix}.css", __FILE__ ),
			array(),
			$plugin_data['Version']
		);

		wp_enqueue_script(
			'wpau-auto-approval-rules',
			plugins_url( "/js/auto-approval-rules{$suffix}.js", __FILE__ ),
			array(),
			$plugin_data['Version'],
			true
		);
	}

	/**
	 * Renders the settings page wrap.
	 *
	 * @since 13
	 */
	public function render_page() {
		$plugin = Obenland_Wp_Approve_User::get_instance();
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Approve User Settings', 'wp-approve-user' ); ?></h2>

			<div id="poststuff">
				<div id="post-body" class="obenland-wp columns-2">
					<div id="post-body-content">
						<form method="post" action="options.php">
							<?php
							settings_fields( self::SLUG );
							do_settings_sections( self::SLUG );
							submit_button();
							?>
						</form>
					</div>
					<div id="postbox-container-1">
						<div id="side-info-column">
							<?php
							$plugin->donate_box();
							$plugin->feed_box();
							?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the description for the email-contents section.
	 *
	 * @since 13
	 */
	public function section_description_cb() {
		$tags = array( 'USERNAME', 'BLOG_TITLE', 'BLOG_URL', 'LOGINLINK', 'RESETLINK' );
		if ( is_multisite() ) {
			$tags[] = 'SITE_NAME';
		}

		printf(
			/* translators: Placeholders. */
			esc_html_x( 'To take advantage of dynamic data, you can use the following placeholders: %s. Username will be the user login in most cases.', 'Placeholders', 'wp-approve-user' ),
			sprintf( '<code>%s</code>', implode( '</code>, <code>', $tags ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		$this->from_address_hint();
	}

	/**
	 * Prints a dismissible hint pointing at the "Change From Address" plugin.
	 *
	 * The plugin lets admins customize the *body* of the approval emails but
	 * always sends them from the site's default address. Admins who want to
	 * change the sender name/address need a companion plugin, so we surface one
	 * here with an inline install/activate action and a dismiss link. The hint
	 * hides itself once the companion plugin is active or the admin dismisses it.
	 *
	 * @since 14
	 */
	public function from_address_hint() {
		if ( ! $this->should_show_from_address_hint() ) {
			return;
		}

		$action      = $this->from_address_plugin_action();
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'wpau_dismiss_from_address_hint', '1' ),
			'wpau_dismiss_from_address_hint'
		);
		?>
		<div class="notice notice-info inline wpau-from-address-hint">
			<p>
				<?php
				printf(
					/* translators: %s: Name of the companion plugin, "Change From Address". */
					esc_html__( 'Approval emails are sent from your site\'s default address. Want them to come from a custom name or address instead? The free %s plugin lets you set the sender for every email WordPress sends.', 'wp-approve-user' ),
					'<strong>' . esc_html__( 'Change From Address', 'wp-approve-user' ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>
			<p>
				<a
					href="<?php echo esc_url( $action['url'] ); ?>"
					class="button button-secondary"
					<?php if ( ! empty( $action['external'] ) ) : ?>
						target="_blank" rel="noopener noreferrer"
					<?php endif; ?>
				>
					<?php echo esc_html( $action['label'] ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link wpau-dismiss-from-address-hint">
					<?php esc_html_e( 'Dismiss', 'wp-approve-user' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the From-address hint should render for the current user.
	 *
	 * @since 14
	 *
	 * @return bool True when the hint is neither dismissed nor redundant.
	 */
	public function should_show_from_address_hint() {
		if ( get_user_meta( get_current_user_id(), self::FROM_ADDRESS_HINT_META, true ) ) {
			return false;
		}

		return ! $this->is_from_address_plugin_active();
	}

	/**
	 * Whether the companion "Change From Address" plugin is active.
	 *
	 * @since 14
	 *
	 * @return bool
	 */
	protected function is_from_address_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::FROM_ADDRESS_PLUGIN_FILE );
	}

	/**
	 * Builds the primary action link for the From-address hint.
	 *
	 * Prefers the action the current user can actually take: activate the
	 * companion plugin if it's installed, install it from WordPress.org if not,
	 * and otherwise fall back to the plugin's WordPress.org page (e.g. for users
	 * without install/activate capabilities, or on locked-down installs).
	 *
	 * @since 14
	 *
	 * @return array{url:string,label:string,external?:bool} Action descriptor.
	 */
	public function from_address_plugin_action() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_key_exists( self::FROM_ADDRESS_PLUGIN_FILE, get_plugins() );

		if ( $installed && current_user_can( 'activate_plugins' ) ) {
			return array(
				'url'   => wp_nonce_url(
					self_admin_url( 'plugins.php?action=activate&plugin=' . self::FROM_ADDRESS_PLUGIN_FILE ),
					'activate-plugin_' . self::FROM_ADDRESS_PLUGIN_FILE
				),
				'label' => __( 'Activate Change From Address', 'wp-approve-user' ),
			);
		}

		if ( ! $installed && current_user_can( 'install_plugins' ) ) {
			return array(
				'url'   => wp_nonce_url(
					self_admin_url( 'update.php?action=install-plugin&plugin=' . self::FROM_ADDRESS_PLUGIN_SLUG ),
					'install-plugin_' . self::FROM_ADDRESS_PLUGIN_SLUG
				),
				'label' => __( 'Install Change From Address', 'wp-approve-user' ),
			);
		}

		return array(
			'url'      => 'https://wordpress.org/plugins/' . self::FROM_ADDRESS_PLUGIN_SLUG . '/',
			'label'    => __( 'Get Change From Address', 'wp-approve-user' ),
			'external' => true,
		);
	}

	/**
	 * Handles the nonced dismiss link for the From-address hint.
	 *
	 * Fires on admin_init so the dismissal survives the redirect back to a clean
	 * settings URL. Verifies the nonce, records the dismissal, then redirects.
	 *
	 * @since 14
	 */
	public function maybe_dismiss_from_address_hint() {
		if ( empty( $_GET['wpau_dismiss_from_address_hint'] ) ) {
			return;
		}

		check_admin_referer( 'wpau_dismiss_from_address_hint' );

		$this->dismiss_from_address_hint();

		wp_safe_redirect( remove_query_arg( array( 'wpau_dismiss_from_address_hint', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Records that the current user dismissed the From-address hint.
	 *
	 * Split out from the request handler so it can be exercised directly without
	 * the nonce/redirect plumbing.
	 *
	 * @since 14
	 */
	public function dismiss_from_address_hint() {
		update_user_meta( get_current_user_id(), self::FROM_ADDRESS_HINT_META, 1 );
	}

	/**
	 * Prints the description for the auto-approval rules section.
	 *
	 * @since 13
	 */
	public function auto_approve_section_description_cb() {
		echo '<p>';
		esc_html_e(
			'Matching new registrations are approved automatically instead of waiting for admin review. A registration is auto-approved if any rule matches.',
			'wp-approve-user'
		);
		echo '</p>';
	}

	/**
	 * Renders a checkbox field.
	 *
	 * @since 13
	 *
	 * @param array $option Field metadata (`name`, `description`).
	 */
	public function checkbox_cb( $option ) {
		$option  = (object) $option;
		$options = Obenland_Wp_Approve_User::get_instance()->get_options();
		?>
		<label for="<?php echo esc_attr( sanitize_title_with_dashes( $option->name ) ); ?>">
			<input type="checkbox" name="wp-approve-user[<?php echo esc_attr( $option->name ); ?>]" id="<?php echo esc_attr( sanitize_title_with_dashes( $option->name ) ); ?>" value="1" <?php checked( $options[ $option->name ] ); ?> />
			<?php echo esc_html( $option->description ); ?>
		</label><br />
		<?php
	}

	/**
	 * Renders a textarea field.
	 *
	 * @since 13
	 *
	 * @param array $option Field metadata (`name`).
	 */
	public function textarea_cb( $option ) {
		$option  = (object) $option;
		$options = Obenland_Wp_Approve_User::get_instance()->get_options();
		?>
		<textarea id="<?php echo esc_attr( sanitize_title_with_dashes( $option->name ) ); ?>" class="large-text code" name="wp-approve-user[<?php echo esc_attr( $option->name ); ?>]" rows="10" cols="50" ><?php echo esc_textarea( $options[ $option->name ] ); ?></textarea>
		<?php
	}

	/**
	 * Renders the repeatable list of auto-approval rule rows.
	 *
	 * The list always includes one blank row so an admin without JavaScript
	 * can still add a rule by filling it in and clicking "Add rule".
	 *
	 * @since 13
	 */
	public function auto_approve_rules_cb() {
		$options = Obenland_Wp_Approve_User::get_instance()->get_options();
		$rules   = isset( $options['auto_approve_rules'] ) && is_array( $options['auto_approve_rules'] )
			? $options['auto_approve_rules']
			: array();

		$display_rules   = $rules;
		$display_rules[] = array(
			'type'  => 'email_domain',
			'value' => '',
		);

		?>
		<div class="wpau-auto-approve-rules">
			<ul class="wpau-auto-approve-rules-list">
				<?php foreach ( $display_rules as $index => $rule ) : ?>
					<?php $this->render_auto_approve_rule_row( $index, $rule ); ?>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="submit" class="button" name="wpau_auto_approve_add_row" value="1">
					<?php esc_html_e( 'Add rule', 'wp-approve-user' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders a single auto-approval rule row.
	 *
	 * @since 13
	 *
	 * @param int   $index Row index, used to scope form field names.
	 * @param array $rule  Stored rule data (expects `type` and `value` keys).
	 */
	protected function render_auto_approve_rule_row( $index, $rule ) {
		$types        = $this->auto_approve_rule_types();
		$placeholders = $this->auto_approve_rule_placeholders();
		$type         = isset( $rule['type'] ) && isset( $types[ $rule['type'] ] ) ? $rule['type'] : 'email_domain';
		$value        = isset( $rule['value'] ) ? $rule['value'] : '';
		$placeholder  = isset( $placeholders[ $type ] ) ? $placeholders[ $type ] : '';

		$name_type  = sprintf( 'wp-approve-user[auto_approve_rules][%d][type]', (int) $index );
		$name_value = sprintf( 'wp-approve-user[auto_approve_rules][%d][value]', (int) $index );
		?>
		<li class="wpau-auto-approve-rule">
			<label class="screen-reader-text" for="wpau-auto-approve-rule-type-<?php echo esc_attr( (int) $index ); ?>">
				<?php esc_html_e( 'Rule type', 'wp-approve-user' ); ?>
			</label>
			<select
				id="wpau-auto-approve-rule-type-<?php echo esc_attr( (int) $index ); ?>"
				name="<?php echo esc_attr( $name_type ); ?>"
				class="wpau-auto-approve-rule-type"
			>
				<?php foreach ( $types as $type_key => $type_label ) : ?>
					<option
						value="<?php echo esc_attr( $type_key ); ?>"
						data-placeholder="<?php echo esc_attr( isset( $placeholders[ $type_key ] ) ? $placeholders[ $type_key ] : '' ); ?>"
						<?php selected( $type_key, $type ); ?>
					>
						<?php echo esc_html( $type_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="wpau-auto-approve-rule-value-<?php echo esc_attr( (int) $index ); ?>">
				<?php esc_html_e( 'Rule value', 'wp-approve-user' ); ?>
			</label>
			<input
				type="text"
				class="regular-text wpau-auto-approve-rule-value"
				id="wpau-auto-approve-rule-value-<?php echo esc_attr( (int) $index ); ?>"
				name="<?php echo esc_attr( $name_value ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			/>

			<button type="button" class="button-link wpau-remove-auto-approve-rule">
				<?php esc_html_e( 'Remove', 'wp-approve-user' ); ?>
			</button>
		</li>
		<?php
	}

	/**
	 * Returns the list of supported auto-approval rule types.
	 *
	 * Keys are machine-readable identifiers stored in the option; values are
	 * the human-readable labels shown in the settings UI. Kept as a method so
	 * future releases can register more rule types without changing storage.
	 *
	 * @since 13
	 *
	 * @return array<string, string>
	 */
	public function auto_approve_rule_types() {
		return array(
			'email_domain' => __( 'Email domain', 'wp-approve-user' ),
			'email_suffix' => __( 'Email ends with', 'wp-approve-user' ),
			'ip_range'     => __( 'IP address or range', 'wp-approve-user' ),
		);
	}

	/**
	 * Returns the input placeholder for each rule type.
	 *
	 * Keeps the UI-only copy next to the type list so render_auto_approve_rule_row()
	 * can swap the placeholder text as the admin changes the dropdown.
	 *
	 * @since 13
	 *
	 * @return array<string, string>
	 */
	public function auto_approve_rule_placeholders() {
		return array(
			'email_domain' => __( 'example.com', 'wp-approve-user' ),
			'email_suffix' => __( '.edu', 'wp-approve-user' ),
			'ip_range'     => __( '192.168.1.0/24', 'wp-approve-user' ),
		);
	}

	/**
	 * Sanitizes the settings input.
	 *
	 * @since 13
	 *
	 * @param mixed $input Form input; expected to be an array, but WP may pass
	 *                     malformed values on corrupt submissions.
	 * @return array The sanitized settings.
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		return array(
			'wpau-send-approve-email'   => isset( $input['wpau-send-approve-email'] ),
			'wpau-send-unapprove-email' => isset( $input['wpau-send-unapprove-email'] ),
			'wpau-approve-email'        => isset( $input['wpau-approve-email'] ) ? trim( $input['wpau-approve-email'] ) : '',
			'wpau-unapprove-email'      => isset( $input['wpau-unapprove-email'] ) ? trim( $input['wpau-unapprove-email'] ) : '',
			'auto_approve_rules'        => $this->sanitize_auto_approve_rules(
				isset( $input['auto_approve_rules'] ) ? $input['auto_approve_rules'] : array()
			),
		);
	}

	/**
	 * Sanitizes auto-approval rules.
	 *
	 * Empty rows are dropped silently. Rows whose values cannot be validated
	 * for the given rule type are dropped with a `settings_error` notice so
	 * the admin knows why the rule didn't make it through.
	 *
	 * @since 13
	 *
	 * @param  mixed $rules Raw rules submitted from the settings form.
	 * @return array Validated list of rules in the canonical storage shape.
	 */
	public function sanitize_auto_approve_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$sanitized      = array();
		$allowed_types  = array_keys( $this->auto_approve_rule_types() );
		$invalid_values = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$type  = isset( $rule['type'] ) ? sanitize_key( $rule['type'] ) : '';
			$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';
			$value = trim( $value );

			if ( '' === $value ) {
				continue;
			}

			if ( ! in_array( $type, $allowed_types, true ) ) {
				$invalid_values[] = $value;
				continue;
			}

			$normalized = $this->sanitize_rule_value( $type, $value );
			if ( '' === $normalized ) {
				$invalid_values[] = $value;
				continue;
			}

			$sanitized[] = array(
				'type'  => $type,
				'value' => $normalized,
			);
		}

		if ( ! empty( $invalid_values ) ) {
			add_settings_error(
				self::SLUG,
				'wpau_auto_approve_invalid',
				sprintf(
					/* translators: %s: Comma-separated list of rejected rule values. */
					esc_html__( 'The following auto-approval rules were ignored because they are not valid: %s', 'wp-approve-user' ),
					esc_html( implode( ', ', $invalid_values ) )
				),
				'error'
			);
		}

		return $sanitized;
	}

	/**
	 * Dispatches to the per-type sanitizer for a single rule value.
	 *
	 * Returns an empty string when the value doesn't validate for the given
	 * type, so callers can treat that as the "reject" signal without caring
	 * which sanitizer ran.
	 *
	 * @since 13
	 * @access protected
	 *
	 * @param string $type  Rule type (already whitelisted by the caller).
	 * @param string $value Raw rule value.
	 * @return string Normalized value, or empty string when the value is invalid.
	 */
	protected function sanitize_rule_value( $type, $value ) {
		switch ( $type ) {
			case 'email_domain':
				return Obenland_Wp_Approve_User::sanitize_email_domain( $value );
			case 'email_suffix':
				return Obenland_Wp_Approve_User::sanitize_email_suffix( $value );
			case 'ip_range':
				return Obenland_Wp_Approve_User::sanitize_ip_range( $value );
		}

		return '';
	}
}
