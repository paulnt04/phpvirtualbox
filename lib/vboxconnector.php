<?php
/*
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 */

class vboxconnector {

	// Fatal error number
	const PHPVB_ERRNO_FATAL = 32;
	// Connection error number
	const PHPVB_ERRNO_CONNECT = 64;

	/* VBOX Constants */
	var $resultcodes = array(
		'0x80BB0001' => 'VBOX_E_OBJECT_NOT_FOUND',
		'0x80BB0002' => 'VBOX_E_INVALID_VM_STATE',
		'0x80BB0003' => 'VBOX_E_VM_ERROR',
		'0x80BB0004' => 'VBOX_E_FILE_ERROR',
		'0x80BB0005' => 'VBOX_E_IPRT_ERROR',
		'0x80BB0006' => 'VBOX_E_PDM_ERROR',
		'0x80BB0007' => 'VBOX_E_INVALID_OBJECT_STATE',
		'0x80BB0008' => 'VBOX_E_HOST_ERROR',
		'0x80BB0009' => 'VBOX_E_NOT_SUPPORTED',
		'0x80BB000A' => 'VBOX_E_XML_ERROR',
		'0x80BB000B' => 'VBOX_E_INVALID_SESSION_STATE',
		'0x80BB000C' => 'VBOX_E_OBJECT_IN_USE'
	);


	// Any exceptions generated
	var $errors = array();

	// Is set to true when a progress operation is created
	var $progressCreated = false;

	public function __construct ($useAuthMaster = false) {

		require_once(dirname(__FILE__).'/cache.php');
		require_once(dirname(__FILE__).'/language.php');
		require_once(dirname(__FILE__).'/vboxServiceWrappers.php');

		/* Set.. .. settings */
		$settings = new phpVBoxConfigClass();

		// Are default settings being used?
		if($settings->warnDefault) {
			throw new Exception("No configuration found. Rename the file <b>config.php-example</b> in phpVirtualBox's folder to <b>config.php</b> and edit as needed.<p>For more detailed instructions, please see the installation wiki on phpVirtualBox's web site. <p><a href='http://code.google.com/p/phpvirtualbox/w/list' target=_blank>http://code.google.com/p/phpvirtualbox/w/list</a>.</p>",vboxconnector::PHPVB_ERRNO_FATAL);
		}
		
		// Check for SoapClient class
		if(!class_exists('SoapClient')) {
			throw new Exception('PHP does not have the SOAP extension enabled.',vboxconnector::PHPVB_ERRNO_FATAL);
		}

		// use authentication master server?
		if($useAuthMaster) {
			$settings->setServer($settings->getServerAuthMaster());
		}
		
		$this->settings = get_object_vars($settings);
		if(!$this->settings['nicMax']) $this->settings['nicMax'] = 4;

		// Cache handler object.
		$this->cache = new cache();

		if($this->settings['cachePath']) $this->cache->path = $this->settings['cachePath'];
		
		$this->cache->prefix = 'pvbx-'.md5($this->settings['key'].'$Revision$').'-';

	}

	/*
	 *
	 * Connect to vboxwebsrv
	 *
	 */
	private function __vboxwebsrvConnect() {

		// Already connected?
		if($this->connected) return true;
		
		// Valid session?
		if(!$this->skipSessionCheck && !$_SESSION['valid']) {
			throw new Exception(trans('Not logged in.'),vboxconnector::PHPVB_ERRNO_FATAL);
		}

		//Connect to webservice
		$this->client = new SoapClient(dirname(__FILE__)."/vboxwebService.wsdl",
		    array(
		    	'features' => (SOAP_USE_XSI_ARRAY_TYPE + SOAP_SINGLE_ELEMENT_ARRAYS),
		        'cache_wsdl'=>WSDL_CACHE_MEMORY,
		        'trace'=>($this->settings['debugSoap']),
				'connection_timeout' => ($this->settings['connectionTimeout'] ? $this->settings['connectionTimeout'] : 20),
		        'location'=>$this->settings['location']
		    ));
		    

		/* Try / catch / throw here hides login credentials from exception if one is thrown */
		try {
			$this->websessionManager = new IWebsessionManager($this->client);
			$this->vbox = $this->websessionManager->logon($this->settings['username'],$this->settings['password']);
		} catch (Exception $e) {
			if(!($msg = $e->getMessage()))
			$msg = 'Error logging in to vboxwebsrv.';
			throw new Exception($msg,vboxconnector::PHPVB_ERRNO_CONNECT);
		}


		// Error logging in
		if(!$this->vbox->handle) {
			throw new Exception('Error logging in or connecting to vboxwebsrv.',vboxconnector::PHPVB_ERRNO_CONNECT);
		}

		return ($this->connected = true);
	}


	/*
	 * Return and / or set version
	 */
	public function getVersion() {

		if(!$this->version) {

			$this->__vboxwebsrvConnect();

			$this->version = explode('.',$this->vbox->version);
			$this->version = array(
				'ose'=>(stripos($this->version[2],'ose') > 0),
				'string'=>join('.',$this->version),
				'major'=>intval(array_shift($this->version)),
				'minor'=>intval(array_shift($this->version)),
				'sub'=>intval(array_shift($this->version)),
				'revision'=>(string)$this->vbox->revision,
				'settingsFilePath' => $this->vbox->settingsFilePath
			);
		}

		return $this->version;

	}

	/*
	 * Always logout & close session
	 */
	public function __destruct () {

		// Do not logout if there is a progress operation associated
		// with this vboxweb session
		if($this->connected && !$this->progressCreated && $this->vbox->handle) {

			if($this->session && $this->session->handle) {
				try {$this->session->unlockMachine();}
				catch (Exception $e) { }
			}

			$this->websessionManager->logoff($this->vbox->handle);
		}

		unset($this->client);
	}


	/*
	 * __call overloader. Handles caching
	 */
	function __call($fn,$args) {

		// Valid session?
		if(!$this->skipSessionCheck && !$_SESSION['valid']) {
			throw new Exception(trans('Not logged in.'),vboxconnector::PHPVB_ERRNO_FATAL);
		}
				
		$req = &$args[0];
		$response = &$args[1][0]; # fix for allow_call_time_pass_reference = Off setting


		/*
		 * Special Cases First
		 *
		 */

		# Setting VM states
		if(strpos($fn,'setStateVM') === 0) {

			$this->__setVMState($req['vm'],substr($fn,10),$response);

		# Getting enumeration values
		} else if(strpos($fn,'getEnum') === 0) {

			$this->__getEnumerationMap(substr($fn,7),$response);

		# Access to other methods goes through caching
		} elseif(method_exists($this,$fn.'Cached')) {

			// do not cache
			if(!@$this->settings['cacheSettings'][$fn]) {

				$this->{$fn.'Cached'}($req,$response);

			// cached data exists ? return it : get data, cache data, return data
			} else if(@$req['force_refresh'] || (($response['data'] = $this->cache->get($fn,@$this->settings['cacheSettings'][$fn])) === false)) {

				$lock = $this->cache->lock($fn);

				// file was modified while attempting to lock.
				// file data is returned
				if($lock === null) {
					$response['data'] = $this->cache->get($fn,@$this->settings['cacheSettings'][$fn]);

				// lock obtained (hopefully)
				} else {
					$this->{$fn.'Cached'}($req,$response);
					if($this->cache->store($fn,$response['data']) === false && $response['data'] !== false) {
						throw new Exception("Error storing cache.");
					}
				}

			}

		// Not found
		} else {

			throw new Exception('Undefined method: ' . $fn);

		}

		return $response;
	}

	/*
	 * Public access to connect
	 */
	public function connect() {
		return $this->__vboxwebsrvConnect();
	}

	/*
	 * Enumerate guest properties of vm
	 */
	public function enumerateGuestProperties($args,&$response) {

		$this->__vboxwebsrvConnect();

		$m = $this->vbox->findMachine($args['vm']);

		$response['data'] = $m->enumerateGuestProperties($args['pattern']);
		$m->releaseRemote();

		return true;

	}

	/*
	 * Save Running VM shared folder settings
	 */
	public function saveVMSharedFolders($args,&$response) {

		$this->__vboxwebsrvConnect();
		
		$machine = $this->vbox->findMachine($args['id']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');
		
		// Compose incoming list
		$sf_inc = array();
		foreach($args['sharedFolders'] as $s) {
			$sf_inc[$s['name']] = $s;
		}
		
		
		// Get list of perm shared folders
		$psf_tmp = $this->session->machine->sharedFolders;
		$psf = array();
		foreach($psf_tmp as $sf) {
			$psf[$sf->name] = $sf;
		}

		// Get a list of temp shared folders
		$tsf_tmp = $this->session->console->sharedFolders;
		$tsf = array();
		foreach($tsf_tmp as $sf) {
			$tsf[$sf->name] = $sf;
		}

		/*
		 *  Step through list and remove non-matching folders
		 */
		foreach($sf_inc as $sf) {
			
			// Already exists in list. Check Settings.
			if($psf[$sf['name']] || $tsf[$sf['name']]) {
				
				/* Remove if it doesn't match */
				if(($tsf[$sf['name']] && $sf['type'] == 'machine') || ($psf[$sf['name']] && $sf['type'] != 'machine') || $sf['hostPath'] != $psf[$sf['name']]->hostPath || (bool)$sf['autoMount'] != (bool)$psf[$sf['name']]->autoMount || (bool)$sf['writable'] != (bool)$psf[$sf['name']]->writable) {
					
					if($psf[$sf['name']]) $this->session->machine->removeSharedFolder($sf['name']);
					else $this->session->console->removeSharedFolder($sf['name']);
					
					unset($psf[$sf['name']]);
					unset($tsf[$sf['name']]);
					
				/* Matches. Don't add or remove. */
				} else {
					unset($psf[$sf['name']]);
					unset($tsf[$sf['name']]);
					continue;
				}
			}
			
			// Does not exist or was removed. Add it.
			if($sf['type'] != 'machine') $this->session->console->createSharedFolder($sf['name'],$sf['hostPath'],(bool)$sf['writable'],(bool)$sf['autoMount']);
			else $this->session->machine->createSharedFolder($sf['name'],$sf['hostPath'],(bool)$sf['writable'],(bool)$sf['autoMount']);
		}

		/*
		 * Remove remaining
		 */
		foreach($psf as $sf) $this->session->machine->removeSharedFolder($sf->name);
		foreach($tsf as $sf) $this->session->console->removeSharedFolder($sf->name);
		
		// Expire shared folder info
		$this->cache->expire('__getSharedFolders'.$args['id']);
		
		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$machine->releaseRemote();
		$this->session->releaseRemote();
		unset($this->session);
		
	}

	/*
	 * Install Guest Additions in VM
	 */
	public function installGuestAdditions($args,&$response) {
		
		$this->__vboxwebsrvConnect();
		
		$response['data'] = array();
		$gem = null;
		foreach($this->vbox->DVDImages as $m) {
			if(strtolower($m->name) == 'vboxguestadditions.iso') {
				$gem = $m;
				break;
			}
			$m->releaseRemote();
		}
		
		// Not in media registry. Try to register it.
		if(!$gem) {
			$checks = array(
				'linux' => '/usr/share/virtualbox/VBoxGuestAdditions.iso',
				'osx' => '/Applications/VirtualBox.app/Contents/MacOS/VBoxGuestAdditions.iso',
				'sunos' => '/opt/VirtualBox/additions/VBoxGuestAdditions.iso',
				'windows' => 'C:\\Program Files\\Oracle\\VirtualBox\\VBoxGuestAdditions.iso',
				'windowsx86' => 'C:\\Program Files (x86)\\Oracle\\VirtualBox\\VBoxGuestAdditions.iso' // Does this exist?
			);
			$hostos = $vbox->vbox->host->operatingSystem;
			if(stripos($hostos,'windows') !== false) {
				$checks = array($checks['windows'],$checks['windowsx86']);
			} elseif(stripos($hostos,'solaris') !== false || stripos($hostos,'sunos') !== false) {
				$checks = array($checks['sunos']);
			} elseif(stripos($hostos,'mac') !== false || stripos($hostos,'apple') !== false || stripos($hostos,'osx') !== false || stripos($hostos,'os x') !== false || stripos($hostos,'darwin') !== false) {
				$checks = array($checks['osx']);
			} elseif(stripos($hostos,'linux') !== false) {
				$checks = array($checks['linux']);
			}
			
			// Check for config setting
			if(@$this->settings['vboxGuestAdditionsISO'])
				$checks = array($this->settings['vboxGuestAdditionsISO']);
			
			// Unknown os and no config setting leaves all checks in place.
			
			// Try to register medium.
			foreach($checks as $iso) {
				try {
					$gem = $this->vbox->openMedium($iso,'DVD','ReadWrite');
					$this->cache->expire('getMediums');
					break;
				} catch (Exception $e) {
					// Ignore
				}
			}
		}
		
		// No guest additions found
		if(!$gem) {
			throw new Exception("Could not find VirtualBox guest additions ISO image.");
		}
		
		// create session and lock machine
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');
		
		// Try update from guest if it is supported
		try {
			$progress = $this->session->console->guest->updateGuestAdditions($gem->location);
			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$response['data']['result'] = $this->resultcodes['0x'.strtoupper(dechex($progress->resultCode))];
					$response['data']['reason'] = $progress->errorInfo->text;
					$progress->releaseRemote();
				}				
			} catch (Exception $null) {
				// No error info. Save progress.
				$gem->releaseRemote();
				$this->__storeProgress($progress);
				$response['data']['progress'] = $progress->handle;
				$this->cache->expire('__getStorageControllers'.$args['vm']);
				$this->cache->expire('getMediums');
				return true;
			}
			
		} catch (Exception $e) {
			// Error. Assume updateGuestAdditions is not supported.
		}
		
