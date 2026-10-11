<?php

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

class Badge {
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add' ), 31 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	private static function visible() {
		return is_admin_bar_showing() && current_user_can( 'edit_posts' );
	}

	public static function enqueue() {
		if ( self::visible() ) {
			wp_enqueue_style( 'stsf-admin-bar', STSF_URL . 'assets/admin-bar.css', array( 'admin-bar' ), STSF_VERSION );
		}
	}

	public static function add( $bar ) {
		if ( ! self::visible() ) {
			return;
		}

		$type   = Environment::type();
		$hidden = Search_Guard::production_hidden();
		$label  = Environment::label();
		if ( 'guess' === Environment::source() || 'unknown' === Environment::source() ) {
			$label .= ' ?';
		}
		if ( $hidden ) {
			$label = sprintf(
				/* translators: %s: environment label */
				__( '%s: hidden from search engines', 'staging-safety' ),
				Environment::label()
			);
		}

		$admin = current_user_can( 'manage_options' );

		$bar->add_node(
			array(
				'id'    => 'stsf-env',
				'title' => esc_html( $label ),
				'href'  => $admin ? Admin::page_url() : false,
				'meta'  => array(
					'class' => 'stsf-env stsf-env-' . $type . ( $hidden ? ' stsf-env-alert' : '' ),
					'title' => Environment::reason(),
				),
			)
		);

		if ( ! $admin ) {
			return;
		}

		foreach ( self::status_lines() as $id => $text ) {
			$bar->add_node(
				array(
					'parent' => 'stsf-env',
					'id'     => 'stsf-env-' . $id,
					'title'  => esc_html( $text ),
					'href'   => Admin::page_url(),
				)
			);
		}
	}

	public static function status_lines() {
		$lines = array();

		switch ( Mail_Guard::mode() ) {
			case 'hold':
				$lines['mail'] = sprintf(
					/* translators: %d: number of emails in the log */
					_n( 'Emails: held (%d logged)', 'Emails: held (%d logged)', count( Mail_Guard::entries() ), 'staging-safety' ),
					count( Mail_Guard::entries() )
				);
				break;
			case 'reroute':
				$lines['mail'] = sprintf(
					/* translators: %s: email address */
					__( 'Emails: rerouted to %s', 'staging-safety' ),
					Settings::get( 'reroute_to' )
				);
				break;
			default:
				$lines['mail'] = __( 'Emails: sent normally', 'staging-safety' );
		}

		if ( Search_Guard::blocking() ) {
			$lines['search'] = __( 'Search engines: blocked', 'staging-safety' );
		} elseif ( Search_Guard::production_hidden() ) {
			$lines['search'] = __( 'Search engines: discouraged in Settings > Reading', 'staging-safety' );
		} else {
			$lines['search'] = __( 'Search engines: allowed', 'staging-safety' );
		}

		if ( ! Environment::is_confirmed() ) {
			$lines['setup'] = __( 'Add your production address to finish setup', 'staging-safety' );
		}

		return $lines;
	}
}
