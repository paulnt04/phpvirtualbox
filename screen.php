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

$settings = new phpVBoxConfig();

$vbox = new vboxconnector();
$vbox->connect();

// Since the vbox screenshot function returns 4 bytes per pixel
// AND we need to build an image from each pixel, the memory usage
// of this script can get rather large. 800px wide usually keeps
// it right under 150M.
ini_set('memory_limit', '512M'); // so swap if you need to swap
if($_REQUEST['width']) {
	$force_width = $_REQUEST['width'];
} else {
	$force_width = ($settings->screenShotWidth ? $settings->screenShotWidth : 800);
}


try {

	//Get a list of registered machines
	$machine = $vbox->__getMachineRef($_REQUEST['vm']);
	if ( 'Running' != $machine->state ) {
		$machine->releaseRemote();
		throw new Exception('The specified virtual machine is not in a Running state.');
	}

	$_REQUEST['vm'] = $machine->id;
	$machine->releaseRemote();

	$vbox->session = $vbox->websessionManager->getSessionObject($vbox->vbox);
	$vbox->vbox->openExistingSession($vbox->session, $_REQUEST['vm']);

	$res = $vbox->session->console->display->getScreenResolution(0);

    $screenWidth = array_shift($res);
    $screenHeight = array_shift($res);

	$factor  = (float)$force_width / (float)$screenWidth;
	$screenHeight = $factor * $screenHeight;
	$screenWidth = $force_width;

	$imageraw = $vbox->session->console->display->takeScreenShotToArray(0,$screenWidth, $screenHeight);

	$vbox->session->close();
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
	if($vbox->session) $vbox->session->close();


	$font = 6;
	$width = ImageFontWidth($font)* strlen($string);
	$height = ImageFontHeight($font);
	$im = ImageCreate($width,$height);

	$x=imagesx($im)-$width ;
	$y=imagesy($im)-$height;
	$background_color = imagecolorallocate ($im, 242, 242, 242); //white background
	$text_color = imagecolorallocate ($im, 0, 0,0);//black text
	imagestring ($im, $font, $x, $y, $string, $text_color);

	//$image = imagecreatetruecolor(800, 600);
	header("content-type: image/png");
	imagepng($im);




	return;
}


if($_REQUEST['debug']) {
	echo('<pre>');
	echo("Completed\n");
	if(function_exists('memory_get_peak_usage'))
		echo('Peak memory usage: ' . ((memory_get_peak_usage(true) / 1024) / 1024) ."MB\n");
	echo('</pre>');
} else {
	header("content-type: image/png",true);
	imagepng($image);
}