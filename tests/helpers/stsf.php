<?php

use StagingSafety\Admin;
use StagingSafety\Badge;
use StagingSafety\Environment;
use StagingSafety\Mail_Guard;
use StagingSafety\Search_Guard;
use StagingSafety\Settings;

$command = isset( $args[0] ) ? $args[0] : '';

switch ( $command ) {
	case 'state':
		Environment::reset();
		echo Environment::type(), ' ', Environment::source(), ' ', Environment::guarded() ? 'guarded' : 'open', ' ', Mail_Guard::mode(), ' ', Search_Guard::blocking() ? 'noindex' : 'index';
		break;

	case 'set':
		$settings = Settings::all();
		foreach ( array_slice( $args, 1 ) as $pair ) {
			list( $key, $value ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$settings[ $key ] = $value;
		}
		update_option( Settings::OPTION, Settings::sanitize( $settings ) );
		echo 'ok';
		break;

	case 'confirm':
		Settings::save_production_url( home_url() );
		echo Settings::get( 'production_url' );
		break;

	case 'mail':
		$sent = array();
		add_filter(
			'pre_wp_mail',
			function ( $return, $atts ) use ( &$sent ) {
				if ( null === $return ) {
					$sent = $atts;
					return true;
				}
				return $return;
			},
			PHP_INT_MAX,
			2
		);
		$before = count( Mail_Guard::entries() );
		$result = wp_mail( array( 'customer@example.com', 'other@example.com' ), 'Order 42', 'Body', array( 'From: shop@example.com', 'Cc: cc@example.com', 'Bcc: bcc@example.com' ) );
		$entries = Mail_Guard::entries();
		$logged  = count( $entries ) > $before ? $entries[0]['action'] : 'none';
		$to      = $sent ? implode( ',', (array) $sent['to'] ) : '-';
		$copies  = $sent ? preg_grep( '/^\s*b?cc\s*:/i', (array) $sent['headers'] ) : array();
		echo ( $result ? 'true' : 'false' ), ' ', $logged, ' ', $to, ' copies=', count( $copies ), ' subject=', $sent ? $sent['subject'] : '-';
		break;

	case 'notices':
		ob_start();
		Admin::notices();
		$html  = ob_get_clean();
		$found = array();
		if ( false !== strpos( $html, 'needs one setting' ) ) {
			$found[] = 'setup';
		}
		if ( false !== strpos( $html, 'hidden from search engines' ) ) {
			$found[] = 'hidden';
		}
		echo $found ? implode( ',', $found ) : 'none';
		break;

	case 'badge':
		add_filter( 'show_admin_bar', '__return_true' );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		Badge::add( $bar );
		$node = $bar->get_node( 'stsf-env' );
		echo $node ? wp_strip_all_tags( $node->title ) . ' | ' . $node->meta['class'] : 'none';
		break;

	case 'admin-check':
		$errors = array();
		set_error_handler(
			function ( $no, $message, $file, $line ) use ( &$errors ) {
				if ( false !== strpos( $file, 'staging-safety' ) ) {
					$errors[] = basename( $file ) . ':' . $line . ' ' . $message;
				}
				return false;
			}
		);
		add_filter( 'show_admin_bar', '__return_true' );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		ob_start();
		Admin::render();
		Admin::notices();
		Badge::add( new WP_Admin_Bar() );
		$html = ob_get_clean();
		restore_error_handler();
		if ( $errors ) {
			echo implode( '; ', $errors );
		} elseif ( false === strpos( $html, 'stsf-current' ) ) {
			echo 'page did not render';
		} else {
			echo isset( $args[1] ) && false !== strpos( $html, $args[1] ) ? 'ok+' : 'ok';
		}
		break;

	default:
		echo 'unknown command';
}
echo "\n";
