<?php

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

class Environment {
	const TYPES = array( 'local', 'development', 'staging', 'production' );

	private static $current = null;

	public static function reset() {
		self::$current = null;
	}

	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$host       = self::host( home_url() );
		$guess      = self::guess( $host );
		$production = Settings::production_host();

		if ( self::explicit() ) {
			$type   = wp_get_environment_type();
			$source = 'constant';
		} elseif ( '' !== $production ) {
			$type   = $host === $production ? 'production' : ( '' !== $guess ? $guess : 'staging' );
			$source = 'address';
		} elseif ( '' !== $guess ) {
			$type   = $guess;
			$source = 'guess';
		} else {
			$type   = 'production';
			$source = 'unknown';
		}

		$type = apply_filters( 'staging_safety_environment', $type, $source );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'production';
		}

		self::$current = array(
			'type'   => $type,
			'source' => $source,
		);

		return self::$current;
	}

	public static function type() {
		$current = self::current();
		return $current['type'];
	}

	public static function source() {
		$current = self::current();
		return $current['source'];
	}

	public static function is_production() {
		return 'production' === self::type();
	}

	public static function is_confirmed() {
		return in_array( self::source(), array( 'constant', 'address' ), true );
	}

	public static function guarded() {
		if ( defined( 'STAGING_SAFETY_DISABLE' ) && STAGING_SAFETY_DISABLE ) {
			return false;
		}
		if ( self::is_production() ) {
			return false;
		}

		return self::is_confirmed() || 'local' === self::type();
	}

	public static function explicit() {
		return defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' );
	}

	public static function host( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = trim( $host, '[]' );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	public static function guess( $host ) {
		if ( '' === $host ) {
			return '';
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return 'local';
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? '' : 'local';
		}

		$local_suffixes = apply_filters(
			'staging_safety_local_suffixes',
			array( '.local', '.test', '.localhost', '.invalid', '.example', '.internal', '.lndo.site', '.ddev.site', '.docksal.site' )
		);
		foreach ( $local_suffixes as $suffix ) {
			if ( self::ends_with( $host, $suffix ) ) {
				return 'local';
			}
		}

		$staging_suffixes = apply_filters(
			'staging_safety_staging_suffixes',
			array( '.flywheelstaging.com', '.wpcomstaging.com', '.mystagingwebsite.com', '.instawp.xyz' )
		);
		foreach ( $staging_suffixes as $suffix ) {
			if ( self::ends_with( $host, $suffix ) ) {
				return 'staging';
			}
		}

		$labels = explode( '.', $host );
		$labels = array_slice( $labels, 0, max( 0, count( $labels ) - 2 ) );

		foreach ( $labels as $label ) {
			if ( in_array( $label, array( 'staging', 'stage', 'stg', 'uat', 'preprod', 'qa', 'test', 'testing' ), true )
				|| preg_match( '/^(staging|stage|stg|test)-|-(staging|stg)$/', $label ) ) {
				return 'staging';
			}
			if ( in_array( $label, array( 'dev', 'develop', 'development' ), true ) || 0 === strpos( $label, 'dev-' ) ) {
				return 'development';
			}
		}

		return '';
	}

	private static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		return $length > 0 && substr( $haystack, -$length ) === $needle;
	}

	public static function label() {
		$custom = Settings::get( 'label' );
		return '' !== $custom && ! self::is_production() ? $custom : self::default_label();
	}

	public static function default_label() {
		$type   = self::type();
		$labels = array(
			'local'       => __( 'Local', 'staging-safety' ),
			'development' => __( 'Development', 'staging-safety' ),
			'staging'     => __( 'Staging', 'staging-safety' ),
			'production'  => __( 'Production', 'staging-safety' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $labels['production'];
	}

	public static function reason() {
		switch ( self::source() ) {
			case 'constant':
				return __( 'Set by WP_ENVIRONMENT_TYPE in wp-config.php or the server environment.', 'staging-safety' );
			case 'address':
				return self::is_production()
					? __( 'This site\'s address matches the production address.', 'staging-safety' )
					: __( 'This site\'s address is different from the production address.', 'staging-safety' );
			case 'guess':
				return __( 'Guessed from this site\'s address. Add your production address to confirm it.', 'staging-safety' );
			default:
				return __( 'Not set up yet, so this site is treated as production and nothing is blocked.', 'staging-safety' );
		}
	}
}
