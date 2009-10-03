<?php
if ( empty($config['db']) || empty($config['fs']) ) {
	header("Location: {$PHP_SELF}?timeout=true");
	exit;
}
the_header('install');
echo '<h2>Installing...</h2>';

$requested_url  = ( !empty($_SERVER['HTTPS'] ) && strtolower($_SERVER['HTTPS']) == 'on' ) ? 'https://' : 'http://';
$requested_url .= $_SERVER['HTTP_HOST'];
$requested_url .= $_SERVER['REQUEST_URI'];

set_time_limit(0); //We may need it...

echo '<p>Connecting to Filesystem.. ';
WP_Filesystem($config['fs']);
echo '<strong>Success!</strong></p>';

echo '<p>Downloading package from <code>' . $config['package'] . '</code>.. ';
@ob_end_flush(); flush();
$download_file = download_url($config['package']);
if ( is_wp_error($download_file) )
	die( '<strong>Failure</strong> - ' . $download_file->get_error_code() . ': ' . $download_file->get_error_message() );
else
	echo '<strong>Success!</strong></p>';

echo '<p>Uncompressing WordPress files to Filesystem.. <strong><span id="progress">0%</span></strong></p>';
@ob_end_flush(); flush();
function _install_tick($args) {
	static $last = 0;
	if ( ! $args['process'] ) return;
	$percent = round($args['process'] / $args['count'] * 100, 0);
	if ( time() > $last + 1 || $percent >= 100 ) { //Once per 2 second.. or ended.
		$last = time();
		echo "<script type='text/javascript'>document.getElementById('progress').innerHTML = '{$percent}%';</script>";
		@ob_end_flush(); flush();
	}
}

$res = unzip_file($download_file, ABSPATH . $config['destination'], '_install_tick');
if ( is_wp_error($res) ) {
	$error = $res->get_error_message();
	$data = $res->get_error_data();
	if ( !empty($data) )
		$error .= $res->get_error_data();
	echo "<script type='text/javascript'>document.getElementById('progress').innerHTML = '<strong>Failed</strong> - Installation Halted, Error: " . $error . "';</script>";
	echo "<noscript><strong>Failed</strong> - Installation Halted, Error: {$error}</noscript>";
	exit;
} else {
	echo "<script type='text/javascript'>document.getElementById('progress').innerHTML = '<strong>Success!</strong>';</script>";
}

echo '<p>Removing Temporary Download files.. ';
if ( unlink($download_file) )
	echo '<strong>Success!</strong>';
else
	echo '<strong>Failure</strong>.. Please ensure that <code>' . $download_file . '</code> has been removed';
echo '</p>';

//Create our wp-config.php file

echo '<p>Creating <code>wp-config.php</code>, seting Database credentials and generating security keys.. ';
$sample = file_get_contents( trailingslashit(ABSPATH . $config['destination']) . 'wp-config-sample.php');

$required_wp_config_defines = array('AUTH_KEY' => null, 'SECURE_AUTH_KEY' => null, 'LOGGED_IN_KEY' => null, 'NONCE_KEY' => null,
									'DB_NAME' => null, 'DB_USER' => '', 'DB_PASSWORD' => null, 'DB_HOST' => null);
if ( in_array('pretty-permalinks', $config['options']) )
	$required_wp_config_defines['WP_DEBUG'] = true;

//If a define doesnt exist already, Better add it to the file.. Best to be secure.
//Note: We do NOT care about the value at this stage, This is mearly a placeholder setup, The next function(_install_replace_constant) will fill the constant value in.
//The Build script doesnt check to see if comments and php openers are in a string or not, Which is why the following code has string breaks.
foreach ( $required_wp_config_defines as $_define => $_value )
	if ( ! preg_match("!define\('" . preg_quote($_define, '!') . "'!ix", $sample) )
		$sample = str_replace('<'.'?php', "<"."?php\r\ndefine('$_define', false);/"."/Added by WordPress QI automatically.\n", $sample);

function _install_replace_constant($matches) {
	global $config, $required_wp_config_defines;
	$replacement = false;
	switch($matches[1]) {
		case 'DB_NAME':
			$replacement = $config['db']['database'];
			break;
		case 'DB_USER':
			$replacement = $config['db']['username'];
			break;
		case 'DB_PASSWORD':
			$replacement = $config['db']['password'];
			break;
		case 'DB_HOST':
			$replacement = $config['db']['hostname'];
			break;
		case 'AUTH_KEY':
		case 'SECURE_AUTH_KEY':
		case 'LOGGED_IN_KEY':
		case 'NONCE_KEY':
			$replacement = wp_generate_password(64, true);
			break;
		default:
			if ( isset($required_wp_config_defines[ $matches[1] ]) ) {
				$replacement = $required_wp_config_defines[ $matches[1] ];
				if ( ! is_string($replacement) ) {
					if ( is_bool($replacement) )
						$replacement = $replacement ? 'true' : 'false';
					elseif ( is_null($replacement) )
						$replacement = '';
				}
			}
			break;
	}
	if ( ! $replacement )
		return $matches[0];
	else
		return str_replace( $matches[2], $replacement, $matches[0]);
}
$new_file = preg_replace_callback("!define\('([A-Z0-9_]+?)',\s*'?([^')]+?)'?\)!ix", '_install_replace_constant', $sample); //TODO note escaped ' 's 

//Database Table Prefix:
$new_file = preg_replace('|\$table_prefix.*?;(.*?)|ix', "\$table_prefix = '{$config['db']['prefix']}';", $new_file);

