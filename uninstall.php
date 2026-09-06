<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
delete_option( 'onkupon_agent_settings' );
delete_option( 'onkupon_agent_db_version' );
