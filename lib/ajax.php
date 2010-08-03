<?php
/*
 *
 * All methods are handled in the vboxconnector() __call method.
 * This script simply catches errors and returns json data. This
 * depends on methods' public / private attributes being set correctly.
 *
 * $Id$
 *
*/

//Set no caching
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");

/*
 * Clean request
 */
$vboxRequest = array_merge($_GET,$_POST);
if(get_magic_quotes_gpc()) {
	function __vbx_stripslash(&$a) { $a = stripslashes($a); }
	array_walk_recursive($vboxRequest,'__vbx_stripslash');
}

require_once(dirname(dirname(__FILE__)).'/config.php');
require_once(dirname(__FILE__).'/utils.php');
require_once(dirname(__FILE__).'/vboxconnector.php');

$response = array('data'=>array(),'errors'=>array(),'persist'=>array());

/*
 * Error handling
 */
error_reporting(E_ALL);
function record_errors($errno, $errmsg) {
	if($errno == E_NOTICE) return;
	$response['errors'][] = new Exception($errmsg);
}
set_error_handler('record_errors');


/*
 * Built-in requests
 */
switch($vboxRequest['fn']) {

	/* Return config vars */
	case 'getConfig':

		$settings = new phpVBoxConfig();
		$response['data'] = get_object_vars($settings);
		$response['data']['host'] = parse_url($response['data']['location'],PHP_URL_HOST);
		unset($response['data']['username']);
		unset($response['data']['password']);

		if(!$response['data']['nicMax']) $response['data']['nicMax'] = 4;

		// RDP Host?
		if(!$response['data']['rdpHost']) $response['data']['rdpHost'] = $response['data']['host'];

		// Vbox version
		try {
			$vbox = new vboxconnector();
			$response['data']['version'] = $vbox->getVersion();

		} catch (Exception $null) { }

		// Host OS and directory seperator
		$response['data']['hostOS'] = $vbox->vbox->host->operatingSystem;
		if(stripos($response['data']['hostOS'],'windows') === false) {
        		 $response['data']['DSEP'] = '/';
		} else {
        		 $response['data']['DSEP'] = '\\';
		}

		// What vboxconnector considers to be a fatal error
		$response['data']['PHPVB_ERRNO_FATAL'] = vboxconnector::PHPVB_ERRNO_FATAL;


		break;

	/* VirtualBox Requests */
	default:

		try {

			$vbox = new vboxconnector();
			# fix for allow_call_time_pass_reference = Off setting
			if(method_exists($vbox,$vboxRequest['fn'])) {
				$vbox->$vboxRequest['fn']($vboxRequest,$response);
			} else {
				$vbox->$vboxRequest['fn']($vboxRequest,array(&$response));
			}

		} catch (Exception $e) {

			ob_start();
			print_r($e);
			$d = ob_get_contents();
			ob_end_clean();
			$response['errors'][] = array('error'=>$e->getMessage(),'details'=>$d,'errno'=>$e->getCode());
		}

		// Add other error info
		if($vbox->errors) {
			foreach($vbox->errors as $e) {
				ob_start();
				print_r($e);
				$d = ob_get_contents();
				ob_end_clean();
				$response['errors'][] = array('error'=>$e->getMessage(),'details'=>$d,'errno'=>$e->getCode());
			}
		}


}
if($vboxRequest['printr']) print_r($response);
else echo(@json_encode($response));

