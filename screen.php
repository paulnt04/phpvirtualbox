<?php
/*
 * $Id$
 */

# Turn off PHP notices
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);

// Clean request
$_REQUEST = array_merge($_GET,$_POST);

require_once(dirname(__FILE__).'/config.php');
require_once(dirname(__FILE__).'/lib/vboxconnector.php');


$settings = new phpVBoxConfig();

$vbox = new vboxconnector();
$vbox->connect();

// Since the vbox screenshot function returns 4 bytes per pixel
// AND we need to build an image from each pixel, the memory usage
// of this script can get rather large. 800px wide usually keeps
// it right under 150M.
@ini_set('memory_limit', '512M');

// Set width. Else assume we want real time updates if VM is running below
if($_REQUEST['width'])
	$force_width = $_REQUEST['width'];



try {

	// Is VM Specified
	if(!$_REQUEST['vm']) {
		echo("Please specify a VM to take a screen shot of. E.g. http://webserver/phpvirtualbox/screen.php?vm=VMName");
		exit;
	}

	//Get a list of registered machines
	$machine = $vbox->vbox->findMachine($_REQUEST['vm']);
	switch($machine->state->__toString()) {
		case 'Running':
		case 'Saved':
		case 'Restoring':
			break;
		default:
			$machine->releaseRemote();
			throw new Exception('The specified virtual machine is not in a Running state.');
	}

	// Date last modified
	$dlm = floor($machine->lastStateChange/1000);

	// Set last modified header
	header("Last-Modified: " . gmdate("D, d M Y H:i:s", $dlm) . " GMT");

	$_REQUEST['vm'] = $machine->id;



	// Take active screenshot if machine is running
	if($machine->state->__toString() == 'Running') {

		$vbox->session = $vbox->websessionManager->getSessionObject($vbox->vbox->handle);
		$machine->lockMachine($vbox->session->handle,'Shared');
		$machine->releaseRemote();

		$res = $vbox->session->console->display->getScreenResolution(0);

	    $screenWidth = array_shift($res);
	    $screenHeight = array_shift($res);

	    // Force screenshot width while maintaining aspect ratio
	    if($force_width) {

			$factor  = (float)$force_width / (float)$screenWidth;

			$screenWidth = $force_width;
			if($factor > 0) {
				$screenHeight = $factor * $screenHeight;
			} else {
				$screenHeight = ($screenWidth * 3.0/4.0);
			}

		// If no width is set, assume we want real-time updates
	    } else {

			//Set no caching
			header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
			header("Pragma: no-cache");
	    }

		// array() for compatibility with readSavedScreenshotPNGToArray return value
		$imageraw = array($vbox->session->console->display->takeScreenShotPNGToArray(0,$screenWidth, $screenHeight));

		$vbox->session->unlockMachine();
		$vbox->session->releaseRemote();

	} else {

		// Let the browser cache images
    	if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $dlm) {
			if (php_sapi_name()=='CGI') {
				Header("Status: 304 Not Modified");
			} else {
				Header("HTTP/1.0 304 Not Modified");
			}
      		exit;
    	}


    	if($_REQUEST['full']) $imageraw = $machine->readSavedScreenshotPNGToArray(0);
    	else $imageraw = $machine->readSavedThumbnailPNGToArray(0);
			
		$machine->releaseRemote();

	}
	$vbox->session = null;


	header("Content-type: image/png",true);

	foreach($imageraw as $i) {
		if(is_array($i))
			foreach($i as $b) echo(chr($b));
	}


} catch (Exception $ex) {

	// Ensure we close the VM Session if we hit a error, ensure we don't have a aborted VM
	if($vbox && $vbox->session && $vbox->session->handle) {
		try {
			$vbox->session->unlockMachine();
			unset($vbox->session);
		} catch (Exception $e) {
		}
	}

	if($_REQUEST['full'] && strpos($ex->faultstring,'VERR_NOT_SUPPORTED') > 0) {
		@header("Content-type: text/html");
		echo("Screen shots are not supported by your VirtualBox installation. To enable screen shots, please install a VirtualBox exteionsion pack that supports VRDE ");
		echo("such as the Oracle VM VirtualBox Extension Pack found in the Downloads section of <a href='http://www.virtualbox.org'>http://www.virtualbox.org</a>.");
	} else if($_REQUEST['full'] || $_REQUEST['debug']) {
		header("Content-type: text/html", true);
		echo("<pre>");
		print_r($ex);
		echo("</pre>");
	}
}