//Save the config
if ( $wp_filesystem->put_contents( trailingslashit(ABSPATH . $config['destination']) . 'wp-config.php', $new_file) ) {
	$wp_filesystem->chmod( trailingslashit(ABSPATH . $config['destination']) . 'wp-config.php', FS_CHMOD_FILE);
	echo '<strong>Success!</strong>.</p>';
} else {
	echo '<strong>Failure</strong> - Failed to write wp-config.php file.</p>';
	echo '<p>Installation cannot continue until a wp-config.php file is created. Once you\'ve created the wp-config file and set the correct settings, You may continue to installing wordpress <a href="' . trailingslashit(url_join($requested_url, $config['destination'])) .'/wp-admin/install.php">By clicking here</a>.</p>'; //TODO: Test this.
	exit;
}
unset($new_file, $sample);

if ( 'direct' != get_filesystem_method() && !is_writable( trailingslashit(ABSPATH . $config['destination']) . '/wp-content/' ) ) {
	//Create cache + upload directories, Chmod accordingly. - TODO try 755, 775, 777 instead.
	//Only do this when using FTP, When using direct access, Theres no problems related to it.
	$folder = trailingslashit(ABSPATH . $config['destination']) . '/wp-content/uploads/';
	if ( ! is_writable($folder) ) { 
		echo '<p>Creating WordPress File upload folder and setting permissions... ';
		if ( $wp_filesystem->mkdir( $folder, 0777) )
			echo '<strong>Success!</strong>.</p>';
		else
			echo '<strong>Failed</strong>.</p>';
	}
	$folder = trailingslashit(ABSPATH . $config['destination']) . '/wp-content/cache/';
	if ( ! is_writable($folder) ) {
		echo '<p>Creating WordPress Cache folder and setting permissions... ';
		if ( $wp_filesystem->mkdir( $folder, 0777) )
			echo '<strong>Success!</strong>.</p>';
		else
			echo '<strong>Failed</strong>.</p>';
	}
}

//Create the .htaccess
if ( in_array('pretty-permalinks', $config['options']) ) {
	echo '<p>Creating <code>.htaccess</code> to enable <em>Pretty Permalinks</em>... ';
	$htaccess_content = '# BEGIN WordPress
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
# END WordPress';
	if ( $wp_filesystem->put_contents( trailingslashit(ABSPATH . $config['destination']) . '.htaccess', $htaccess_content ) ) {
		$wp_filesystem->chmod( trailingslashit(ABSPATH . $config['destination']) . '.htaccess', FS_CHMOD_FILE);
		echo '<strong>Success!</strong>.</p>';
	} else {
		echo '<strong>Failure</strong>.</p>';
	}
}

//Run the install!
echo '<p>Creating Database Tables and settings defaults.. ';

$post_data = array('title' => $config['title'], 'email' => $config['email'], 'options' => $config['options']);

$data = wp_remote_post( substr($requested_url, 0, strpos($requested_url, '?')) . '?step=install-wordpress',
						array(	'body' => $post_data,
							  	'cookies' => cookie_array_to_cookie_http_objects($_COOKIE),
								'timeout' => 60,
								'user-agent' => 'WordPress QI/0.5beta')
						);

$details = @unserialize( $data['body'] );

if ( $details )
	echo '<strong>Success!</strong>.</p>';
else
	echo '<strong>Failure</strong> - The install may not have completed correctly. You have been warned, But attempting to continue anyway.</p>';
	
if ( ! $details ) var_dump(substr($requested_url, 0, strpos($requested_url, '?')) . '?step=install-wordpress', $data, $post_data);

//Delete our config.php file about here..
echo '<p>Removing Installer Configuration file... ';
if ( $wp_filesystem->delete( ABSPATH . 'config.php') )
	echo '<strong>Success!</strong>.</p>';
else
	echo '<strong>Failed</strong> - You should remove <code>config.php</code> manually.</p>';

//Finally.. Delete ourselves..
if ( defined('COMPRESSED_BUILD') && COMPRESSED_BUILD && !file_exists('./build.php') ) { //as long as he build file doesnt exist.. (ie. dev install)
	//Lets hope like he.. that someone hasnt uploaded it as a filename which WP has created in the current dir.. ie. index.php
	echo '<p>Removing Installer file... ';
	if ( $wp_filesystem->delete( $installer_file ) )
		echo '<strong>Success!</strong>.</p>';
	else
		echo '<strong>Failed</strong> - You should remove <code>' . basename($installer_file) . '</code> manually.</p>';
}

//Ok, We've got all the details now..

if ( $details ) {
	echo '<form method="post" action="' . $details['url']  . 'wp-login.php">
<input type="hidden" name="log" value="' . $details['username'] . '" />
<input type="hidden" name="pwd" value="' . $details['password'] . '" />
<p><strong>Success!</strong> Your WordPress installation is now complete, You may now login with the default username <strong>' . $details['username'] . '</strong>, with the password <strong>' . $details['password'] . '</strong>. Note this password as it will be required to login with in the future if you do not change the password after logging in. For your convience it has also been emailed to your selected email address.</p>

<p class="step"><input name="submit" type="submit" value="Login Instantly" class="button" /></p>
</form>';
} else {
	echo '<p>An error occured during install, Its unknown if the installation was completed successfully. Please browse to the installation folder and complete the install if nesecary.(TODO Spellings)</p>';
}

//Its been a long night :)