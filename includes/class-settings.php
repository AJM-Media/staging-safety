<?php

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

class Settings {
	const OPTION = 'stsf_settings';

	public static function defaults() {
		return array(
			'production_url'  => '',
			'label'           => '',
			'mail_mode'       => 'hold',
			'reroute_to'      => '',
			'noindex'         => true,
			'warn_production' => true,
		);
	}

	public static function all() {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		if ( self::url_from_constant() ) {
			$settings['production_url'] = self::origin( STAGING_SAFETY_PRODUCTION_URL );
		}

		return $settings;
	}

	public static function get( $key ) {
		$settings = self::all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public static function url_from_constant() {
		return defined( 'STAGING_SAFETY_PRODUCTION_URL' ) && '' !== (string) STAGING_SAFETY_PRODUCTION_URL;
	}

	public static function production_host() {
		return Environment::host( self::get( 'production_url' ) );
	}

	public static function origin( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( esc_url_raw( $url ) );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		$saved                    = (array) get_option( self::OPTION, array() );
		$clean['production_url']  = isset( $input['production_url'] ) ? self::origin( $input['production_url'] ) : ( isset( $saved['production_url'] ) ? (string) $saved['production_url'] : '' );
		$clean['label']           = isset( $input['label'] ) ? mb_substr( sanitize_text_field( $input['label'] ), 0, 40 ) : '';
		$clean['noindex']         = ! empty( $input['noindex'] );
		$clean['warn_production'] = ! empty( $input['warn_production'] );

		if ( isset( $input['mail_mode'] ) && in_array( $input['mail_mode'], array( 'hold', 'reroute', 'off' ), true ) ) {
			$clean['mail_mode'] = $input['mail_mode'];
		}
		if ( isset( $input['reroute_to'] ) ) {
			$clean['reroute_to'] = sanitize_email( $input['reroute_to'] );
		}
		if ( 'reroute' === $clean['mail_mode'] && ! is_email( $clean['reroute_to'] ) ) {
			$clean['mail_mode'] = 'hold';
		}
		if ( 'hold' === $clean['mail_mode'] && isset( $input['mail_mode'] ) && 'reroute' === $input['mail_mode'] && function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'stsf_reroute', __( 'Enter a valid email address to reroute emails to. Emails will be held until then.', 'staging-safety' ) );
		}

		Environment::reset();

		return $clean;
	}

	public static function save_production_url( $url ) {
		$settings                   = (array) get_option( self::OPTION, array() );
		$settings['production_url'] = self::origin( $url );
		update_option( self::OPTION, wp_parse_args( $settings, self::defaults() ) );
		Environment::reset();
	}
}
