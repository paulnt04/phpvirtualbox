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

// Does PHP installation have GD?
if(!function_exists('imagepng')) {
	echo("PHP does not have the GD extension installed and/or enabled.");
	exit;
// Is VM Specified
} else if(!$_REQUEST['vm']) {
	echo("Please specify a *RUNNING* VM to take a screen shot of. E.g. http://webserver/phpvirtualbox/screen.php?vm=VMName");
	exit;
}

require_once(dirname(__FILE__).'/config.php');
require_once(dirname(__FILE__).'/lib/vboxconnector.php');

//Set no caching
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT", true);
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT", true);
header("Cache-Control: max-age=0, no-store, no-cache, must-revalidate, post-check=0, pre-check=0", true);
header("Pragma: no-cache", true);


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
} else {
	$force_width = ($settings->screenShotWidth ? $settings->screenShotWidth : 800);
}


try {

	//Get a list of registered machines
	$machine = $vbox->__getMachineRef($_REQUEST['vm']);
	switch($machine->state) {
		case 'Running':
		case 'Saved':
		case 'Restoring':
			break;
		default:
			$machine->releaseRemote();
			throw new Exception('The specified virtual machine is not in a Running state.');
	}

	$_REQUEST['vm'] = $machine->id;

	$vbox->session = $vbox->websessionManager->getSessionObject($vbox->vbox);
	$machine->lockMachine($vbox->session,'Shared');

	// Take active screenshot if machine is running
	if($machine->state == 'Running') {

		$res = $vbox->session->console->display->getScreenResolution(0);

	    $screenWidth = array_shift($res);
	    $screenHeight = array_shift($res);

		$factor  = (float)$force_width / (float)$screenWidth;

		$screenWidth = $force_width;
		if($factor > 0) {
			$screenHeight = $factor * $screenHeight;
		} else {
			$screenHeight = ($screenWidth * 3.0/4.0);
		}

		$imageraw = $vbox->session->console->display->takeScreenShotToArray(0,$screenWidth, $screenHeight);

	} else {

		$res = $machine->querySavedThumbnailSize(0);

		$screenWidth = $res[1];
	    $screenHeight = $res[0];

    	$factor  = (float)$force_width / (float)$screenWidth;

		$screenWidth = $force_width;
		if($factor > 0) {
			$screenHeight = $factor * $screenHeight;
		} else {
			$screenHeight = ($screenWidth * 3.0/4.0);
		}

		$imageraw = $machine->readSavedThumbnailToArray(false, $screenWidth, $screenHeight);

	}
	$vbox->session->unlockMachine();
	$vbox->session = null;


    $image = imagecreatetruecolor($screenWidth, $screenHeight);


    for ($height = 0; $height < $screenHeight; $height++) {

		for ($width = 0; $width < $screenWidth; $width++) {

			$start = ($height*$screenWidth + $width)*4;
			$red = $imageraw[$start];
			$green = $imageraw[$start+1];
			$blue = $imageraw[$start+2];
			$imageraw[$start] = $imageraw[$start+1] = $imageraw[$start+2] = $imageraw[$start+3] = null;

			$colour = imagecolorallocate($image, $red, $green, $blue);

            imagesetpixel($image, $width, $height, $colour);
		}
	}


} catch (Exception $ex) {

	$string = $ex->getMessage();

	// Ensure we close the VM Session if we hit a error, ensure we don't have a aborted VM
	if($vbox->session) $vbox->session->unlockMachine();

	if($_REQUEST['debug']) {

		$font = 6;
		$width = ImageFontWidth($font)* strlen($string);
		$height = ImageFontHeight($font);
		$image = ImageCreate($width,$height);

		$x=imagesx($image)-$width ;
		$y=imagesy($image)-$height;
		$background_color = imagecolorallocate ($image, 255, 255, 255);
		$text_color = imagecolorallocate ($image, 0, 0, 0);
		imagestring($image, $font, $x, $y, $string, $text_color);

	} else {
		$image = ImageCreate(1,1);
		$b = imagecolorallocate ($image, 0, 0, 0); // black bg
		imagefill($image,0,0,$b);
	}

}


header("Content-type: image/png",true);
imagepng($image);
