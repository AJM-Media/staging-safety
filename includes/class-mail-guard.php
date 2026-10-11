<?php

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

class Mail_Guard {
	const LOG = 'stsf_mail_log';
	const MAX = 50;

	public static function init() {
		$mode = self::mode();

		if ( 'hold' === $mode ) {
			add_filter( 'pre_wp_mail', array( __CLASS__, 'hold' ), PHP_INT_MAX, 2 );
		} elseif ( 'reroute' === $mode ) {
			add_filter( 'wp_mail', array( __CLASS__, 'reroute' ), PHP_INT_MAX );
		}
	}

	public static function mode() {
		if ( ! Environment::guarded() ) {
			return 'off';
		}

		$mode = Settings::get( 'mail_mode' );
		if ( 'reroute' === $mode && ! is_email( Settings::get( 'reroute_to' ) ) ) {
			return 'hold';
		}

		return $mode;
	}

	public static function hold( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		self::log( $atts, 'held' );

		return true;
	}

	public static function reroute( $atts ) {
		$original = self::recipients( isset( $atts['to'] ) ? $atts['to'] : '' );

		self::log( $atts, 'rerouted' );

		$atts['to']      = Settings::get( 'reroute_to' );
		$atts['subject'] = sprintf(
			/* translators: 1: environment label, 2: original recipients, 3: original subject */
			__( '[%1$s, to %2$s] %3$s', 'staging-safety' ),
			Environment::label(),
			$original,
			isset( $atts['subject'] ) ? $atts['subject'] : ''
		);
		$atts['headers'] = self::strip_copies( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		return $atts;
	}

	private static function strip_copies( $headers ) {
		$lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );

		return array_values(
			array_filter(
				$lines,
				function ( $line ) {
					return '' !== trim( (string) $line ) && ! preg_match( '/^\s*b?cc\s*:/i', (string) $line );
				}
			)
		);
	}

	private static function recipients( $to ) {
		$to = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
		return mb_substr( sanitize_text_field( $to ), 0, 200 );
	}

	private static function log( $atts, $action ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'to'      => self::recipients( isset( $atts['to'] ) ? $atts['to'] : '' ),
				'subject' => mb_substr( sanitize_text_field( isset( $atts['subject'] ) ? (string) $atts['subject'] : '' ), 0, 200 ),
				'action'  => $action,
			)
		);

		update_option( self::LOG, array_slice( $log, 0, self::MAX ), false );
	}

	public static function entries() {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		delete_option( self::LOG );
	}

	public static function replaced_by() {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}

		try {
			$file = wp_normalize_path( (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName() );
		} catch ( \ReflectionException $e ) {
			return '';
		}

		if ( wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' ) === $file ) {
			return '';
		}

		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		if ( 0 === strpos( $file, $plugins ) ) {
			return strtok( substr( $file, strlen( $plugins ) ), '/' );
		}

		return basename( $file );
	}
}
