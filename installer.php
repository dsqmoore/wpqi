<?php

$step = isset($_REQUEST['step']) ? $_REQUEST['step'] : 'first';

include 'resources.php';
include 'configuration.php';
include 'functions.php';
include 'servervars.php';

if ( 'install-wordpress' == $step ) {
	//Here is where we run the WordPress Installer via the usual process
	if ( ! isset($config['fs']) && !isset($config['fs']) ) {
		header("Location: $PHP_SELF");
		exit;
	}
	define('WP_INSTALLING', true);
	// This is seen as hacky by some, But its got a useful use.
	// Start output buffering so as to ensure that when WE echo the serialized data, it IS the only output.. none of these PHP Deprecated errors under PHP 5.3 please!
	ob_start();
	include dirname(__FILE__) . '/' . rtrim($config['destination'], '/') . '/wp-config.php';
	include 'steps/install-wordpress.php';
	exit;
}
/*BuildCompressSplit*/
define('ABSPATH', dirname(__FILE__) . '/');
if ( !defined('WP_MEMORY_LIMIT') )
	define('WP_MEMORY_LIMIT', '64M');

if ( function_exists('memory_get_usage') && ( (int) @ini_get('memory_limit') < abs(intval(WP_MEMORY_LIMIT)) ) )
	@ini_set('memory_limit', WP_MEMORY_LIMIT);

if ( defined( 'E_DEPRECATED' ) )
	error_reporting( E_ALL & ~E_DEPRECATED & ~E_STRICT );
else
	error_reporting( E_ALL );
@ini_set('display_errors', 1);
define('QI_DEBUG', false);

include 'steps/header.php';
include 'steps/footer.php';
include 'wp-error.php';
include 'file.php';

$wpdb = true; //Hack to stop auto-loading of the DB
if ( file_exists('./db.php') ) {
	/*BuildIgnoreInclude*/include 'db.php';
} else {
	include 'wp-files/wp-includes/wp-db.php';
}
include 'wordpress-functions.php';
include 'wp-files/wp-includes/class-http.php';
include 'wp-files/wp-includes/http.php';
include 'wp-files/wp-admin/includes/class-wp-filesystem-base.php';
include 'wp-files/wp-admin/includes/class-wp-filesystem-direct.php';
include 'wp-files/wp-admin/includes/class-wp-filesystem-ssh2.php';
include 'wp-files/wp-admin/includes/class-wp-filesystem-ftpext.php';
include 'wp-files/wp-admin/includes/class-ftp.php';
if ( defined('COMPRESSED_BUILD') && COMPRESSED_BUILD ) { //class-ftp includes it in normal operation..
	if ( $mod_sockets ) {
		include 'wp-files/wp-admin/includes/class-ftp-sockets.php';
	} else {
		include 'wp-files/wp-admin/includes/class-ftp-pure.php';
	}
}
include 'wp-files/wp-admin/includes/class-wp-filesystem-ftpsockets.php';

switch ( $step ) {
	default:
	case 'first':
		include 'steps/first.php';
		break;
	case 'ftp-details':
	case 'ftp-detail-check':
		include 'steps/fs.php';
		break;
	case 'download-options':
	case 'download-options-check':
		include 'steps/download-options.php';
		break;
	case 'download':
		include 'steps/download.php';
		break;
}
