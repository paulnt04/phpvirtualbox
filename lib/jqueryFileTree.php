<?php
//
// jQuery File Tree PHP Connector
//
// Version 1.01
//
// Cory S.N. LaViska
// A Beautiful Site (http://abeautifulsite.net/)
// 24 March 2008
//
// History:
//
// 1.01 - updated to work with foreign characters in directory/file names (12 April 2008)
// 1.00 - released (24 March 2008)
//
// Output a list of files for jQuery File Tree
//
//	]--- Modified by Ian Moore for phpVirtualBox
//
// $Id$
//
//

global $vbox, $localbrowser, $allowed;

require_once(dirname(dirname(__FILE__)).'/config.php');
require_once(dirname(__FILE__).'/utils.php');
require_once(dirname(__FILE__).'/vboxconnector.php');

$vbox = new vboxconnector();
$vbox->connect();


$settings = new phpVBoxConfig();


$allowed = explode(',',strtolower($settings->browserRestrictFiles));
if($allowed[0]) $allowed = array_combine($allowed,$allowed);
else $allowed = array();

$folders = explode(',',$settings->browserRestrictFolders);
if($folders[0]) $folders = array_combine($folders,$folders);
else $folders = array();

error_reporting(E_ALL ^ E_NOTICE);

/*
 * Clean request
 */
$vboxRequest = clean_request();


// Force localbrowser if we're on the same host or sunos is detected
$vbhost = parse_url($settings->location);
$vbhost = $vbhost['host'];
$localbrowser = ($vbhost == 'localhost' || $vbhost == '127.0.0.1' || $settings->browserLocal);

if($localbrowser) {
	define('DSEP', DIRECTORY_SEPARATOR);
} else if(stripos($vbox->vbox->host->operatingSystem,'windows') === false) {
	define('DSEP','/');
} else {
	define('DSEP','\\');
}

/* In some cases, "dir" passed is just a file name */
if(strpos($vboxRequest['dir'],DSEP)===false) $vboxRequest['dir'] = DSEP;


$dir = $vboxRequest['dir'];
/* Check that folder restriction validates if it exists */
if($vboxRequest['dir'] != '/' && count($folders)) {
	$valid = false;
	foreach($folders as $f) {
		if(strpos(strtoupper($dir),strtoupper($f)) === 0) {
			$valid = true;
			break;
		}
	}
	if(!$valid) {
		folder_start();
		echo('<li>Access denied to '.htmlentities($dir).' (' . htmlentities(urldecode($vboxRequest['dir'])).')</li>');
		folder_end();
		$vboxRequest['dir'] = '/';
	}
}

/* Folder Restriction with root '/' requested */
if($vboxRequest['dir'] == '/' && count($folders)) {
	folder_start();
	foreach($folders as $f) folder_folder($f,true);
	folder_end();
	return;
}

/* Full, expanded path to $dir */
if($vboxRequest['fullpath']) {
	folder_start();
	if(count($folders)) {
		folder_start();
		foreach($folders as $f) {
			if((strtoupper($dir) != strtoupper($f)) && strpos(strtoupper($dir),strtoupper($f)) === 0) {
				folder_folder($f,true,true);
				$path = substr($dir,strlen($f)+1);
				$path = preg_split('/[\/|\\\]/',$path);
				printdir($f,$path);
			} else {
				folder_folder($f,true);
			}
		}
		folder_end();
	} else {

		$dir = preg_split('/[\/|\\\]/',$dir);
		$root = array_shift($dir).DSEP;
		folder_folder($root,true,true);
		printdir($root,$dir);
		echo('</li>');
	}

	folder_end();
	return;
}


/* Default action. Return dir requested */
printdir($dir);