		// updateGuestAdditions is not supported. Just try to mount image.
		$response['data']['result'] = 'nocdrom';
		$mounted = false;
		foreach($machine->storageControllers as $sc) {
			foreach($machine->getMediumAttachmentsOfController($sc->name) as $ma) {
				if($ma->type->__toString() == 'DVD') {
					$this->session->machine->mountMedium($sc->name, $ma->port, $ma->device, $gem->handle, true);
					$response['data']['result'] = 'mounted';
					$this->cache->expire('__getStorageControllers'.$args['vm']);
					$this->cache->expire('getMediums');
					$mounted = true;
					break;
				}
			}
			$sc->releaseRemote();
			if($mounted) break;
		}

		/* Probably should not save Guest Additions cd mount */
		/*
		if($mounted && strtolower($this->session->machine->getExtraData('GUI/SaveMountedAtRuntime')) == 'yes')
			$this->session->machine->saveSettings();
		*/
			
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		$gem->releaseRemote();
		
	}

	/*
	 * Attach USB devices to VM
	 */
	public function usbAttachDevice($args,&$response) {

		$this->__vboxwebsrvConnect();
		
		// create session and lock machine
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');
		
		$this->session->console->attachUSBDevice($args['id']);

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		
		$response['data']['result'] = 1;
	}

	/*
	 * Attach USB devices to VM
	 */
	public function usbDetachDevice($args,&$response) {

		$this->__vboxwebsrvConnect();
		
		// create session and lock machine
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');
		
		$this->session->console->detachUSBDevice($args['id']);

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		
		$response['data']['result'] = 1;
	}
	
	/*
	 * Save Running VM USB device attachments
	 */
	public function saveVMUSBDevices($args,&$response) {

		$this->__vboxwebsrvConnect();
		
		// create session and lock machine
		$machine = $this->vbox->findMachine($args['id']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');
		
		// Existing USB devices
		$attached = array();
		foreach($this->session->console->USBDevices as $u) {
			$attached[$u->id] = 1;
		}
		
		if(!is_array($args['usbdevs'])) $args['usbdevs'] = array();
		foreach($args['usbdevs'] as $u) {
			try {
				if(intval($u['attached']) && !$attached[$u['id']])
					$this->session->console->attachUSBDevice($u['id']);
				else if(!intval($u['attached']) && $attached[$u['id']])
					$this->session->console->detachUSBDevice($u['id']);
			} catch (Exception $e) {
				$this->errors[] = $e;
			}
		}

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		
	}
	
	/*
	 * Save Running VM Network settings
	 */
	public function saveVMNetwork($args,&$response) {
		
		$this->__vboxwebsrvConnect();
		
		// create session and lock machine
		$machine = $this->vbox->findMachine($args['id']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');

		// Network Adapters
		$netprops = array('internalNetwork','NATNetwork','cableConnected','attachmentType');
		
		for($i = 0; $i < count($args['networkAdapters']); $i++) {

			$n = $this->session->machine->getNetworkAdapter($i);
			
			// Skip disabled adapters
			if(!$n->enabled) {
				$n->releaseRemote();
				continue;
			}
			
			$n->cableConnected = intval($args['networkAdapters'][$i]['cableConnected']);
			for($p = 0; $p < count($netprops); $p++) {
				switch($netprops[$p]) {
					case 'attachmentType':
					case 'internalNetwork':
					case 'VDENetwork':
					case 'enabled':
					case 'cableConnected':
						break;
					default:
						$n->{$netprops[$p]} = $args['networkAdapters'][$i][$netprops[$p]];
				}
			}
				
			switch($args['networkAdapters'][$i]['attachmentType']) {
				case 'Bridged':
					$n->attachToBridgedInterface();
					break;
				case 'Internal':
					$n->attachToInternalNetwork();
					$n->internalNetwork = (string)$args['networkAdapters'][$i]['internalNetwork'];
					break;
				case 'HostOnly':
					$n->attachToHostOnlyInterface();
					break;
				case 'NAT':
					$n->attachToNAT();

					// Remove existing redirects
					foreach($n->NatDriver->getRedirects() as $r) {
						$n->NatDriver->removeRedirect(array_shift(split(',',$r)));
					}
					// Add redirects
					foreach($args['networkAdapters'][$i]['redirects'] as $r) {
						$r = split(',',$r);
						$n->NatDriver->addRedirect($r[0],$r[1],$r[2],$r[3],$r[4],$r[5]);
					}

					break;
				default:
					$n->detach();
			}
			$n->releaseRemote();
		}
		
		// Expire network info
		$this->cache->expire('__getNetworkAdapters'.$args['id']);
		$this->cache->expire('getHostNetworking');
		
		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
	}
	
	/*
	 * Save all VM settings
	 */
	public function saveVM($args,&$response) {

		$this->__vboxwebsrvConnect();

		// create session and lock machine
		$machine = $this->vbox->findMachine($args['id']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Write');

		// Version (OSE) checks below
		$version = $this->getVersion();

		// Cache items to expire
		$expire = array();

		// Shorthand
		$m = &$this->session->machine;

		// General machine settings
		$m->name = $args['name'];
		$m->description = $args['description'];
		$m->OSTypeId = $args['OSTypeId'];
		$m->CPUCount = $args['CPUCount'];
		$m->memorySize = $args['memorySize'];
		$m->firmwareType = $args['firmwareType'];
		if($args['chipsetType']) $m->chipsetType = $args['chipsetType']; 
		if($m->snapshotFolder != $args['snapshotFolder']) $m->snapshotFolder = $args['snapshotFolder'];
		if(@$this->settings['enableAdvancedConfig']) $m->hpetEnabled = intval($args['hpetEnabled']);

		$m->VRAMSize = $args['VRAMSize'];

		/* Unsupported at this time
		$m->monitorCount = max(1,intval($args['monitorCount']));
		$m->accelerate3DEnabled = $args['accelerate3DEnabled'];
		$m->accelerate2DVideoEnabled = $args['accelerate2DVideoEnabled'];
		*/

		/* Only if advanced configuration is enabled */
		if($this->settings['enableAdvancedConfig']) {
			$m->keyboardHidType = $args['keyboardHidType'];
			$m->pointingHidType = $args['pointingHidType'];
			$m->setHWVirtExProperty('Enabled',($args['HWVirtExProperties']['Enabled'] ? 1 : 0));
			$m->setHWVirtExProperty('NestedPaging', ($args['HWVirtExProperties']['NestedPaging'] ? 1 : 0));
			$m->setHWVirtExProperty('LargePages', ($args['HWVirtExProperties']['LargePages'] ? 1 : 0));
			$m->setHWVirtExProperty('Exclusive', ($args['HWVirtExProperties']['Exclusive'] ? 1 : 0));
			$m->setHWVirtExProperty('VPID', ($args['HWVirtExProperties']['VPID'] ? 1 : 0));
			
		}

		$m->RTCUseUTC = ($args['RTCUseUTC'] ? 1 : 0);


		$m->setCpuProperty('PAE', ($args['CpuProperties']['PAE'] ? 1 : 0));

		$m->setExtraData('GUI/SaveMountedAtRuntime', ($args['GUI']['SaveMountedAtRuntime'] == 'no' ? 'no' : 'yes'));

		// IOAPIC
		$m->BIOSSettings->IOAPICEnabled = ($args['BIOSSettings']['IOAPICEnabled'] ? 1 : 0);

		// VRDE settings
		try {
			if($m->VRDEServer && $this->vbox->systemProperties->defaultVRDEExtPack) {
				$m->VRDEServer->enabled = intval($args['VRDEServer']['enabled']);
				$m->VRDEServer->setVRDEProperty('TCP/Ports',$args['VRDEServer']['ports']);
				if($this->settings['enableAdvancedConfig'])
					$m->VRDEServer->setVRDEProperty('TCP/Address',$args['VRDEServer']['netAddress']);
				$m->VRDEServer->authType = ($args['VRDEServer']['authType'] ? $args['VRDEServer']['authType'] : null);
				$m->VRDEServer->authTimeout = intval($args['VRDEServer']['authTimeout']);
				$m->VRDEServer->allowMultiConnection = intval($args['VRDEServer']['allowMultiConnection']);
			}
		} catch (Exception $e) {
		}

		// Audio controller settings
		$m->audioAdapter->enabled = ($args['audioAdapter']['enabled'] ? 1 : 0);
		$m->audioAdapter->audioController = $args['audioAdapter']['audioController'];
		$m->audioAdapter->audioDriver = $args['audioAdapter']['audioDriver'];

		// Boot order
		$mbp = $this->vbox->systemProperties->maxBootPosition;
		for($i = 0; $i < $mbp; $i ++) {
			if($args['bootOrder'][$i]) {
				$m->setBootOrder(($i + 1),$args['bootOrder'][$i]);
			} else {
				$m->setBootOrder(($i + 1),null);
			}
		}

		// Expire machine cache
		$expire[] = '__getMachine'.$args['id'];

		// Storage Controllers
		$scs = $m->storageControllers;
		$attachedEx = $attachedNew = array();
		foreach($scs as $sc) {
			$mas = $m->getMediumAttachmentsOfController($sc->name);
			foreach($mas as $ma) {
				$attachedEx[$sc->name.$ma->port.$ma->device] = (($ma->medium->handle && $ma->medium->id) ? $ma->medium->id : null);
				if($ma->controller) {
					$m->detachDevice($ma->controller,$ma->port,$ma->device);
				}
			}
			$scname = $sc->name;
			$sc->releaseRemote();
			$m->removeStorageController($scname);
		}

		// Add New
		foreach($args['storageControllers'] as $sc) {

			$sc['name'] = trim($sc['name']);
			$name = ($sc['name'] ? $sc['name'] : $sc['bus'].' Controller');

			$bust = new StorageBus(null,$sc['bus']);
			$c = $m->addStorageController($name,$bust->__toString());
			$c->controllerType = $sc['controllerType'];
			$c->useHostIOCache = ($sc['useHostIOCache'] ? 1 : 0);

			// Set sata port count
			if($sc['bus'] == 'SATA' && ($this->settings['enableAdvancedConfig'] || !@$this->settings['disableSataPortCount'])) {
				$max = max(1,intval(@$sc['portCount']));
				foreach($sc['mediumAttachments'] as $ma) {
					$max = max($max,(intval($ma['port'])+1));
				}
				$c->portCount = min(intval($c->maxPortCount),max(count($sc['mediumAttachments']),$max));
				
				// Check for "automatic" setting
				if(@$this->settings['enableAdvancedConfig']) {
					if(intval(@$sc['portCount']) == 0)
						$m->setExtraData('phpvb/AutoSATAPortCount','yes');
					else
						$m->setExtraData('phpvb/AutoSATAPortCount','no');
				}				
			}
			$c->releaseRemote();
			
			// Medium attachments
			foreach($sc['mediumAttachments'] as $ma) {
				
				$attachedNew[$name.$ma['port'].$ma['device']] = $ma['medium']['id'];
				
				if(is_array($ma['medium']) && $ma['medium']['id'] && $ma['type']) {

					// Host drive
					if(strtolower($ma['medium']['hostDrive']) == 'true' || $ma['medium']['hostDrive'] === true) {
						// CD / DVD Drive
						if($ma['type'] == 'DVD') {
							$drives = $this->vbox->host->DVDDrives;
						// floppy drives
						} else {
							$drives = $this->vbox->host->floppyDrives;
						}
						foreach($drives as $md) {
							if($md->id == $ma['medium']['id']) {
								$med = &$md;
								break;
							}
							$md->releaseRemote();
						}
					} else {						
						$med = $this->vbox->findMedium($ma['medium']['id'],$ma['type']);
					}
				} else {
					$med = null;
				}
				$m->attachDevice($name,$ma['port'],$ma['device'],$ma['type'],(is_object($med) ? $med->handle : null));
				if($ma['type'] == 'DVD') $m->passthroughDevice($name,$ma['port'],$ma['device'],($ma['passthrough'] ? true : false));
				if(is_object($med)) $med->releaseRemote();
			}

		}
		// Expire storage
		$expire[] = '__getStorageControllers'.$args['id'];
		// Expire mediums?
		ksort($attachedEx);
		ksort($attachedNew);
		if(serialize($attachedEx) != serialize($attachedNew))
			$expire[] = 'getMediums';


		/*
		 *
		 * When changing the following items, try our best to preserve existing
		 * cache at the expense of some PHP processing
		 *
		 */


		// Network Adapters
		$netchanged = false;
		$netprops = array('adapterType','enabled','MACAddress','hostInterface','internalNetwork','NATNetwork','cableConnected','attachmentType');
		if(@$this->settings['enableVDE']) $netprops[] = 'VDENetwork';
		$adapters = $this->__getCachedMachineData('__getNetworkAdapters',$args['id'],$this->session->machine);
		for($i = 0; $i < count($args['networkAdapters']); $i++) {

			// Is there a property diff?
			$ndiff = false;
			foreach($netprops as $p) {
				try {
					if($args['networkAdapters'][$i][$p] == $adapters[$i][$p]) continue;
				} catch (Exception $e) {
					// ignore
				}
				$ndiff = true;
				break;
			}

			// Check for redirection rules diff
			$ndiff = ($ndiff || (@serialize($args['networkAdapters'][$i]['redirects']) != @serialize($adapters[$i]['redirects'])));

			if(!$ndiff) { continue; }
			$netchanged = true;


			$n = $m->getNetworkAdapter($i);
			$n->enabled = intval($args['networkAdapters'][$i]['enabled']);
			$n->cableConnected = intval($args['networkAdapters'][$i]['cableConnected']);
			for($p = 0; $p < count($netprops); $p++) {
				switch($netprops[$p]) {
					case 'attachmentType':
					case 'internalNetwork':
					case 'VDENetwork':
					case 'enabled':
					case 'cableConnected':
						break;
					default:
						$n->{$netprops[$p]} = $args['networkAdapters'][$i][$netprops[$p]];
				}
			}


			switch($args['networkAdapters'][$i]['attachmentType']) {
				case 'Bridged':
					$n->attachToBridgedInterface();
					break;
				case 'VDE':
					$n->attachToVDE();
					$n->VDENetwork = (string)$args['networkAdapters'][$i]['VDENetwork'];
					break;
				case 'Internal':
					$n->attachToInternalNetwork();
					$n->internalNetwork = (string)$args['networkAdapters'][$i]['internalNetwork'];
					break;
				case 'HostOnly':
					$n->attachToHostOnlyInterface();
					break;
				case 'NAT':
					$n->attachToNAT();

					// Remove existing redirects
					foreach($n->NatDriver->getRedirects() as $r) {
						$n->NatDriver->removeRedirect(array_shift(split(',',$r)));
					}
					// Add redirects
					foreach($args['networkAdapters'][$i]['redirects'] as $r) {
						$r = split(',',$r);
						$n->NatDriver->addRedirect($r[0],$r[1],$r[2],$r[3],$r[4],$r[5]);
					}

					break;
				default:
					$n->detach();
			}
			$n->releaseRemote();
		}
		// Expire network info?
		if($netchanged) {
			$expire[] = '__getNetworkAdapters'.$args['id'];
			$expire[] = 'getHostNetworking';
		}

		// Serial Ports
		$spChanged = false;
		for($i = 0; $i < count($args['serialPorts']); $i++) {
			$p = $m->getSerialPort($i);
			if(!($p->enabled || intval($args['serialPorts'][$i]['enabled']))) continue;
			$spChanged = true;
			try {
				$p->enabled = intval($args['serialPorts'][$i]['enabled']);
				$p->IOBase = @hexdec($args['serialPorts'][$i]['IOBase']);
				$p->IRQ = intval($args['serialPorts'][$i]['IRQ']);
				if($args['serialPorts'][$i]['path']) {
					$p->path = $args['serialPorts'][$i]['path'];
					$p->hostMode = $args['serialPorts'][$i]['hostMode'];
				} else {
					$p->hostMode = $args['serialPorts'][$i]['hostMode'];				
					$p->path = $args['serialPorts'][$i]['path'];
				}
				$p->server = intval($args['serialPorts'][$i]['server']);
				$p->releaseRemote();
			} catch (Exception $e) {
				$this->errors[] = $e;
			}
		}
		if($spChanged) $expire[] = '__getSerialPorts'.$args['id'];

		// LPT Ports
		if($this->settings['enableLPTConfig']) {
			$lptChanged = false;
			for($i = 0; $i < count($args['parallelPorts']); $i++) {
				$p = $m->getParallelPort($i);
				if(!($p->enabled || intval($args['parallelPorts'][$i]['enabled']))) continue;
				$lptChanged = true;
				try {
					$p->IOBase = @hexdec($args['parallelPorts'][$i]['IOBase']);
					$p->IRQ = intval($args['parallelPorts'][$i]['IRQ']);
					$p->path = $args['parallelPorts'][$i]['path'];
					$p->enabled = intval($args['parallelPorts'][$i]['enabled']);
					$p->releaseRemote();
				} catch (Exception $e) {
					$this->errors[] = $e;
				}
			}
			if($lptChanged) $expire[] = '__getParallelPorts'.$args['id']; 
		}		

		// Shared Folders
		$sharedchanged = false;
		$sharedEx = array();
		$sharedNew = array();
		foreach($this->__getCachedMachineData('__getSharedFolders',$args['id'],$m) as $s) {
			$sharedEx[$s['name']] = array('name'=>$s['name'],'hostPath'=>$s['hostPath'],'autoMount'=>(bool)$s['autoMount'],'writable'=>(bool)$s['writable']);
		}
		foreach($args['sharedFolders'] as $s) {
			$sharedNew[$s['name']] = array('name'=>$s['name'],'hostPath'=>$s['hostPath'],'autoMount'=>(bool)$s['autoMount'],'writable'=>(bool)$s['writable']);
		}
		// Compare
		if(count($sharedEx) != count($sharedNew) || (@serialize($sharedEx) != @serialize($sharedNew))) {
			$sharedchanged = true;
			foreach($sharedEx as $s) { $m->removeSharedFolder($s['name']);}
			try {
				foreach($sharedNew as $s) {
					$m->createSharedFolder($s['name'],$s['hostPath'],(bool)$s['writable'],(bool)$s['autoMount']);
				}
			} catch (Exception $e) { $this->errors[] = $e; }
		}
		// Expire shared folders?
		if($sharedchanged) $expire[] = '__getSharedFolders'.$args['id'];


		// USB Filters

		$usbchanged = false;
		$usbEx = array();
		$usbNew = array();

		$usbc = $this->__getCachedMachineData('__getUSBController',$args['id'],$this->session->machine);

		// controller properties
		if((bool)$usbc['enabled'] != (bool)$args['USBController']['enabled'] || (bool)$usbc['enabledEhci'] != (bool)$args['USBController']['enabledEhci']) {
			$usbchanged = true;
			$m->USBController->enabled = (bool)$args['USBController']['enabled'];
			$m->USBController->enabledEhci = (bool)$args['USBController']['enabledEhci'];
		}

		// filters
		if(!is_array($args['USBController']['deviceFilters'])) $args['USBController']['deviceFilters'] = array();
		if(count($usbc['deviceFilters']) != count($args['USBController']['deviceFilters']) || @serialize($usbc['deviceFilters']) != @serialize($args['USBController']['deviceFilters'])) {

			$usbchanged = true;

			// usb filter properties to change
			$usbProps = array('vendorId','productId','revision','manufacturer','product','serialNumber','port','remote');

			// Remove and Add filters
			try {


				$max = max(count($usbc['deviceFilters']),count($args['USBController']['deviceFilters']));
				$offset = 0;

				// Remove existing
				for($i = 0; $i < $max; $i++) {

					// Only if filter differs
					if(@serialize($usbc['deviceFilters'][$i]) != @serialize($args['USBController']['deviceFilters'][$i])) {

						// Remove existing?
						if($i < count($usbc['deviceFilters'])) {
							$m->USBController->removeDeviceFilter(($i-$offset));
							$offset++;
						}

						// Exists in new?
						if(count($args['USBController']['deviceFilters'][$i])) {

							// Create filter
							$f = $m->USBController->createDeviceFilter($args['USBController']['deviceFilters'][$i]['name']);
							$f->active = (bool)$args['USBController']['deviceFilters'][$i]['active'];

							foreach($usbProps as $p) {
								$f->$p = $args['USBController']['deviceFilters'][$i][$p];
							}

							$m->USBController->insertDeviceFilter($i,$f);
							$f->releaseRemote();
							$offset--;
						}
					}

				}

			} catch (Exception $e) { $this->errors[] = $e; }

		}

		// Expire USB info?
		if($usbchanged) $expire[] = '__getUSBController'.$args['id'];


		$this->session->machine->saveSettings();
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();

		// Expire cache
		foreach(array_unique($expire) as $ex)
			$this->cache->expire($ex);

		$response['data']['result'] = 1;

		return true;
	}

	/*
	 * Add a VM from settings file
	 */
	public function addVM($args,&$response) {

		$this->__vboxwebsrvConnect();

		$m = $this->vbox->openMachine($args['file']);
		$this->vbox->registerMachine($m->handle);
		
		$m->releaseRemote();
		return ($response['data']['result'] = 1);

	}
	
	/*
	 * Return cached result from machine request
	 */
	private function __getCachedMachineData($fn,$key,&$item,$force_refresh=false) {

		// do not cache
		if(!@$this->settings['cacheSettings'][$fn] || !$key) {

			return $this->$fn($item);

		// Cached data exists?
		} else if(!$force_refresh && ($result = $this->cache->get($fn.$key,@$this->settings['cacheSettings'][$fn])) !== false) {

			return $result;

		} else {

			$lock = $this->cache->lock($fn.$key);

			// file was modified while attempting to lock.
			// file data is returned
			if($lock === null) {

				return $this->cache->get($fn.$key,@$this->settings['cacheSettings'][$fn]);

			// lock obtained
			} else {

				$result = $this->$fn($item);

				if($this->cache->store($fn.$key,$result) === false && $result !== false) {
					throw new Exception("Error storing cache.");
					return false;
				}

				return $result;

			}

		}


	}

	/*
	 *  Progress operations garbage collection (stub / unimplemented)
	 */
	private function __progressGC() {

		return;

		$pops = $this->cache->get('ProgressOperations',false);
		$cpops = array();
		$now = time();

		foreach(array_keys($pops) as $p) {
			if($now - $$pops[$p]['started'] > 300) {

			}
		}

	}

	/*
	 * Get progress for operation.
	 */
	public function getProgress($args,&$response) {

		$pop = $this->cache->get('ProgressOperations',false);

		if(!($pop = @$pop[$args['progress']])) {
			throw new Exception('Could not obtain progress operation: '.$args['progress']);
		}

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		try {

			try {
				// Keep session from timing out
				$vbox = new IVirtualBox($this->client, $pop['session']);
				$session = $this->websessionManager->getSessionObject($vbox->handle);
				// Force web call
				if($session->state->__toString()) {}
				$progress = new IProgress($this->client,$args['progress']);
			} catch (Exception $e) {
				$this->errors[] = $e;
				throw new Exception('Could not obtain progress operation: '.$args['progress']);
			}


			$response['data']['progress'] = $args['progress'];

			$response['data']['info'] = array(
				'completed' => $progress->completed,
				'canceled' => $progress->canceled,
				'description' => $progress->operationDescription,
				'timeRemaining' => $this->__splitTime($progress->timeRemaining),
				'timeElapsed' => $this->__splitTime((time() - $pop['started'])),
				'percent' => $progress->percent
				);


			// Completed? Do not return. Fall to __destroyProgress() called later
			if($response['data']['info']['completed'] || $response['data']['info']['canceled']) {

				try {
					if(!$response['data']['info']['canceled'] && $progress->errorInfo->handle) {
						$err = $progress->errorInfo->text;
						$this->errors[] = new Exception($err);
					}
				} catch (Exception $null) {}


			} else {

				$response['data']['info']['cancelable'] = $progress->cancelable;

				return true;
			}


		} catch (Exception $e) {

			// Force progress dialog closure
			$response['data']['info'] = array('completed'=>1);

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
				}
			} catch (Exception $null) {}

			// Some progress operations seem to go away after completion
			// probably the result of automatic session closure
			if(!($session->handle && $session->state->__toString() == 'Unlocked'))
				$this->errors[] = $e;

		}

		$this->__destroyProgress($args['progress'],$response);


	}

	/*
	 * Get progress for operation.
	 */
	public function cancelProgress($args,&$response) {

		$pop = $this->cache->get('ProgressOperations',false);

		if(!($pop = @$pop[$args['progress']])) {
			throw new Exception('Could not obtain progress operation: '.$args['progress']);
		}

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		try {
			$progress = new IProgress($this->client,$args['progress']);
			if(!($progress->completed || $progress->canceled))
				$progress->cancel();
		} catch (Exception $e) {
			$this->errors[] = $e;
		}

		return ($response['data']['result'] = 1);
	}

	/*
	 *
	 * Destroy a progress reference
	 *
	 */
	private function __destroyProgress($p,&$response) {

		$pops = $this->cache->get('ProgressOperations',false);

		if(!($pop = @$pops[$p])) {
			throw new Exception('Could not destroy progress operation: '.$p);
		}

		// Expire cache item?
		if(is_array(@$pop['expire'])) foreach($pop['expire'] as $e) $this->cache->expire($e);

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		try {$progress = new IProgress($this->client,$p); $progress->releaseRemote();}
		catch (Exception $e) {}
		try {

			// Recreate vbox interface and close session
			$vbox = new IVirtualBox(null, $pop['session']);

			try {

				$session = $this->websessionManager->getSessionObject($vbox->handle);

				if($session->handle && $session->state->__toString() != 'Unlocked')
					$session->unlockMachine();

			} catch (Exception $null) { }


			// Logoff
			$this->websessionManager->logoff($vbox->handle);

		} catch (Exception $e) {
			$this->errors[] = $e;
		}

		// Remove progress reference from cache
		$this->cache->lock('ProgressOperations');
		$inprogress = $this->cache->get('ProgressOperations');
		if(!is_array($inprogress)) $inprogress = array();
		unset($inprogress[$p]);
		$this->cache->store('ProgressOperations',$inprogress);

		return true;
	}

	/*
	 * Get enumeration maps
	 */
	private function __getEnumerationMap($class, &$response) {
		if(class_exists($class)) {
			$c = new $class;
			$response['data'] = $c->NameMap;
		}
	}
	/*
	 * Save system properties
	 */
	public function saveSystemProperties($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$this->vbox->systemProperties->defaultMachineFolder = $args['SystemProperties']['defaultMachineFolder'];
		$this->vbox->systemProperties->VRDEAuthLibrary = $args['SystemProperties']['VRDEAuthLibrary'];

		$this->cache->expire('getSystemProperties');

		return ($response['data']['result'] = 1);

	}

	/*
	 * Import appliance
	 */
	public function applianceImport($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();


		$app = $this->vbox->createAppliance();
		$progress = $app->read($args['file']);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$app->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$progress->waitForCompletion(-1);

		$app->interpret();

		$a = 0;
		foreach($app->virtualSystemDescriptions as $d) {
			// Replace with passed values
			$args['descriptions'][$a][5] = array_pad($args['descriptions'][$a][5], count($args['descriptions'][$a][3]),true);
			foreach(array_keys($args['descriptions'][$a][5]) as $k) $args['descriptions'][$a][5][$k] = (bool)$args['descriptions'][$a][5][$k];
			$d->setFinalValues($args['descriptions'][$a][5],$args['descriptions'][$a][3],$args['descriptions'][$a][4]);
			$a++;
		}

		$progress = $app->importMachines();

		$app->releaseRemote();
		
		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,'getMediums');

		$response['data']['progress'] = $progress->handle;

		return true;

	}

	/*
	 * Get list of VMs available for export
	 */
	public function getVMsExportable($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		//Get a list of registered machines
		$machines = $this->vbox->machines;

		foreach ($machines as $machine) {

			try {
				$response['data'][] = array(
					'name' => $machine->name,
					'state' => $machine->state->__toString(),
					'OSTypeId' => $machine->getOSTypeId(),
					'id' => $machine->id,
					'description' => $machine->description
				);
				$machine->releaseRemote();
				
			} catch (Exception $e) {
				// Ignore. Probably inaccessible machine.
			}
		}
		return true;
	}

	/*
	 * Read and interpret appliance
	 */
	public function applianceReadInterpret($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$app = $this->vbox->createAppliance();
		$progress = $app->read($args['file']);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$app->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$progress->waitForCompletion(-1);

		$app->interpret();

		$response['data']['warnings'] = $app->getWarnings();
		$response['data']['descriptions'] = array();
		$i = 0;
		foreach($app->virtualSystemDescriptions as $d) {
			$desc = array();
			$response['data']['descriptions'][$i] = $d->getDescription();
			foreach($response['data']['descriptions'][$i][0] as $ddesc) {
				$desc[] = $ddesc->__toString();
			}
			$response['data']['descriptions'][$i][0] = $desc;
			$i++;
			$d->releaseRemote();
		}
		$app->releaseRemote();
		$app=null;

		return ($response['data']['result'] = 1);

	}

	/*
	 * Export an appliance
	 */
	public function applianceExport($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$app = $this->vbox->createAppliance();

		$appProps = array(
			'name' => 'Name',
			'description' => 'Description',
			'product' => 'Product',
			'vendor' => 'Vendor',
			'version' => 'Version',
			'product-url' => 'ProductUrl',
			'vendor-url' => 'VendorUrl',
			'license' => 'License');


		foreach($args['vms'] as $vm) {
			$m = $this->vbox->findMachine($vm['id']);
			$desc = $m->export($app->handle);
			$props = $desc->getDescription();
			$ptypes = array();
			foreach($props[0] as $p) {$ptypes[] = $p->__toString();}
			foreach($appProps as $k=>$v) {
				// Check for existing property
				if(($i=array_search($v,$ptypes)) !== false) {
					$props[3][$i] = $vm[$k];
				} else {
					$desc->addDescription($v,$vm[$k],null);
					$props[3][] = $vm[$k];
					$props[4][] = null;
				}
			}
			$enabled = array_pad(array(),count($props[3]),true);
			foreach(array_keys($enabled) as $k) $enabled[$k] = (bool)$enabled[$k];
			$desc->setFinalValues($enabled,$props[3],$props[4]);
			$desc->releaseRemote();
			$m->releaseRemote();
		}

		$progress = $app->write(($args['format'] ? $args['format'] : 'ovf-1.0'),true,$args['file']);
		$app->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress);

		$response['data']['progress'] = $progress->handle;

		return true;
	}
	/*
	 * Get host networking info
	 */
	private function getHostNetworkingCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		/*
		 * Existing Networks
		 */
		$networks = array();
		$vdenetworks = array();
		foreach($this->vbox->machines as $machine) {

			for($i = 0; $i < $this->settings['nicMax']; $i++) {

				try {
					$h = &$machine->getNetworkAdapter($i);
				} catch (Exception $e) {
					break;
				}

				try {
					if($h->internalNetwork) {
						$networks[$h->internalNetwork] = 1;
					} else if($this->settings['enableVDE'] && $h->VDENetwork) {
						$vdenetworks[$h->VDENetwork] = 1;
					}
					$h->releaseRemote();
				} catch (Exception $e) {
					// Ignore
				}

			}
			$machine->releaseRemote();
		}
		$response['data']['networks'] = array_keys($networks);
		$response['data']['vdenetworks'] = array_keys($vdenetworks);

		return true;
	}


	/*
	 * Get host-only networking info
	 */
	private function getHostOnlyNetworkingCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		/*
		 * NICs
		 */
		$response['data']['networkInterfaces'] = array();
		foreach($this->vbox->host->networkInterfaces as $d) {

			if($d->interfaceType->__toString() != 'HostOnly') {
				$d->releaseRemote();
				continue;
			}
				

			// Get DHCP Info
			try {
				$dhcp = $this->vbox->findDHCPServerByNetworkName($d->networkName);
			} catch (Exception $e) {};

			if($dhcp->handle) {
				$dhcpserver = array(
					'enabled' => $dhcp->enabled,
					'IPAddress' => $dhcp->IPAddress,
					'networkMask' => $dhcp->networkMask,
					'networkName' => $dhcp->networkName,
					'lowerIP' => $dhcp->lowerIP,
					'upperIP' => $dhcp->upperIP
				);
				$dhcp->releaseRemote();
			} else {
				$dhcpserver = array();
			}
			$response['data']['networkInterfaces'][] = array(
				'id' => $d->id,
				'IPV6Supported' => $d->IPV6Supported,
				'name' => $d->name,
				'IPAddress' => $d->IPAddress,
				'networkMask' => $d->networkMask,
				'IPV6Address' => $d->IPV6Address,
				'IPV6NetworkMaskPrefixLength' => $d->IPV6NetworkMaskPrefixLength,
				'dhcpEnabled' => $d->dhcpEnabled,
				'networkName' => $d->networkName,
				'dhcpServer' => $dhcpserver
			);
			$d->releaseRemote();
		}

		return true;
	}

	/*
	 * Save Host-only interface configuration
	 */
	public function saveHostOnlyInterfaces($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$nics = $args['networkInterfaces'];

		for($i = 0; $i < count($nics); $i++) {

			$nic = $this->vbox->host->findHostNetworkInterfaceById($nics[$i]['id']);

			// Common settings
			if($nic->IPAddress != $nics[$i]['IPAddress'] || $nic->networkMask != $nics[$i]['networkMask']) {
				$nic->enableStaticIpConfig($nics[$i]['IPAddress'],$nics[$i]['networkMask']);
			}
			if($nics[$i]['IPV6Supported'] &&
				($nic->IPV6Address != $nics[$i]['IPV6Address'] || $nic->IPV6NetworkMaskPrefixLength != $nics[$i]['IPV6NetworkMaskPrefixLength'])) {
				$nic->enableStaticIpConfigV6($nics[$i]['IPV6Address'],intval($nics[$i]['IPV6NetworkMaskPrefixLength']));
			}

			// Get DHCP Info
			try {
				$dhcp = $this->vbox->findDHCPServerByNetworkName($nic->networkName);
			} catch (Exception $e) {$dhcp = null;};

			// Create DHCP server?
			if((bool)@$nics[$i]['dhcpServer']['enabled'] && !$dhcp) {
				$dhcp = $this->vbox->createDHCPServer($nic->networkName);
			}
			if($dhcp->handle) {
				$dhcp->enabled = (bool)@$nics[$i]['dhcpServer']['enabled'];
				$dhcp->setConfiguration($nics[$i]['dhcpServer']['IPAddress'],$nics[$i]['dhcpServer']['networkMask'],$nics[$i]['dhcpServer']['lowerIP'],$nics[$i]['dhcpServer']['upperIP']);
				$dhcp->releaseRemote();
			}
			$nic->releaseRemote();

		}

		return ($response['data']['result'] = 1);

	}

	/*
	 * Add Host-only interface
	 */
	public function createHostOnlyInterface($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		list($int,$progress) = $this->vbox->host->createHostOnlyNetworkInterface();
		$int->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,array('getHostDetails'));

		$response['data']['progress'] = $progress->handle;

		return true;

	}


	/*
	 * Remove network interface
	 */
	public function removeHostOnlyInterface($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->vbox->host->removeHostOnlyNetworkInterface($args['id']);

		if(!$progress->handle) return false;

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,array('getHostDetails'));

		$response['data']['result'] = 1;
		$response['data']['progress'] = $progress->handle;

		return true;
	}

	/*
	 * Populate a list of Guest OS types
	 */
	private function getGuestOSTypesCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$ts = $this->vbox->getGuestOSTypes();
		
		$supp64 = ($this->vbox->host->getProcessorFeature('LongMode') && $this->vbox->host->getProcessorFeature('HWVirtEx'));

		foreach($ts as $g) {

			// Avoid multiple calls
			$bit64 = $g->is64Bit;
			$response['data'][] = array(
				'familyId' => $g->familyId,
				'familyDescription' => $g->familyDescription,
				'id' => $g->id,
				'description' => $g->description,
				'is64Bit' => $bit64,
				'recommendedRAM' => $g->recommendedRAM,
				'recommendedHDD' => ($g->recommendedHDD/1024)/1024,
				'supported' => intval(!$bit64 || $supp64)
			);
		}
		
		return true;
	}

	/*
	 * Set VM state
	 *
	 *
	 */
	private function __setVMState($vm, $state, &$response) {


		$states = array(
			'powerDown' => array('result'=>'PoweredOff','progress'=>2),
			'reset' => array(),
			'saveState' => array('result'=>'Saved','progress'=>2),
			'powerButton' => array('acpi'=>true),
			'sleepButton' => array('acpi'=>true),
			'pause' => array('result'=>'Paused','progress'=>false),
			'resume' => array('result'=>'Running','progress'=>false),
			'powerUp' => array('result'=>'Running'),
			'discardSavedState' => array('result'=>'poweredOff','lock'=>'shared','force'=>true)
		);

		// Check for valid state
		if(!is_array($states[$state])) {
			$response['data']['result'] = 0;
			throw new Exception('Invalid state: ' . $state);
		}

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		// Machine state
		$machine = &$this->vbox->findMachine($vm);
		$mstate = $machine->state->__toString();

		// If state has an expected result, check
		// that we are not already in it
		if($states[$state]['result']) {
			if($mstate == $states[$state]['result']) {
				$response['data']['result'] = 0;
				$machine->releaseRemote();
				throw new Exception('Machine is already in requested state.');
			}
		}

		// Special case for power up
		if($state == 'powerUp' && $mstate == 'Paused') {
			return $this->__setVMState($vm,'resume',$response);
		} else if($state == 'powerUp') {
			return $this->__launchVMProcess($machine,$response);
		}

		// Open session to machine
		$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);

		// Lock machine
		$machine->lockMachine($this->session->handle,($states[$state]['lock'] == 'write' ? 'Write' : 'Shared'));

		// If this operation returns a progress object save progress
		$progress = null;
		if($states[$state]['progress']) {

			$progress = $this->session->console->$state();

			if(!$progress->handle) {

				// should never get here
				try {
					$this->session->unlockMachine();
					$this->session = null;
				} catch (Exception $e) {};

				$response['data']['result'] = 0;
				$machine->releaseRemote();
				throw new Exception('Unknown error settings machine to requested state.');
			}

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			// Save progress
			$this->__storeProgress($progress,null);

			$response['data']['progress'] = $progress->handle;

		// Operation does not return a progress object
		// Just call the function
		} else {

			$this->session->console->$state(($states[$state]['force'] ? true : null));

		}

		$machine->releaseRemote();
		
		// Check for ACPI button
		if($states[$state]['acpi'] && !$this->session->console->getPowerButtonHandled()) {
			$this->session->console->releaseRemote();
			$this->session->unlockMachine();
			$this->session = null;
			throw new Exception(trans('ACPI event not handled'));
		}


		if(!$progress->handle) {
			$this->session->console->releaseRemote();
			$this->session->unlockMachine();
			$this->session->releaseRemote();
			unset($this->session);
		}

		return ($response['data']['result'] = 1);

	}

	/*
	 *
	 * This starts a VM
	 *
	 */
	private function __launchVMProcess(&$machine, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		# Try opening session for VM
		try {
			// create session
			$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);

			$progress = $machine->launchVMProcess($this->session->handle, 'headless', '');

		} catch (Exception $e) {
			// Error opening session
			$this->errors[] = $e;
			return ($response['data']['result'] = 0);
		}

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,null);

		$response['data']['progress'] = $progress->handle;

		return ($response['data']['result'] = 1);
	}

	/*
	 *  Array containing details about the VirtualBox host.
	 */
	private function getHostDetailsCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		/*
		 * Generic Host system details
		 */
		$host = &$this->vbox->host;
		$response['data'] = array(
			'id' => 'host',
			'operatingSystem' => $host->operatingSystem,
			'OSVersion' => $host->OSVersion,
			'memorySize' => $host->memorySize,
			'cpus' => array(),
			'networkInterfaces' => array(),
			'DVDDrives' => array(),
			'floppyDrives' => array()
		);

		/*
		 * Processors
		 */
		for($i = 0; $i < $host->processorCount; $i++) {
			$response['data']['cpus'][$i] = $host->getProcessorDescription($i);
		}
		
		/*
		 * Supported CPU features?
		 */
		$response['data']['cpuFeatures'] = array();
		foreach(array('HWVirtEx'=>'VCPU','PAE'=>'PAE','NestedPaging'=>'Nested Paging','LongMode'=>'Long Mode (64-bit)') as $k=>$v) {
			$response['data']['cpuFeatures'][$v] = intval($host->getProcessorFeature($k));
		}	
		
		/*
		 * NICs
		 */
		foreach($host->networkInterfaces as $d) {
			$response['data']['networkInterfaces'][] = array(
				'name' => $d->name,
				'IPAddress' => $d->IPAddress,
				'networkMask' => $d->networkMask,
				'IPV6Address' => $d->IPV6Address,
				'IPV6NetworkMaskPrefixLength' => $d->IPV6NetworkMaskPrefixLength,
				'status' => $d->status->__toString(),
				'mediumType' => $d->mediumType->__toString(),
				'interfaceType' => $d->interfaceType->__toString(),
				'hardwareAddress' => $d->hardwareAddress,
				'networkName' => $d->networkName,
			);
			$d->releaseRemote();
		}

		/*
		 * Medium types (DVD and Floppy)
		 */
		foreach($host->DVDDrives as $d) {

			$response['data']['DVDDrives'][] = array(
				'id' => $d->id,
				'name' => $d->name,
				'location' => $d->location,
				'description' => $d->description,
				'deviceType' => 'DVD',
				'hostDrive' => true,
			);
			$d->releaseRemote();
		}

		foreach($host->floppyDrives as $d) {

			$response['data']['floppyDrives'][] = array(
				'id' => $d->id,
				'name' => $d->name,
				'location' => $d->location,
				'description' => $d->description,
				'deviceType' => 'Floppy',
				'hostDrive' => true,
			);
			$d->releaseRemote();
		}
		$host->releaseRemote();
		return true;
	}

	/*
	 * Get a list of connected USB devices
	 */
	public function getHostUSBDevices($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		foreach($this->vbox->host->USBDevices as $d) {
			$response['data'][] = array(
				'id' => $d->id,
				'vendorId' => sprintf('%04s',dechex($d->vendorId)),
				'productId' => sprintf('%04s',dechex($d->productId)),
				'revision' => sprintf('%04s',dechex($d->revision)),
				'manufacturer' => $d->manufacturer,
				'product' => $d->product,
				'serialNumber' => $d->serialNumber,
				'address' => $d->address,
				'port' => $d->port,
				'version' => $d->version,
				'portVersion' => $d->portVersion,
				'remote' => $d->remote,
				'state' => $d->state->__toString(),
				);
			$d->releaseRemote();
		}
		
		return true;
	}


	/*
	 *
	 *
	 * Return an array containing details of the virtual
	 * machine specified by vm
	 *
	 *
	 */
	public function getVMDetails($args, &$response, $snapshot=null) {

		// Host instead of vm info
		if($args['vm'] == 'host') return @$this->getHostDetails($args, array(&$response));


		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$version = $this->getVersion();

		//Get registered machine or snapshot machine
		if($snapshot) {

			$machine = &$snapshot;

		} else {

			$machine = &$this->vbox->findMachine($args['vm']);


			// For correct caching, always use id
			$args['vm'] = $machine->id;

			// Check for accessibility
			if(!$machine->accessible) {

				$response['data'] = array(
					'name' => $machine->name,
					'state' => 'Inaccessible',
					'OSTypeId' => 'Other',
					'id' => $machine->id,
					'sessionState' => 'Inaccessible',
					'accessible' => 0,
					'accessError' => array(
						'resultCode' => $this->resultcodes['0x'.strtoupper(dechex($machine->accessError->resultCode))],
						'component' => $machine->accessError->component,
						'text' => $machine->accessError->text)
				);

				return true;
			}

		}

		// Basic data
		$data = $this->__getCachedMachineData('__getMachine',@$args['vm'],$machine,@$args['force_refresh']);

		// Network Adapters
		$data['networkAdapters'] = $this->__getCachedMachineData('__getNetworkAdapters',@$args['vm'],$machine,@$args['force_refresh']);

		// Storage Controllers
		$data['storageControllers'] = $this->__getCachedMachineData('__getStorageControllers',@$args['vm'],$machine,@$args['force_refresh']);
		
		// Serial Ports
		$data['serialPorts'] = $this->__getCachedMachineData('__getSerialPorts',@$args['vm'],$machine,@$args['force_refresh']);
		
		// LPT Ports
		$data['parallelPorts'] = $this->__getCachedMachineData('__getParallelPorts',@$args['vm'],$machine,@$args['force_refresh']);
		
		// Shared Folders
		$data['sharedFolders'] = $this->__getCachedMachineData('__getSharedFolders',@$args['vm'],$machine,@$args['force_refresh']);


		// USB Filters
		$data['USBController'] = $this->__getCachedMachineData('__getUSBController',@$args['vm'],$machine,@$args['force_refresh']);

		// Non-cached items when not obtaining
		// snapshot machine info
		if(!$snapshot) {

			$data['state'] = $machine->state->__toString();
			$data['currentSnapshot'] = ($machine->currentSnapshot->handle ? array('id'=>$machine->currentSnapshot->id,'name'=>$machine->currentSnapshot->name) : null);
			$data['snapshotCount'] = $machine->snapshotCount;
			$data['sessionState'] = $machine->sessionState->__toString();
			$data['currentStateModified'] = $machine->currentStateModified;

			$mdlm = floor($machine->lastStateChange/1000);

			// Get current console port
			if($data['state'] == 'Running') {
				$console = $this->cache->get('__consolePort'.$args['vm'],120000);
				if($console === false || intval($console['lastStateChange']) < $mdlm) {
					$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);
					$machine->lockMachine($this->session->handle, 'Shared');
					$data['consolePort'] = $this->session->console->VRDEServerInfo->port;
					$this->session->unlockMachine();
					$this->session->releaseRemote();
					unset($this->session);
					$console = array(
						'consolePort'=>$data['consolePort'],
						'lastStateChange'=>$mdlm
					);
					$this->cache->store('__consolePort'.$data['id'],$console);
				} else {
					$data['consolePort'] = $console['port'];
				}
			}


		}
		
		$machine->releaseRemote();

		$data['accessible'] = 1;
		$response['data'] = $data;

		return true;
	}

	/*
	 *
	 * Register a VM from its settings file
	 *
	 */
	public function registerVM($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$vm = $this->vbox->openMachine($args['file']);
		$this->vbox->registerMachine($vm->handle);
		
		$vm->releaseRemote();

		return ($response['data']['result'] = 1);

	}

	/*
	 *
	 * Remove a Virtual Machine
	 *
	 */
	public function removeVM($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$machine = $this->vbox->findMachine($args['vm']);

		$cache = array('__consolePort'.$args['vm'],'__getMachine'.$args['vm'],'__getNetworkAdapters'.$args['vm'],'__getStorageControllers'.$args['vm'],
			'__getSharedFolders'.$args['vm'],'__getUSBController'.$args['vm'],'getMediums','__getSerialPorts'.$args['vm'],'__getParallelPorts'.$args['vm']);

		// Only unregister or delete?
		if($args['unregister']) {

			$machine->unregister('Full');

			// Clear caches
			foreach($cache as $ex) {
				$this->cache->expire('__'.$ex.$args['vm']);
			}
			
			$machine->releaseRemote();
			return ($response['data']['result'] = 1);

		} else {

			$delete = $machine->unregister('DetachAllReturnHardDisksOnly');
			if($args['delete']) {
				foreach($delete as $hd) $hds[] = $hd->handle;
			} else {
				$hds = array();
			}

			$progress = $machine->delete($hds);
			
			$machine->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,$cache);

			$response['data']['progress'] = $progress->handle;

			return ($response['data']['result'] = 1);

		}



	}

	/*
	 *
	 * Create a new Virtual Machine
	 *
	 */
	public function createVM($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$version = $this->getVersion();
		
		// create machine
		$m = $this->vbox->createMachine(null,$args['name'],$args['ostype'],null,null);

		// Set memory
		$m->memorySize = intval($args['memory']);


		// Save and register
		$m->saveSettings();
		$this->vbox->registerMachine($m->handle);
		$vm = $m->id;
		$m->releaseRemote();

		try {

			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			// Lock VM
			$machine = $this->vbox->findMachine($vm);
			$machine->lockMachine($this->session->handle,'Write');

			// OS defaults
			$defaults = $this->vbox->getGuestOSType($args['ostype']);


			// Always set
			$this->session->machine->setExtraData('GUI/SaveMountedAtRuntime', 'yes');
			try {
				$this->session->machine->USBController->enabled = true;
				$this->session->machine->USBController->enabledEhci = true;
			} catch (Exception $e) {
				// Ignore
			}
			
			try {
				if($this->session->machine->VRDEServer && $this->vbox->systemProperties->defaultVRDEExtPack) {
					$this->session->machine->VRDEServer->enabled = 1;
					$this->session->machine->VRDEServer->authTimeout = 5000;
					$this->session->machine->VRDEServer->setVRDEProperty('TCP/Ports','3389-4000');
				}
			} catch (Exception $e) {
				//Ignore
			}

			// Other defaults
			$this->session->machine->BIOSSettings->IOAPICEnabled = $defaults->recommendedIOAPIC;
			$this->session->machine->RTCUseUTC = $defaults->recommendedRtcUseUtc;
			$this->session->machine->firmwareType = $defaults->recommendedFirmware->__toString();
			$this->session->machine->chipsetType = $defaults->recommendedChipset->__toString();
			if(intval($defaults->recommendedVRAM) > 0) $this->session->machine->VRAMSize = intval($defaults->recommendedVRAM);
			$this->session->machine->setCpuProperty('PAE',$defaults->recommendedPae);
			
			// USB input devices
			if($defaults->recommendedUsbHid) {
				$this->session->machine->pointingHidType = 'USBMouse';
				$this->session->machine->keyboardHidType = 'USBKeyboard';
			}
			
			/* Only if acceleration configuration is enabled */
			if($this->settings['enableAdvancedConfig']) {	
				$this->session->machine->setHWVirtExProperty('Enabled',$defaults->recommendedVirtEx);
			}
			
			/*
			 * Hard Disk and DVD/CD Drive
			 */
			$DVDbusType = $defaults->recommendedDvdStorageBus->__toString();
			$DVDconType = $defaults->recommendedDvdStorageController->__toString();

			// Attach harddisk?
			if($args['disk']) {

				$HDbusType = $defaults->recommendedHdStorageBus->__toString();
				$HDconType = $defaults->recommendedHdStorageController->__toString();

				$bus = new StorageBus(null,$HDbusType);
				$sc = $this->session->machine->addStorageController(trans($HDbusType.' Controller'),$bus->__toString());
				$sc->controllerType = $HDconType;
				$sc->useHostIOCache = (bool)$this->vbox->systemProperties->getDefaultIoCacheSettingForStorageController($HDconType);
				$sc->releaseRemote();

				$m = $this->vbox->findMedium($args['disk'],'HardDisk');

				$this->session->machine->attachDevice(trans($HDbusType.' Controller'),0,0,'HardDisk',$m->handle);

				$m->releaseRemote();


			}

			// Attach DVD/CDROM
			if($DVDbusType) {

				if(!$args['disk'] || ($HDbusType != $DVDbusType)) {

					$bus = new StorageBus(null,$DVDbusType);
					$sc = $this->session->machine->addStorageController(trans($DVDbusType.' Controller'),$bus->__toString());
					$sc->controllerType = $DVDconType;
					$sc->useHostIOCache = (bool)$this->vbox->systemProperties->getDefaultIoCacheSettingForStorageController($DVDconType);
					$sc->releaseRemote();
				}

				$this->session->machine->attachDevice(trans($DVDbusType.' Controller'),1,0,'DVD',null);

			}
			
			// Auto-sata port count
			$this->session->machine->setExtraData('phpvb/AutoSATAPortCount','yes');

			$this->session->machine->saveSettings();
			$this->session->unlockMachine();
			$this->session = null;

			if($args['disk']) $this->cache->expire('getMediums');
			
			$machine->releaseRemote();

		} catch (Exception $e) {
			$this->errors[] = $e;
			return false;
		}

		return ($response['data']['result'] = 1);

	}


	/*
	 *
	 * Return a list of guest attached network adapters
	 *
	 */
	private function __getNetworkAdapters(&$m) {

		$adapters = array();

		for($i = 0; $i < $this->settings['nicMax']; $i++) {
			$n = $m->getNetworkAdapter($i);
			$adapters[] = $this->__getNetworkAdapter($n);
			$n->releaseRemote();
		}

		return $adapters;

	}


	/*
	 *
	 *
	 * Return a list of VMs along with their
	 *
	 * states and basic info
	 *
	 */
	public function getVMsCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$response['data']['vmlist'] = array();
		
		//Get a list of registered machines
		$machines = $this->vbox->machines;

		foreach ($machines as $machine) {

			try {
				$response['data']['vmlist'][] = array(
					'name' => $machine->name,
					'state' => $machine->state->__toString(),
					'OSTypeId' => $machine->getOSTypeId(),
					'id' => $machine->id,
					'lastStateChange' => floor($machine->lastStateChange/1000),
					'sessionState' => $machine->sessionState->__toString(),
					'currentSnapshot' => ($machine->currentSnapshot->handle ? $machine->currentSnapshot->name : '')
				);
				if($machine->currentSnapshot->handle) $machine->currentSnapshot->releaseRemote();

			} catch (Exception $e) {

				if($machine) {

					$response['data']['vmlist'][] = array(
						'name' => $machine->id,
						'state' => 'Inaccessible',
						'OSTypeId' => 'Other',
						'id' => $machine->id,
						'sessionState' => 'Inaccessible',
						'lastStateChange' => 0,
						'currentSnapshot' => ''
					);

				} else {
					$this->errors[] = $e;
				}
			}
			
			try {
				$machine->releaseRemote();
			} catch (Exception $e) { }
		}
		$response['data']['server_key'] = $this->settings['key'];
		$response['data']['result'] = 1;
		return true;

	}

	/*
	 * Debug input array
	 *
	 */
	public function debugInput($args,&$response) {
		$this->errors[] = new Exception('debug');
		return ($response['data']['result'] = 1);
	}

	/*
	 *
	 *
	 * Get all mediums registered with this vbox installation
	 *
	 *
	 */
	private function getMediumsCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$response['data'] = array();
		$mds = array($this->vbox->hardDisks,$this->vbox->DVDImages,$this->vbox->floppyImages);
		for($i=0;$i<3;$i++) {
			foreach($mds[$i] as $m) {
				$response['data'][] = $this->__getMedium($m);
				$m->releaseRemote();
			}
		}
		return true;
	}

	/*
	 *
	 * Fill network adapter info
	 *
	 */
	private function __getNetworkAdapter(&$n) {

		return array(
			'adapterType' => $n->adapterType->__toString(),
			'slot' => $n->slot,
			'enabled' => $n->enabled,
			'MACAddress' => $n->MACAddress,
			'attachmentType' => $n->attachmentType->__toString(),
			'hostInterface' => $n->hostInterface,
			'internalNetwork' => $n->internalNetwork,
			'NATNetwork' => $n->NATNetwork,
			'VDENetwork' => ($this->settings['enableVDE'] ? $n->VDENetwork : ''),
			'cableConnected' => $n->cableConnected,
			'lineSpeed' => $n->lineSpeed,
			'redirects' => (
				$n->attachmentType->__toString() == 'NAT' ?
				$n->NatDriver->getRedirects()
				: array()
				)
			);
	}
	/*
	 *
	 * Fill USB Controller data
	 *
	 */
	private function __getUSBController(&$m) {

		$u = &$m->USBController;

		$deviceFilters = array();
		foreach($u->deviceFilters as $df) {
			$deviceFilters[] = array(
				'name' => $df->name,
				'active' => intval($df->active),
				'vendorId' => $df->vendorId,
				'productId' => $df->productId,
				'revision' => $df->revision,
				'manufacturer' => $df->manufacturer,
				'product' => $df->product,
				'serialNumber' => $df->serialNumber,
				'port' => $df->port,
				'remote' => $df->remote
				);
			$df->releaseRemote();
		}
		$r = array(
			'enabled' => $u->enabled,
			'enabledEhci' => $u->enabledEhci,
			'deviceFilters' => $deviceFilters);
		$u->releaseRemote();
		return $r;
	}

	/*
	 *
	 *
	 * Fill Machine data
	 *
	 */
	private function __getMachine(&$m) {

		return array(
			'name' => $m->name,
			'description' => $m->description,
			'id' => $m->id,
			'settingsFilePath' => $m->settingsFilePath,
			'OSTypeId' => $m->OSTypeId,
			'OSTypeDesc' => $this->vbox->getGuestOSType($m->OSTypeId)->description,
			'CPUCount' => $m->CPUCount,
			'hpetEnabled' => $m->hpetEnabled,
			'memorySize' => $m->memorySize,
			'VRAMSize' => $m->VRAMSize,
			'pointingHidType' => $m->pointingHidType->__toString(),
			'keyboardHidType' => $m->keyboardHidType->__toString(),
			'accelerate3DEnabled' => $m->accelerate3DEnabled,
			'accelerate2DVideoEnabled' => $m->accelerate2DVideoEnabled,
			'BIOSSettings' => array(
				'ACPIEnabled' => $m->BIOSSettings->ACPIEnabled,
				'IOAPICEnabled' => $m->BIOSSettings->IOAPICEnabled,
				'timeOffset' => $m->BIOSSettings->timeOffset
				),
			'firmwareType' => $m->firmwareType->__toString(),
			'snapshotFolder' => $m->snapshotFolder,
			'monitorCount' => $m->monitorCount,
			'VRDEServer' => (!$m->VRDEServer ? null : array(
				'enabled' => $m->VRDEServer->enabled,
				'ports' => $m->VRDEServer->getVRDEProperty('TCP/Ports'),
				'netAddress' => $m->VRDEServer->getVRDEProperty('TCP/Address'),
				'authType' => $m->VRDEServer->authType->__toString(),
				'authTimeout' => $m->VRDEServer->authTimeout,
				'allowMultiConnection' => intval($m->VRDEServer->allowMultiConnection),
				'VRDEExtPack' => (string)$m->VRDEServer->VRDEExtPack
				)),
			'audioAdapter' => array(
				'enabled' => $m->audioAdapter->enabled,
				'audioController' => $m->audioAdapter->audioController->__toString(),
				'audioDriver' => $m->audioAdapter->audioDriver->__toString(),
				),
			'RTCUseUTC' => $m->RTCUseUTC,
			'HWVirtExProperties' => array(
				'Enabled' => $m->getHWVirtExProperty('Enabled'),
				'NestedPaging' => $m->getHWVirtExProperty('NestedPaging'),
				'LargePages' => $m->getHWVirtExProperty('LargePages'),
				'Exclusive' => $m->getHWVirtExProperty('Exclusive'),
				'VPID' => $m->getHWVirtExProperty('VPID')
				),
			'CpuProperties' => array(
				'PAE' => $m->getCpuProperty('PAE')
				),
			'bootOrder' => $this->__getBootOrder($m),
			'chipsetType' => $m->chipsetType->__toString(),
			'GUI' => array('SaveMountedAtRuntime' => $m->getExtraData('GUI/SaveMountedAtRuntime')),
			'AutoSATAPortCount' => (@$this->settings['enableAdvancedConfig'] ? $m->getExtraData('phpvb/AutoSATAPortCount') : 'no')
		);

	}

	/*
	 *
	 * Fill boot order
	 *
	 */
	private function __getBootOrder(&$m) {
		$return = array();
		$mbp = $this->vbox->systemProperties->maxBootPosition;
		for($i = 0; $i < $mbp; $i ++) {
			if(($b = $m->getBootOrder($i + 1)->__toString()) == 'Null') continue;
			$return[] = $b;
		}
		return $return;
	}

	/*
	 *
	 * Fill Serial ports 
	 *
	 */
	private function __getSerialPorts(&$m) {
		$ports = array();
		$max = intval($this->vbox->systemProperties->serialPortCount);
		for($i = 0; $i < $max; $i++) {
			try {
				$p = $m->getSerialPort($i);
				$ports[] = array(
					'slot' => $p->slot,
					'enabled' => intval($p->enabled),
					'IOBase' => '0x'.strtoupper(sprintf('%3s',dechex($p->IOBase))),
					'IRQ' => $p->IRQ,
					'hostMode' => $p->hostMode->__toString(),
					'server' => intval($p->server),
					'path' => $p->path
				);
				$p->releaseRemote();
			} catch (Exception $e) {
				// Ignore
			}
		}
		return $ports;
	}

	/*
	 *
	 * Fill LPT ports 
	 *
	 */
	private function __getParallelPorts(&$m) {
		if(!$this->settings['enableLPTConfig']) return array();
		$ports = array();
		$max = intval($this->vbox->systemProperties->parallelPortCount);
		for($i = 0; $i < $max; $i++) {
			try {
				$p = $m->getParallelPort($i);
				$ports[] = array(
					'slot' => $p->slot,
					'enabled' => intval($p->enabled),
					'IOBase' => '0x'.strtoupper(sprintf('%3s',dechex($p->IOBase))),
					'IRQ' => $p->IRQ,
					'path' => $p->path
				);
				$p->releaseRemote();
			} catch (Exception $e) {
				// Ignore
			}
		}
		return $ports;
	}
	
	/*
	 *
	 * Fill shared folders
	 *
	 */
	private function __getSharedFolders(&$m) {
		$sfs = &$m->sharedFolders;
		$return = array();
		foreach($sfs as $sf) {
			$return[] = array(
				'name' => $sf->name,
				'hostPath' => $sf->hostPath,
				'accessible' => $sf->accessible,
				'writable' => $sf->writable,
				'autoMount' => $sf->autoMount,
				'lastAccessError' => $sf->lastAccessError,
				'type' => 'machine'
			);
		}
		return $return;
	}
	

	/*
	 *
	 * Fill transient shared folders
	 *
	 */
	public function getVMTransientSharedFolders($args,&$response) {
		
		$this->__vboxwebsrvConnect();
		
		$response['data'] = array();

		$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);
		$machine = $this->vbox->findMachine($args['vm']);
		$machine->lockMachine($this->session->handle,'Shared');

		$sfs = $this->session->console->sharedFolders;
		
		foreach($sfs as $sf) {
			$response['data'][] = array(
				'name' => $sf->name,
				'hostPath' => $sf->hostPath,
				'accessible' => $sf->accessible,
				'writable' => $sf->writable,
				'autoMount' => $sf->autoMount,
				'lastAccessError' => $sf->lastAccessError,
				'type' => 'transient'
			);
		}
		
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		
		return true;
	}
	
	/*
	 *
	 * Fill medium attachments
	 *
	 */
	private function __getMediumAttachments(&$mas) {

		$return = array();

		foreach($mas as $ma) {
			$return[] = array(
				'medium' => ($ma->medium->handle ? array('id'=>$ma->medium->id) : null),
				'controller' => $ma->controller,
				'port' => $ma->port,
				'device' => $ma->device,
				'type' => $ma->type->__toString(),
				'passthrough' => $ma->passthrough
			);
		}

		return $return;
	}

	/*
	 * Save snapshot name and description
	 */
	public function saveSnapshot($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$vm = $this->vbox->findMachine($args['vm']);

		$snapshot = $vm->findSnapshot($args['snapshot']);
		$snapshot->name = $args['name'];
		$snapshot->description = $args['description'];

		// cleanup
		$snapshot->releaseRemote();
		$vm->releaseRemote();

		return ($response['data']['result'] = 1);
	}

	/*
	 * Return full snapshot details
	 */
	public function getSnapshotDetails($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$vm = $this->vbox->findMachine($args['vm']);
		$snapshot = $vm->findSnapshot($args['snapshot']);
		$machine = array();
		$this->getVMDetails(array(),$machine,$snapshot->machine);

		$response['data'] = $this->__getSnapshot($snapshot,false);
		$response['data']['machine'] = $machine['data'];

		// cleanup
		$snapshot->releaseRemote();
		$vm->releaseRemote();

		return ($response['data']['result'] = 1);

	}

	/*
	 * Restore snapshot of machine
	 */
	public function snapshotRestore($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);

			$machine = $this->vbox->findMachine($args['vm']);
			$machine->lockMachine($this->session->handle,'Write');

			$snapshot = $this->session->machine->findSnapshot($args['snapshot']);

			$progress = $this->session->console->restoreSnapshot($snapshot->handle);
			
			$snapshot->releaseRemote();
			$machine->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('__getMachine'.$args['vm'],'getMediums','__getStorageControllers'.$args['vm']));

		} catch (Exception $e) {

			$this->errors[] = $e;

			if($this->session->handle) {
				try{$this->session->unlockMachine();}catch(Exception $e){}
			}
			return ($response['data']['result'] = 0);
		}

		$response['data']['progress'] = $progress->handle;

		return ($response['data']['result'] = 1);

	}

	/*
	 * Delete snapshot of machine
	 */
	public function snapshotDelete($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

			$machine = $this->vbox->findMachine($args['vm']);
			$machine->lockMachine($this->session->handle, 'Shared');

			$progress = $this->session->console->deleteSnapshot($args['snapshot']);
			
			$machine->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('__getMachine'.$args['vm'],'getMediums','__getStorageControllers'.$args['vm']));


		} catch (Exception $e) {

			$this->errors[] = $e;

			if($this->session->handle) {
				try{$this->session->unlockMachine();$this->session=null;}catch(Exception $e){}
			}

			$response['data']['result'] = 0;
			return;
		}

		$response['data']['progress'] = $progress->handle;
		return ($response['data']['result'] = 1);
	}

	/*
	 * Take snapshot of machine
	 */
	public function snapshotTake($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$machine = $this->vbox->findMachine($args['vm']);

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);
			$machine->lockMachine($this->session->handle, ($machine->sessionState->__toString() == 'Unlocked' ? 'Write' : 'Shared'));
			$machine->releaseRemote();

			$progress = $this->session->console->takeSnapshot($args['name'],$args['description']);

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					try{$this->session->unlockMachine(); $this->session=null;}catch(Exception $e){}
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('__getMachine'.$args['vm'],'getMediums','__getStorageControllers'.$args['vm']));

		} catch (Exception $e) {

			$this->errors[] = $e;

			$response['data']['error'][] = $e->getMessage();
			$response['data']['progress'] = $progress->handle;
			$response['data']['result'] = 0;

			if(!$progress->handle && $this->session->handle) {
				try{$this->session->unlockMachine();$this->session=null;}catch(Exception $e){}
			}

			return;
		}

		$response['data']['progress'] = $progress->handle;
		return ($response['data']['result'] = 1);
	}

	/*
	 * Return a list of Snapshots for machine
	 */
	public function getSnapshots($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$machine = &$this->vbox->findMachine($args['vm']);

		/* No snapshots? Empty array */
		if($machine->snapshotCount < 1) {
			$response['data'] = array();
		} else {

			$s = $machine->findSnapshot(null);
			$response['data'] = $this->__getSnapshot($s,true);
			$s->releaseRemote();
		}

		$machine->releaseRemote();

		return true;
	}


	/*
	 *
	 * Fill snapshot info
	 *
	 */
	private function __getSnapshot(&$s,$sninfo=false) {

		$children = array();

		if($sninfo)
			foreach($s->children as $c) {
				$children[] = $this->__getSnapshot($c, true);
				$c->releaseRemote();
			}

		// Avoid multiple soap calls
		$timestamp = (string)$s->timeStamp;

		return array(
			'id' => $s->id,
			'name' => $s->name,
			'description' => $s->description,
			'timeStamp' => floor($timestamp/1000),
			'timeStampSplit' => $this->__splitTime(time() - floor($timestamp/1000)),
			'online' => $s->online,
			'machine' => ($sninfo ? $s->machine->id : null)
		) + (
			($sninfo ? array('children' => $children) : array())
		);
	}

	/*
	 *
	 * Fill Storage Controllers
	 *
	 */
	private function __getStorageControllers(&$m) {

		$sc = array();
		$scs = $m->storageControllers;

		foreach($scs as $c) {
			$sc[] = array(
				'name' => $c->name,
				'maxDevicesPerPortCount' => $c->maxDevicesPerPortCount,
				'useHostIOCache' => $c->useHostIOCache,
				'minPortCount' => $c->minPortCount,
				'maxPortCount' => $c->maxPortCount,
				'instance' => $c->instance,
				'portCount' => $c->portCount,
				'bus' => $c->bus->__toString(),
				'controllerType' => $c->controllerType->__toString(),
				'mediumAttachments' => $this->__getMediumAttachments($m->getMediumAttachmentsOfController($c->name), $m->id)
			);
			$c->releaseRemote();
		}
		return $sc;
	}

	/*
	 * Clone a medium
	 */
	public function mediumCloneTo($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$format = strtoupper(preg_replace('/.*\./','',$args['file']));
		if($format != 'VDI' && $format != 'VMDK') $format = 'VDI';
		$target = $this->vbox->createHardDisk($format,$args['file']);

		$src = $this->vbox->findMedium($args['id'],'HardDisk');

		$type = ($args['type'] == 'fixed' ? 'Fixed' : 'Standard');

		$progress = $src->cloneTo($target->handle,$type,null);
		
		$src->releaseRemote();
		$target->releaseRemote();

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,'getMediums');

		$response['data'] = array('progress' => $progress->handle);

		return true;
	}

	/*
	 * Make a medium immutable
	 */
	public function mediumSetType($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->vbox->findMedium($args['id'],'HardDisk');
		$m->type = $args['type'];
		$m->releaseRemote();

		$this->cache->expire('getMediums');

		$response['data'] = array('result' => 1,'id' => $args['id']);

		return true;
	}

	/*
	 * Add iSCSI medium
	 */
	public function mediumAddISCSI($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();
		
		// {'server':server,'port':port,'intnet':intnet,'target':target,'lun':lun,'enclun':enclun,'targetUser':user,'targetPass':pass}
		
		// Fix LUN
		$args['lun'] = intval($args['lun']);
		if($args['enclun']) $args['lun'] = 'enc'.$args['lun'];
		
		// Compose name
		$name = $args['server'].'|'.$args['target'];
		if($args['lun'] != 0 && $args['lun'] != 'enc0')
			$name .= '|'.$args['lun'];
			
		// Create disk
		$hd = $this->vbox->createHardDisk('iSCSI',$name);
		
		if($args['port']) $args['server'] .= ':'.intval($args['port']);
			
		$arrProps = array();
		
		$arrProps["TargetAddress"] = $args['server'];
		$arrProps["TargetName"] = $args['target'];
		$arrProps["LUN"] = $args['lun'];
		if($args['targetUser']) $arrProps["InitiatorUsername"] = $args['targetUser'];
		if($args['targetPass']) $arrProps["InitiatorSecret"] = $args['targetPass'];
		if($args['intnet']) $arrProps["HostIPStack"] = '0';
		
		$hd->setProperties(array_keys($arrProps),array_values($arrProps));
		
		$this->cache->expire('getMediums');
		$response['data'] = array('result' => 1, 'id' => $hd->id);
		$hd->releaseRemote();
	}

	/*
	 * Add existing medium
	 */
	public function mediumAdd($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->vbox->openMedium($args['path'],$args['type'],'ReadWrite');

		$this->cache->expire('getMediums');
		$response['data'] = array('result' => 1, 'id' => $m->id);
		$m->releaseRemote();
	}
	
	/*
	 * Compose a filename for VM
	 */
	public function getComposedMachineFilename($args,&$response) {
		
		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();
		
		$response['data']['file'] = $this->vbox->composeMachineFilename($args['name'],$this->vbox->systemProperties->defaultMachineFolder);
		
		return true;

	}
	
	/*
	 * Create base storage medium
	 */
	public function mediumCreateBaseStorage($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$format = strtoupper(preg_replace('/.*\./','',$args['file']));
		if($format != 'VDI' && $format != 'VMDK') $format = 'VDI';
		$hd = $this->vbox->createHardDisk($format,$args['file']);
		$type = ($args['type'] == 'fixed' ? 'Fixed' : 'Standard');
		$mv = new MediumVariant();
		$progress = $hd->createBaseStorage(intval($args['size'])*1024*1024,$mv->ValueMap[$type]);

		// Does an exception exist?
		try {
			if($progress->errorInfo->handle) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				$progress->releaseRemote();
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,'getMediums');

		$response['data'] = array('progress' => $progress->handle,'id' => $hd->id);
		$hd->releaseRemote();

		return true;
	}

	/*
	 * Release medium from all attachments
	 */
	public function mediumRelease($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->vbox->findMedium($args['id'],$args['type']);

		// connected to...
		$machines = $m->machineIds;
		$response['data']['released'] = array();
		foreach($machines as $uuid) {

			// Find medium attachment
			try {
				$mach = $this->vbox->findMachine($uuid);
			} catch (Exception $e) {
				// TODO: error message indicating machine no longer exists?
				continue;
			}
			$attach = $mach->mediumAttachments;
			$remove = array();
			foreach($attach as $a) {
				if($a->medium->handle && $a->medium->id == $args['id']) {
					$remove[] = array(
						'controller' => $a->controller,
						'port' => $a->port,
						'device' => $a->device);
					break;
				}
			}
			// save state
			$state = $mach->sessionState->__toString();

			if(!count($remove)) continue;
			
			$response['data']['released'][] = $uuid;

			// create session
			$this->session = &$this->websessionManager->getSessionObject($this->vbox->handle);

			// Hard disk requires machine to be stopped
			if($args['type'] == 'HardDisk' || $state == 'Unlocked') {

				$mach->lockMachine($this->session->handle, 'Write');

			} else {

				$mach->lockMachine($this->session->handle, 'Shared');

			}

			foreach($remove as $r) {
				if($args['type'] == 'HardDisk') {
					$this->session->machine->detachDevice($r['controller'],$r['port'],$r['device']);
				} else {
					$this->session->machine->mountMedium($r['controller'],$r['port'],$r['device'],null,true);
				}
			}

			$this->session->machine->saveSettings();
			$this->session->machine->releaseRemote();
			$this->session->unlockMachine();
			$this->session->releaseRemote();
			unset($this->session);
			$mach->releaseRemote();

			$this->cache->expire('__getStorageControllers'.$uuid);
		}
		$m->releaseRemote();

		$this->cache->expire('getMediums');

		return ($response['data']['result'] = 1);
	}

	/*
	 * Remove medium
	 */
	public function mediumRemove($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		if(!$args['type']) $args['type'] = 'HardDisk';
		$m = $this->vbox->findMedium($args['id'],$args['type']);

		if($args['delete'] && $this->settings['deleteOnRemove'] && $m->deviceType->__toString() == 'HardDisk') {

			$progress = $m->deleteStorage();
			
			$m->releaseRemote();

			// Does an exception exist?
			try {
				if($progress->errorInfo->handle) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					$progress->releaseRemote();
					return false;
				}
			} catch (Exception $null) { }

			$this->__storeProgress($progress,'getMediums');
			$response['data']['progress'] = $progress->handle;

		} else {
			$m->close();
			$m->releaseRemote();
			$this->cache->expire('getMediums');
		}

		return($reponse['data']['result'] = 1);
	}

	/*
	 * get Recent Mediums
	 */
	public function getRecentMediums($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		foreach(array(
			array('type'=>'HardDisk','key'=>'GUI/RecentListHD'),
			array('type'=>'DVD','key'=>'GUI/RecentListCD'),
			array('type'=>'Floppy','key'=>'GUI/RecentListFD')) as $r) {
			$list = $this->vbox->getExtraData($r['key']);
			$response['data'][$r['type']] = array_filter(explode(';', trim($list,';')));
		}
		return $response;
	}
	
	/* Update recent mediums */
	public function mediumRecentUpdate($args,&$response) {
		
		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();
		
		$types = array(
			'HardDisk'=>'GUI/RecentListHD',
			'DVD'=>'GUI/RecentListCD',
			'Floppy'=>'GUI/RecentListFD'
		);
		
		$this->vbox->setExtraData($types[$args['type']], implode(';',array_unique($args['list'])).';');
		
		return ($response['data']['result'] = 1);
	
	}
	
	/*
	 * Mount a medium on a given medium attachment (port/device)
	 */
	public function mediumMount($args,&$response,$save=false) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();
		
		// Find medium attachment
		$machine = $this->vbox->findMachine($args['vm']);
		$state = $machine->sessionState->__toString();
		$save = ($save || strtolower($machine->getExtraData('GUI/SaveMountedAtRuntime')) == 'yes');

		// create session
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);

		if($state == 'Unlocked') {
			$machine->lockMachine($this->session->handle,'Write');
			$save = true; // force save on closed session as it is not a "run-time" change
		} else {

			$machine->lockMachine($this->session->handle, 'Shared');
		}

		// Empty medium / eject
		if($args['medium'] == 0) {
			$med = null;
		} else {
			// Host drive
			if(strtolower($args['medium']['hostDrive']) == 'true' || $args['medium']['hostDrive'] === true) {
				// CD / DVD Drive
				if($args['medium']['deviceType'] == 'DVD') {
					$drives = $this->vbox->host->DVDDrives;
				// floppy drives
				} else {
					$drives = $this->vbox->host->floppyDrives;
				}
				foreach($drives as $m) {
					if($m->id == $args['medium']['id']) {
						$med = &$m;
						break;
					}
					$m->releaseRemote();
				}
			// Normal medium
			} else {
				$med = $this->vbox->findMedium($args['medium']['id'],$args['medium']['deviceType']);
			}
		}
		
		$this->session->machine->mountMedium($args['controller'],$args['port'],$args['device'],(is_object($med) ? $med->handle : null),true);
		
		if(is_object($med)) $med->releaseRemote();

		if($save) $this->session->machine->saveSettings();

		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();

		$this->cache->expire('getMediums');
		$this->cache->expire('__getStorageControllers'.$args['vm']);

		return ($response['data']['result'] = 1);
	}

	/*
	 *
	 *  Fill medium data
	 *
	 */
	private function __getMedium(&$m) {

		$children = array();
		$attachedTo = array();
		$machines = $m->machineIds;
		$hasSnapshots = 0;

		foreach($m->children as $c) {
			$children[] = $this->__getMedium($c);
			$c->releaseRemote();
		}

		foreach($machines as $mid) {
			$sids = $m->getSnapshotIds($mid);
			try {
				$mid = $this->vbox->findMachine($mid);
			} catch (Exception $e) {
				continue;
			}

			$c = count($sids);
			$hasSnapshots = max($hasSnapshots,$c);
			for($i = 0; $i < $c; $i++) {
				if($sids[$i] == $mid->id) {
					unset($sids[$i]);
				} else {
					try {
						$sn = $mid->findSnapshot($sids[$i]);
						$sids[$i] = $sn->name;
						$sn->releaseRemote();
					} catch(Exception $e) { }
				}
			}
			$hasSnapshots = (count($sids) ? 1 : 0);
			$attachedTo[] = array('machine'=>$mid->name,'snapshots'=>$sids);
			$mid->releaseRemote();
		}

		return array(
				'id' => $m->id,
				'description' => $m->description,
				'state' => $m->refreshState()->__toString(),
				'location' => $m->location,
				'name' => $m->name,
				'deviceType' => $m->deviceType->__toString(),
				'hostDrive' => $m->hostDrive,
				'size' => (string)$m->size, /* (string) to support large disks. Bypass integer limit */
				'format' => $m->format,
				'type' => $m->type->__toString(),
				'parent' => (($m->deviceType->__toString() == 'HardDisk' && $m->parent->handle) ? $m->parent->id : null),
				'children' => $children,
				'base' => (($m->deviceType->__toString() == 'HardDisk' && $m->base->handle) ? $m->base->id : null),
				'readOnly' => $m->readOnly,
				'logicalSize' => ($m->logicalSize/1024)/1024,
				'autoReset' => $m->autoReset,
				'hasSnapshots' => $hasSnapshots,
				'lastAccessError' => $m->lastAccessError,
				'machineIds' => array(),
				'attachedTo' => $attachedTo
			);

	}

	/*
	 * Store a progress operation for later use
	 */
	private function __storeProgress(&$progress,$expire=null) {

		/* Store progress operation */
		$this->cache->lock('ProgressOperations');
		$inprogress = $this->cache->get('ProgressOperations');
		if(!is_array($inprogress)) $inprogress = array();
		if($expire && !is_array($expire)) $expire = array($expire);

		// If progress is unaccessible, let getProgress()
		// handle it. Try / catch used and errors ignored.
		try { $cancelable = $progress->cancelable; }
		catch (Exception $null) {}

		$inprogress[$progress->handle] = array(
			'session'=>$this->vbox->handle,
			'progress'=>$progress->handle,
			'cancelable'=>$cancelable,
			'expire'=> $expire,
			'started'=>time());

		$this->cache->store('ProgressOperations',$inprogress);

		/* Do not destroy login session / reference to progress operation */
		$this->progressCreated = true;

		return $progress->handle;
	}

	/*
	 *
	 * Get information about this vbox installation
	 *
	 */
	private function getSystemPropertiesCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$response['data'] = array(
			'minGuestRAM' => (string)$this->vbox->systemProperties->minGuestRAM,
			'maxGuestRAM' => (string)$this->vbox->systemProperties->maxGuestRAM,
			'minGuestVRAM' => (string)$this->vbox->systemProperties->minGuestVRAM,
			'maxGuestVRAM' => (string)$this->vbox->systemProperties->maxGuestVRAM,
			'minGuestCPUCount' => (string)$this->vbox->systemProperties->minGuestCPUCount,
			'maxGuestCPUCount' => (string)$this->vbox->systemProperties->maxGuestCPUCount,
			'infoVDSize' => (string)$this->vbox->systemProperties->infoVDSize,
			'networkAdapterCount' => (string)$this->vbox->systemProperties->networkAdapterCount,
			'maxBootPosition' => (string)$this->vbox->systemProperties->maxBootPosition,
			'defaultMachineFolder' => (string)$this->vbox->systemProperties->defaultMachineFolder,
			'defaultHardDiskFormat' => (string)$this->vbox->systemProperties->defaultHardDiskFormat,
			'homeFolder' => $this->vbox->homeFolder,
			'VRDEAuthLibrary' => (string)$this->vbox->systemProperties->VRDEAuthLibrary,
			'defaultAudioDriver' => (string)$this->vbox->systemProperties->defaultAudioDriver,
			'maxGuestMonitors' => $this->vbox->systemProperties->maxGuestMonitors,
			'defaultVRDEExtPack' => $this->vbox->systemProperties->defaultVRDEExtPack,
			'serialPortCount' => $this->vbox->systemProperties->serialPortCount,
			'parallelPortCount' => $this->vbox->systemProperties->parallelPortCount
		);
		return true;
	}

	/*
	 *
	 * Return vm log names
	 *
	 */
	public function getVMLogFileNames($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->vbox->findMachine($args['vm']);
		$logs = array();
		try { $i = 0; while($l = $m->queryLogFilename($i++)) $logs[] = $l;
		} catch (Exception $null) {}
		$m->releaseRemote();
		$response['data'] = $logs;
	}

	/*
	 *
	 * Return vm log contents
	 *
	 */
	public function getVMLogFile($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->vbox->findMachine($args['vm']);
		try {
			$o = 0; $s = 8192; // 8k chunks
			while($l = $m->readLog(intval($args['log']),$o,$s)) {
				@$response['data']['log'] .= implode('',array_map('chr',$l));
				$o+=count($l);
			}
		} catch (Exception $null) {}
		$m->releaseRemote();
	}

	/*
	 * 
	 * List of attached USB devices
	 * 
	 */
	public function getVMUSBDevices($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();
		
		$machine = $this->vbox->findMachine($args['vm']);
		$this->session = $this->websessionManager->getSessionObject($this->vbox->handle);
		$machine->lockMachine($this->session->handle, 'Shared');
		
		$response['data'] = array();
		foreach($this->session->console->USBDevices as $u) {
			$response['data'][$u->id] = array('id'=>$u->id,'remote'=>$u->remote);
			$u->releaseRemote();
		}
		
		$this->session->unlockMachine();
		$this->session->releaseRemote();
		unset($this->session);
		$machine->releaseRemote();
		
		
	}

	/*
	 *
	 * Format a time
	 *
	 */
	private function __splitTime($t) {

		$spans = array(
			'days' => 86400,
			'hours' => 3600,
			'minutes' => 60,
			'seconds' => 1);

		$time = array();

		foreach($spans as $k => $v) {
			if(!(floor($t / $v) > 0)) continue;
			$time[$k] = floor($t / $v);
			$t -= floor($time[$k] * $v);
		}

		return $time;
	}


}

