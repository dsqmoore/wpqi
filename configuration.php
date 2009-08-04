<?php
define('FS_METHOD', 'ftpext');
//If configuration exists, Load it.
if ( file_exists('./config.php') && filemtime('./config.php') + 5*15*60 > time() )
	/*BuildIgnoreInclude*/include './config.php';

if ( ! isset($config) )
	$config = array();

if ( isset($config['user']) ) {

	if ( $config['user']['time'] < time() ) { //timeout.
		$config = array(); //reset it.
		write_config();
		if ( $step != 'first' ) {
			header("Location: {$PHP_SELF}");
			exit;
		}
	} else {
		//Still have a valid user logged in.. possibly..
		if ( ! isset($_COOKIE['wpauto']) || $config['user']['cookie'] != $_COOKIE['wpauto'] ) { //If not actually logged in.. Or doesnt have the right cookie..
			the_header();
			echo '<p>Your Cookie is Fail. If this is incorrect, Please ensure that cookies are enabled in your browser. If you\'ve attempted this install already, You may delete the <code>config.php</code> file which has been created in the same folder as this to restart the installation.</p>';
			echo '</body></html>';
			exit;
		}
	}
}
$hash = isset($config['user']['cookie']) ? $config['user']['cookie'] : md5(time() . microtime());
setcookie('wpauto', $hash, time() + 5*15*60);

$config['user'] = array('time' => time() + 5*15*60, 'cookie' => $hash);

function write_config() {
	global $config, $wp_filesystem;
	if ( empty($config) || empty($config['fs']) )
		return false;

	$_config = '<?php $config = ' . var_export($config, true) . ';';

	if ( ! is_object($wp_filesystem) && ! WP_Filesystem($config['fs'], ABSPATH) )
		return false;

	$res = $wp_filesystem->put_contents( ABSPATH . 'config.php', $_config);
	$wp_filesystem->chmod(ABSPATH . 'config.php', FS_CHMOD_FILE);
	return $res;
}

function delete_config() {
	global $config, $wp_filesystem;
	if ( ! file_exists('./config.php') )
		return false;

	if ( ! is_object($wp_filesystem) && ! WP_Filesystem($config['fs'], ABSPATH) )
		return false;

	if ( $wp_filesystem->delete( ABSPATH . 'config.php' ) )
		return true;
	else
		return @unlink(ABSPATH . 'config.php'); //Purely just in case?
}