function printdir($dir, $recurse=array()) {

	global $vbox, $localbrowser, $allowed;

	if($localbrowser) return printdirlocal($dir,$recurse);

	try {


		if(substr($dir,-1) != '\\' && substr($dir,-1) != '/') $dir .= DSEP;

		$appl = $vbox->vbox->createAppliance();
		$vfs = $appl->createVFSExplorer('file://'.str_replace(DSEP.DSEP,DSEP,$dir));
		$progress = $vfs->update();
		$progress->waitForCompletion(-1);
		$progress->releaseRemote();
		list($files,$types) = $vfs->entryList();
		$vfs->releaseRemote();
		$appl->releaseRemote();

	} catch (Exception $e) {

		echo($e->getMessage());

		return;

	}

	// Sort files while preserving file / type
	$files = @array_combine($files,$types);
	@uksort($files,'strnatcasecmp');
	$types = @array_combine(range(0,count($files)-1),$files);
	$files = @array_keys($files);


	// Shift . and ..
	while($files[0] == '.' || $files[0] == '..') { array_shift($files); array_shift($types); }

	if(!count($files)) return;

	folder_start();

	// All dirs
	for($i = 0; $i < count($files); $i++) {
		$file = $files[$i];
		$file = $dir.$file;
		// Folder
		if($types[$i] == 4) {
			if(count($recurse) > 1 && (strcasecmp($recurse[0],vbox_basename($file)) == 0)) {
				folder_folder($file,false,true);
				printdir($dir.array_shift($recurse),$recurse);
				echo('</li>');
			} elseif(count($recurse) == 1 && (strcasecmp($recurse[0],vbox_basename($file)) == 0)) {
				folder_folder($file,false,false,true);
			} else {
				folder_folder($file);
			}
		}
	}
	if(!$vboxRequest['dirsOnly']) {
		// All files
		for($i = 0; $i < count($files); $i++) {
			$file = $files[$i];
			$file = str_replace(DSEP.DSEP,DSEP,$dir.DSEP.$file);

			if($types[$i] != 4) {

				$ext = strtolower(preg_replace('/^.*\./', '', $file));

				if(count($allowed) && !@$allowed['.'.strtolower($ext)]) continue;

				folder_file($file);
			}
		}
	}
	folder_end();

}

function printdirlocal($dir, $recurse=array()) {

	global $allowed;

	if(!(file_exists($dir) && ($files = @scandir($dir)))) return;

	@natcasesort($files);

	// Shift . and ..
	while($files[0] == '.' || $files[0] == '..') array_shift($files);

	if(!count($files)) return;

	folder_start();

	// All dirs
	foreach( $files as $file ) {
		$file = $dir.DSEP.$file;
		if( file_exists($file) && is_dir($file) ) {
			if(count($recurse) > 1 && (strcasecmp($recurse[0],vbox_basename($file)) == 0)) {
				folder_folder($file,false,true);
				printdir($dir.DSEP.array_shift($recurse),$recurse);
				echo('</li>');
			} elseif(count($recurse) == 1 && (strcasecmp($recurse[0],vbox_basename($file)) == 0)) {
				folder_folder($file,false,false,true);
			} else {
				folder_folder($file);
			}
		}
	}
	if(!$vboxRequest['dirsOnly']) {
		// All files
		foreach( $files as $file ) {
			$file = $dir.DSEP.$file;
			if( file_exists($file) && !is_dir($file) ) {

				$ext = strtolower(preg_replace('/^.*\./', '', $file));

				if(count($allowed) && !$allowed['.'.$ext]) continue;

				folder_file($file);
			}
		}
	}
	folder_end();

}

function vbox_basename($b) { return substr($b,strrpos($b,DSEP)+1); }
function folder_file($f) {
	$ext = strtolower(preg_replace('/^.*\./', '', $f));
	echo "<li class=\"file file_{$ext} vboxListItem\"><a href=\"#\" name='".htmlentities($f)."' rel=\"".htmlentities($f)."\">".htmlentities(vbox_basename($f))."</a></li>";
}
function folder_folder($f,$full=false,$expanded=false,$selected=false) {
	echo "<li class=\"directory ".($expanded ? 'expanded' : 'collapsed')." vboxListItem".($selected ? 'Selected' : '')."\"><a href=\"#\" name='".htmlentities($f)."' rel=\"".htmlentities($f)."\">".htmlentities(($full ? $f : vbox_basename($f)))."</a>".($expanded ? '' : '</li>');
}

function folder_start() { echo "<ul class=\"jqueryFileTree\" style=\"display: none;\">"; }
function folder_end() { echo ("</ul>"); }
