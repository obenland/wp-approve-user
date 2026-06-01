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
		add_action( 'load-settings_page_' . self::SLUG, array( $this, 'on_settings_page_load' ) );
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
	 * only load on this one page. The from-address-hint script is enqueued
	 * only when the hint is actually shown.
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

		if ( $this->should_show_from_address_hint() ) {
			wp_enqueue_script(
				'wpau-from-address-hint',
				plugins_url( "/js/from-address-hint{$suffix}.js", __FILE__ ),
				array( 'common' ),
				$plugin_data['Version'],
				true
			);
		}
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
	}

	/**
	 * Settings-page load handler.
	 *
	 * Runs only on the Approve User settings screen (the `load-{$page_hook}`
	 * hook fires there for both single-site and network admin). Processes a
	 * pending dismiss request, then queues the From-address hint onto
	 * `all_admin_notices` so it renders through the standard admin-notices
	 * pipeline — and only on this page, since that's where we registered it.
	 *
	 * @since 14
	 */
	public function on_settings_page_load() {
		$this->maybe_dismiss_from_address_hint();

		add_action( 'all_admin_notices', array( $this, 'from_address_hint' ) );
	}

	/**
	 * Prints a dismissible hint pointing at the "Change From Address" plugin.
	 *
	 * Hooked onto `all_admin_notices` from on_settings_page_load(), so it renders
	 * through the standard admin-notices pipeline at the top of the settings
	 * page. The plugin lets admins customize the *body* of the approval emails
	 * but always sends them from the site's default address; admins who want to
	 * change the sender name/address need a companion plugin, so we surface one
	 * here: the plugin name links to core's plugin-information modal when it
	 * isn't installed, or to a one-click activate link when it is. The hint
	 * hides itself once the companion plugin is active or the admin dismisses
	 * it.
	 *
	 * The notice is marked `dismissible`, so core renders its × button; the
	 * from-address-hint script (enqueued in print_styles()) hides the no-JS
	 * text link and persists the × dismissal via the same nonced URL. Without
	 * JavaScript the text link remains the dismiss affordance.
	 *
	 * @since 14
	 */
	public function from_address_hint() {
		if ( ! $this->should_show_from_address_hint() ) {
			return;
		}

		$action = $this->from_address_plugin_action();

		// The plugin-information modal needs Thickbox + plugin-install; enqueue
		// them here so they load only when the modal link is actually shown.
		if ( $action['modal'] ) {
			add_thickbox();
			wp_enqueue_script( 'plugin-install' );
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'wpau_dismiss_from_address_hint', '1' ),
			'wpau_dismiss_from_address_hint'
		);

		$plugin_link = sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( $action['url'] ),
			$action['modal'] ? ' class="thickbox open-plugin-details-modal"' : '',
			esc_html__( 'Change From Address', 'wp-approve-user' )
		);

		$intro = sprintf(
			/* translators: %s: Linked name of the companion plugin, “Change From Address”. */
			esc_html__( 'To send approval emails from a custom name or address, add the %s plugin.', 'wp-approve-user' ),
			$plugin_link
		);

		$message  = '<p>' . $intro . '</p>';
		$message .= sprintf(
			'<p><a href="%1$s" class="button-link wpau-dismiss-from-address-hint">%2$s</a></p>',
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'wp-approve-user' )
		);

		wp_admin_notice(
			$message,
			array(
				'type'               => 'info',
				'dismissible'        => true,
				'additional_classes' => array( 'wpau-from-address-hint' ),
				'paragraph_wrap'     => false,
			)
		);
	}

	/**
	 * Whether the From-address hint should render for the current user.
	 *
	 * @since 14
	 *
	 * @return bool True when the current user can install plugins and the hint
	 *              is neither dismissed nor redundant.
	 */
	public function should_show_from_address_hint() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return false;
		}

		if ( get_user_meta( get_current_user_id(), 'wp-approve-user-from-address-hint-dismissed', true ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return ! is_plugin_active( 'change-from-address/change-from-address.php' );
	}

	/**
	 * Builds the target for the linked plugin name in the From-address hint.
	 *
	 * The hint only renders for users who can install plugins (see
	 * should_show_from_address_hint()). When the companion plugin is already
	 * installed but inactive, the name links straight to a one-click activate
	 * URL; otherwise it links to core's plugin-information modal (`modal` true),
	 * where the admin can read the details and install it without leaving the
	 * page.
	 *
	 * @since 14
	 *
	 * @return array{url:string,modal:bool} Link descriptor: the URL and whether
	 *                                      it opens the plugin-information modal.
	 */
	public function from_address_plugin_action() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = 'change-from-address/change-from-address.php';

		if ( array_key_exists( $plugin_file, get_plugins() ) ) {
			return array(
				'url'   => wp_nonce_url(
					self_admin_url( 'plugins.php?action=activate&plugin=' . $plugin_file ),
					'activate-plugin_' . $plugin_file
				),
				'modal' => false,
			);
		}

		return array(
			'url'   => self_admin_url(
				'plugin-install.php?tab=plugin-information&plugin=change-from-address&TB_iframe=true&width=600&height=550'
			),
			'modal' => true,
		);
	}

	/**
	 * Handles the nonced dismiss link for the From-address hint.
	 *
	 * Fires on `load-settings_page_wp-approve-user` — the dismiss link points
	 * back at this settings page, so this is the only request that needs to
	 * handle it. Verifies the nonce, records the dismissal, then redirects to a
	 * clean URL. If the meta write fails we skip the redirect and surface an
	 * error notice instead, so a failed dismissal isn't disguised as success.
	 *
	 * @since 14
	 */
	public function maybe_dismiss_from_address_hint() {
		if ( empty( $_GET['wpau_dismiss_from_address_hint'] ) ) {
			return;
		}

		check_admin_referer( 'wpau_dismiss_from_address_hint' );

		if ( ! update_user_meta( get_current_user_id(), 'wp-approve-user-from-address-hint-dismissed', 1 ) ) {
			add_action(
				'all_admin_notices',
				static function () {
					wp_admin_notice(
						esc_html__( 'Could not save your dismissal. Please try again.', 'wp-approve-user' ),
						array( 'type' => 'error' )
					);
				}
			);
			return;
		}

		wp_safe_redirect( remove_query_arg( array( 'wpau_dismiss_from_address_hint', '_wpnonce' ) ) );
		// @codeCoverageIgnoreStart
		exit;
		// @codeCoverageIgnoreEnd
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
