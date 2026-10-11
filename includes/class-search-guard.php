<?php

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

class Search_Guard {
	public static function init() {
		if ( ! self::blocking() ) {
			return;
		}

		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), PHP_INT_MAX );
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'send_headers', array( __CLASS__, 'header' ) );
	}

	public static function blocking() {
		return Environment::guarded() && Settings::get( 'noindex' );
	}

	public static function robots( $robots ) {
		unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	public static function header() {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	public static function production_hidden() {
		return Environment::is_production()
			&& Environment::is_confirmed()
			&& Settings::get( 'warn_production' )
			&& '0' === (string) get_option( 'blog_public' );
	}
}
