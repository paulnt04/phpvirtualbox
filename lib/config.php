<?php
/*
 * phpVirtualBox configuration class
 * $Id$
 *
 */
class phpVBoxConfigClass {

/* DEFAULTS */
	
/* Default language. See languages folder for more language options.
 * Can also be changed in File -> Preferences -> Language in
 * phpVirtualBox.
 */
var $language = 'en_us';

// Preview box pixel width
var $previewWidth = 180;

/*
Allow to prompt deletion harddisk files on removal from Virtual Media Manager.
If this is not set, files are always kept. If this is set, you will be PROMPTED
to decide whether or not you would like to delete the harddisk file(s) when you
remove a harddisk from virtual media manager. You may still choose not to delete
the file when prompted.
*/
var $deleteOnRemove = true;

/*
 * File / Folder browser settings
 */

// Restrict file types
var $browserRestrictFiles = array('.iso','.vdi','.vmdk','.img','.bin','.vhd','.hdd','.ovf','.ova','.xml','.vbox');

/* Screen resolutions for console tab */
var $consoleResolutions = array('640x480','800x600','1024x768');

/* Max number of network cards per VM. Do not set above VirtualBox's limit (typically 8) or below 1 */
var $nicMax = 4;

/*
 * Cache tweeking.
 *
 */

/*
 * Timings.
 * NOT a good idea to set any of these unless asked to do so.
 */

var $cacheSettings = array(
		'getHostDetails' => 86400, // "never" changes. 1 day
		'getGuestOSTypes' => 86400,
		'getSystemProperties' => 86400,
		'getHostNetworking' => 86400,
		'getMediums' => 600, // 10 minutes
		'getVMs' => 2,
		'__getMachine' => 7200, // 2 hours
		'__getNetworkAdapters' => 7200,
		'__getStorageControllers' => 7200,
		'__getSerialPorts' => 7200,
		'__getSharedFolders' => 7200,
		'__getUSBController' => 7200,
);
	
/*
 * Multiple server functionality
 */
function __construct() {
	
	@include_once(dirname(dirname(__FILE__)).'/config.php');

	if(class_exists('phpVBoxConfig')) {
		$c = new phpVBoxConfig();
		foreach(get_object_vars($c) as $k => $v) {
			// Safety checks
			if($k == 'browserRestrictFiles' && !is_array($v)) continue;
			if($k == 'consoleResolutions' && !is_array($v)) continue;
			if($k == 'browserRestrictFolders' && !is_array($v)) continue;
			$this->$k = $v;
		}
			
	} else {
		$this->warnDefault = true;
	}
		
	// Ignore any server settings if we have servers
	// in the servers array
	if(is_array($this->servers) && is_array($this->servers[0])) {
		unset($this->location);
		unset($this->user);
		unset($this->pass);
	}
	// Set to selected server based on browser cookie
	if(@$_COOKIE['vboxServer'] && is_array($this->servers) && count($this->servers)) {
		foreach($this->servers as $s) {
			if($s['name'] == $_COOKIE['vboxServer']) {				
				foreach($s as $k=>$v) $this->$k = $v;
			}
		}
	// If servers is not an array, set to empty array
	} elseif(!is_array($this->servers)) {
		$this->servers = array();
	}
	// We still have no server set, use the first one from
	// the servers array
	if(!$this->location && @is_array($this->servers[0])) {
		foreach($this->servers[0] as $k=>$v) $this->$k = $v;
	}
	// Make sure name is set
	if(!$this->name) {
		$this->name = parse_url($this->location);
		$this->name = $this->name['host'];
	}
	
	$this->key = md5($this->location.$this->username);
	
	// legacy rdpHost setting
	if(@$this->rdpHost && !@$this->consoleHost)
		$this->consoleHost = $this->rdpHost;

	
}

}



