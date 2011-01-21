<?php
/*
 * Returns PHP language class
 *
 * The decision to use PHP for language files instead of JS
 * was made in case the PHP back-end needs to inject translated
 * messages into the interface.
 *
 * $Id$
 *
 */

error_reporting(0);


/*
 * Load language file
 */

// Settings contains language
require_once(dirname(dirname(__FILE__)) .'/config.php');
require_once(dirname(__FILE__).'/utils.php');

$settings = new phpVBoxConfig();
$lang = strtolower($settings->language);
if(@$_COOKIE['vboxLanguage']) {
	$lang = str_replace(array('/','\\','.'),'',$_COOKIE['vboxLanguage']);
}
// File as specified
if($lang && file_exists(dirname(dirname(__FILE__)).'/languages/'.$lang.'.php')) {
	require_once(dirname(dirname(__FILE__)).'/languages/'.$lang.'.php');
	@define('VBOXLANG', $lang);

// Default to en_us
} else if (file_exists(dirname(dirname(__FILE__)).'/languages/en_us.php')) {
	require_once(dirname(dirname(__FILE__)).'/languages/en_us.php');
	@define('VBOXLANG', 'en_us');
	
// No lang file found
} else {
	echo("alert('Language file does not exist or is not defined in config.php.');\n\n");
	return;
}


function trans($a) {
	return language::$trans[$a];
}
