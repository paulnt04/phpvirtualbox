<?php

/*
 * Sample client for the VirtualBox webservice, written in PHP.
 *
 * Run the VirtualBox web service server first; see the VirtualBOx
 * SDK reference for details.
 *
 * Copyright (C) 2009 Sun Microsystems, Inc.
 * Contributed by James Lucas (mjlucas at eng.uts.edu.au).
 *
 * The following license applies to this file only:
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 */

/*
 * </Sun license>
 *
 * SLIGHTLY modified by Ian Moore
 * $Id$
 */

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
if($_REQUEST['width']) {
	$force_width = $_REQUEST['width'];
}


try {

	// Is VM Specified
	if(!$_REQUEST['vm']) {
		echo("Please specify a *RUNNING* VM to take a screen shot of. E.g. http://webserver/phpvirtualbox/screen.php?vm=VMName");
		exit;
	}

	//Get a list of registered machines
	$machine = $vbox->__getMachineRef($_REQUEST['vm']);
	switch($machine->state->__toString()) {
		case 'Running':
		case 'Saved':
		case 'Restoring':
			break;
		default:
			$machine->releaseRemote();
			throw new Exception('The specified virtual machine is not in a Running state.');
	}

	$_REQUEST['vm'] = $machine->id;


	// Take active screenshot if machine is running
	if($machine->state->__toString() == 'Running') {

		$vbox->session = $vbox->websessionManager->getSessionObject($vbox->vbox->handle);
		$machine->lockMachine($vbox->session->handle,'Shared');

		$res = $vbox->session->console->display->getScreenResolution(0);

	    $screenWidth = array_shift($res);
	    $screenHeight = array_shift($res);

	    if($force_width) {

			$factor  = (float)$force_width / (float)$screenWidth;

			$screenWidth = $force_width;
			if($factor > 0) {
				$screenHeight = $factor * $screenHeight;
			} else {
				$screenHeight = ($screenWidth * 3.0/4.0);
			}
	    }

		// array() for compatibility with readSavedScreenshotPNGToArray return value
		$imageraw = array($vbox->session->console->display->takeScreenShotPNGToArray(0,$screenWidth, $screenHeight));

		$vbox->session->unlockMachine();

	} else {

		$imageraw = $machine->readSavedScreenshotPNGToArray(0);

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
		} catch (Exception $e) {}
	}

	if($_REQUEST['debug']) {
		echo("<pre>");
		print_r($ex);
	}
}
