<?php
/*
 * phpVirtualBox configuration class
 * $Id$
 *
 */
class phpVBoxConfigClass {

/* DEFAULTS */
	
var $language = 'en_us';

var $previewWidth = 180;
var $previewAspectRatio = 1.6;

var $deleteOnRemove = true;

var $sessionSecurityLevel = 2;

var $browserRestrictFiles = array('.iso','.vdi','.vmdk','.img','.bin','.vhd','.hdd','.ovf','.ova','.xml','.vbox','.cdr','.dmg','.ima','.dsk','.vfd');

var $consoleResolutions = array('640x480','800x600','1024x768','1280x720','1440x900');
var $nicMax = 4;

var $vmConfigRefresh = true;

var $vmListSort = 'name';

/*
 * Cache tweeking.
 *
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
		'getVMSortOrder' => 300 // 5 minutes
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
				break;
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
		
	// Ensure authlib is set
	if(!$this->authLib) $this->authLib = 'Builtin';
	
	include_once(dirname(__FILE__).'/auth/'.str_replace(array('.','/','\\'),'',$this->authLib).'.php');
	
	$alib = "phpvbAuth{$this->authLib}";
	$this->auth = new $alib(@$this->authConfig);
	$this->authCapabilities = $this->auth->capabilities;
}

// Set Server
function setServer($server) {
	foreach($this->servers as $s) {
		if($s['name'] == $server) {				
			foreach($s as $k=>$v) $this->$k = $v;
			break;
		}
	}
}
// Set server for authentication
function getServerAuthMaster() {
	foreach($this->servers as $s) {
		if($s['authMaster']) {				
			return $s['name'];
		}
	}
	return $this->servers[0]['name'];
}

}



