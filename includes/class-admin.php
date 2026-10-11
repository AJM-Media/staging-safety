<?php

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

class Admin {
	const PAGE           = 'staging-safety';
	const DONATE_META    = 'stsf_donate_dismissed';
	const SETUP_META     = 'stsf_setup_dismissed';
	const HIDDEN_META    = 'stsf_hidden_dismissed';
	const HIDDEN_SNOOZE  = WEEK_IN_SECONDS;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_stsf_confirm_live', array( __CLASS__, 'handle_confirm_live' ) );
		add_action( 'admin_post_stsf_clear_log', array( __CLASS__, 'handle_clear_log' ) );
		add_action( 'admin_post_stsf_dismiss', array( __CLASS__, 'handle_dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( STSF_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	public static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_options_page(
			__( 'Staging Safety', 'staging-safety' ),
			__( 'Staging Safety', 'staging-safety' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'stsf',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function enqueue_page( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'stsf-admin', STSF_URL . 'assets/admin.css', array(), STSF_VERSION );
		}
	}

	private static function action_url( $action, $extra = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $extra ), admin_url( 'admin-post.php' ) ), $action );
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user = get_current_user_id();

		if ( Search_Guard::production_hidden() && (int) get_user_meta( $user, self::HIDDEN_META, true ) < time() - self::HIDDEN_SNOOZE ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Staging Safety: your production site is hidden from search engines.', 'staging-safety' ); ?></strong>
					<?php esc_html_e( '"Discourage search engines from indexing this site" is ticked in Settings > Reading, often after a staging database was copied to live.', 'staging-safety' ); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>"><?php esc_html_e( 'Open Reading settings', 'staging-safety' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'stsf_dismiss', array( 'notice' => 'hidden' ) ) ); ?>"><?php esc_html_e( 'Remind me in a week', 'staging-safety' ); ?></a>
				</p>
			</div>
			<?php
		}

		if ( Environment::is_confirmed() || get_user_meta( $user, self::SETUP_META, true ) ) {
			return;
		}

		$guess = 'guess' === Environment::source();
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Staging Safety needs one setting: your live site\'s address.', 'staging-safety' ); ?></strong>
				<?php
				if ( $guess ) {
					printf(
						/* translators: %s: environment label, e.g. Staging */
						esc_html__( 'This site looks like a %s copy. Add your live site\'s address so it can tell copies from the live site for sure.', 'staging-safety' ),
						esc_html( Environment::label() )
					);
				} else {
					esc_html_e( 'Until it\'s set, this site is treated as live and nothing is blocked.', 'staging-safety' );
				}
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'It\'s a copy: enter the live address', 'staging-safety' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'stsf_confirm_live' ) ); ?>"><?php esc_html_e( 'This is the live site', 'staging-safety' ); ?></a>
				<a href="<?php echo esc_url( self::action_url( 'stsf_dismiss', array( 'notice' => 'setup' ) ) ); ?>"><?php esc_html_e( 'Dismiss', 'staging-safety' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$locked   = Settings::url_from_constant();
		$name     = Settings::OPTION;
		$replaced = Mail_Guard::replaced_by();
		$log      = Mail_Guard::entries();
		$guarded  = Environment::guarded();
		?>
		<div class="wrap stsf">
			<h1><?php esc_html_e( 'Staging Safety', 'staging-safety' ); ?></h1>

			<div class="stsf-current stsf-current-<?php echo esc_attr( Environment::type() ); ?>">
				<p class="stsf-current-type">
					<?php
					printf(
						/* translators: %s: environment label */
						esc_html__( 'This site: %s', 'staging-safety' ),
						'<strong>' . esc_html( Environment::label() ) . '</strong>'
					);
					?>
				</p>
				<p><?php echo esc_html( Environment::reason() ); ?></p>
				<ul>
					<?php foreach ( Badge::status_lines() as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php if ( $replaced && 'off' !== $settings['mail_mode'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: plugin folder or file name */
							esc_html__( 'Another plugin (%s) has replaced WordPress\'s email function, so Staging Safety can\'t hold or reroute emails. Use that plugin\'s own test or disable-sending setting on copies of this site.', 'staging-safety' ),
							'<code>' . esc_html( $replaced ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'stsf' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="stsf-production-url"><?php esc_html_e( 'Production site address', 'staging-safety' ); ?></label></th>
						<td>
							<input type="url" id="stsf-production-url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[production_url]" value="<?php echo esc_attr( $settings['production_url'] ); ?>" placeholder="https://example.com" <?php disabled( $locked ); ?>>
							<?php if ( $locked ) : ?>
								<p class="description"><?php esc_html_e( 'Set by STAGING_SAFETY_PRODUCTION_URL in wp-config.php.', 'staging-safety' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Your live site. Any copy of this database running at a different address is treated as a copy, and the protections below turn on there. Only the domain is used.', 'staging-safety' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stsf-label"><?php esc_html_e( 'Badge text', 'staging-safety' ); ?></label></th>
						<td>
							<input type="text" id="stsf-label" class="regular-text" maxlength="40" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $settings['label'] ); ?>" placeholder="<?php echo esc_attr( Environment::default_label() ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Replaces the admin bar text on copies of the site, for example "Client review". The live site always shows "Production".', 'staging-safety' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emails on copies', 'staging-safety' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mail_mode]" value="hold" <?php checked( $settings['mail_mode'], 'hold' ); ?>> <?php esc_html_e( 'Hold them: log but don\'t send (recommended)', 'staging-safety' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mail_mode]" value="reroute" <?php checked( $settings['mail_mode'], 'reroute' ); ?>> <?php esc_html_e( 'Send them all to one address instead:', 'staging-safety' ); ?></label>
								<input type="email" class="regular-text" aria-label="<?php esc_attr_e( 'Reroute address', 'staging-safety' ); ?>" name="<?php echo esc_attr( $name ); ?>[reroute_to]" value="<?php echo esc_attr( $settings['reroute_to'] ); ?>" placeholder="you@example.com"><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mail_mode]" value="off" <?php checked( $settings['mail_mode'], 'off' ); ?>> <?php esc_html_e( 'Send them normally', 'staging-safety' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Stops copies emailing real customers: order emails, form notifications, password resets. The live site always sends normally.', 'staging-safety' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search engines on copies', 'staging-safety' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[noindex]" value="1" <?php checked( $settings['noindex'] ); ?>>
								<?php esc_html_e( 'Keep copies out of search results', 'staging-safety' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Adds noindex to every page and turns off the sitemap on copies. It doesn\'t change Settings > Reading, so nothing is carried over to the live site when you push a copy live.', 'staging-safety' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Live site check', 'staging-safety' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[warn_production]" value="1" <?php checked( $settings['warn_production'] ); ?>>
								<?php esc_html_e( 'Warn me if the live site is set to discourage search engines', 'staging-safety' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Email log', 'staging-safety' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php echo esc_html( $guarded ? __( 'No emails held or rerouted yet.', 'staging-safety' ) : __( 'Emails are only logged on copies of the site.', 'staging-safety' ) ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %d: maximum number of log entries */
						esc_html__( 'The last %d emails held or rerouted on this copy. Only the recipient and subject are kept.', 'staging-safety' ),
						(int) Mail_Guard::MAX
					);
					?>
				</p>
				<table class="widefat striped stsf-log">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'staging-safety' ); ?></th>
							<th scope="col"><?php esc_html_e( 'To', 'staging-safety' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subject', 'staging-safety' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'staging-safety' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( $entry['to'] ); ?></td>
								<td><?php echo esc_html( $entry['subject'] ); ?></td>
								<td><?php echo esc_html( 'rerouted' === $entry['action'] ? __( 'Rerouted', 'staging-safety' ) : __( 'Held', 'staging-safety' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><a class="button" href="<?php echo esc_url( self::action_url( 'stsf_clear_log' ) ); ?>"><?php esc_html_e( 'Clear log', 'staging-safety' ); ?></a></p>
			<?php endif; ?>

			<?php self::render_donate(); ?>
		</div>
		<?php
	}

	private static function render_donate() {
		if ( get_user_meta( get_current_user_id(), self::DONATE_META, true ) ) {
			return;
		}
		?>
		<div class="stsf-donate">
			<p><?php esc_html_e( 'Saved a customer from a test email? This plugin is free. If it helps, you can buy me a coffee.', 'staging-safety' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( STSF_DONATE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy me a coffee', 'staging-safety' ); ?></a>
				<a class="stsf-dismiss" href="<?php echo esc_url( self::action_url( 'stsf_dismiss', array( 'notice' => 'donate' ) ) ); ?>"><?php esc_html_e( 'Hide this', 'staging-safety' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'staging-safety' ) );
		}
		check_admin_referer( $action );
	}

	private static function back( $fallback ) {
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : $fallback );
		exit;
	}

	public static function handle_confirm_live() {
		self::check( 'stsf_confirm_live' );

		if ( ! Settings::url_from_constant() ) {
			Settings::save_production_url( home_url() );
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_clear_log() {
		self::check( 'stsf_clear_log' );

		Mail_Guard::clear();

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'staging-safety' ) );
		}
		check_admin_referer( 'stsf_dismiss' );

		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$keys   = array(
			'donate' => self::DONATE_META,
			'setup'  => self::SETUP_META,
			'hidden' => self::HIDDEN_META,
		);

		if ( isset( $keys[ $notice ] ) ) {
			update_user_meta( get_current_user_id(), $keys[ $notice ], time() );
		}

		self::back( self::page_url() );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'staging-safety' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( STSF_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( STSF_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy me a coffee', 'staging-safety' ) . '</a>';
		}
		return $meta;
	}
}
