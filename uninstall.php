<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $stsf_site_id ) {
		delete_blog_option( $stsf_site_id, 'stsf_settings' );
		delete_blog_option( $stsf_site_id, 'stsf_mail_log' );
	}
} else {
	delete_option( 'stsf_settings' );
	delete_option( 'stsf_mail_log' );
}

foreach ( array( 'stsf_donate_dismissed', 'stsf_setup_dismissed', 'stsf_hidden_dismissed' ) as $stsf_meta ) {
	delete_metadata( 'user', 0, $stsf_meta, '', true );
}
