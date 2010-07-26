<?php
/*
 * $Id: vboxconnector.php 718 2010-07-24 18:19:33Z ian $
 */

class vboxconnector {

	// Fatal error number
	const PHPVB_ERRNO_FATAL = 32;

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


	/*
	 * Caching settings. Function => time in seconds. 0 == do not cache
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
			'__getSharedFolders' => 7200,
			'__getUSBController' => 7200,
	);

	// Any exceptions generated
	var $errors = array();

	// Is set to true when a progress operation is created
	var $progressCreated = false;

	public function __construct () {

		require_once(dirname(__FILE__).'/cache.php');
		require_once(dirname(__FILE__).'/language.php');
		require_once(dirname(__FILE__).'/vboxServiceWrappers.php');

		/* Set.. .. settings */
		$settings = new phpVBoxConfig();
		$this->settings = get_object_vars($settings);
		if(!$this->settings['nicMax']) $this->settings['nicMax'] = 4;

		// Check for SoapClient class
		if(!class_exists('SoapClient')) {
			define('VBOX_ERR_FATAL','PHP does not have the SOAP extension enabled.');
			return;
		}

		// Cache handler object.
		$this->cache = new cache();
		$this->cache->expire_multiplier = 1; // default

		if($this->settings['disableCache']) { $this->cacheSettings = array(); }
		else if(intval($this->settings['cacheExpireMultiplier'])) {
			$this->cache->expire_multiplier = intval($this->settings['cacheExpireMultiplier']);
			if(@is_array($this->settings['cacheSettings']))
				$this->cacheSettings = array_merge($this->cacheSettings,$this->settings['cacheSettings']);
		}

		if($this->settings['cachePath']) $this->cache->path = $this->settings['cachePath'];

		$this->cache->prefix = 'pvbx-'.md5(parse_url($this->settings['location'],PHP_URL_HOST)).'-';

	}

	/*
	 *
	 * Connect to vboxwebsrv
	 *
	 */
	private function __vboxwebsrvConnect() {

		// Already connected?
		if($this->connected) return true;


		//Connect to webservice
		$this->client = new SoapClient(dirname(__FILE__)."/vboxwebService.wsdl",
		    array(
		    	'features' => (SOAP_USE_XSI_ARRAY_TYPE + SOAP_SINGLE_ELEMENT_ARRAYS),
		        'cache_wsdl'=>WSDL_CACHE_MEMORY,
		        'trace'=>($this->settings['debugSoap']),
				'connection_timeout' => ($this->settings['connectionTimeout'] ? $this->settings['connectionTimeout'] : 20),
		        'location'=>$this->settings['location'],
		        'soap_version'=>SOAP_1_2));

		/* Try / catch / throw here hides login credentials from exception if one is thrown */
		try {
			$this->websessionManager = new IWebsessionManager($this->client);
			$this->vbox = $this->websessionManager->logon($this->settings['username'],$this->settings['password']);
		} catch (Exception $e) {
			throw new Exception($e->getMessage(),vboxconnector::PHPVB_ERRNO_FATAL);
		}


		// Error logging in
		if(!(string)$this->vbox) {
			throw new Exception('Error logging in or connecting to vboxwebsrv.',vboxconnector::PHPVB_ERRNO_FATAL);
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
				'sub'=>intval(array_shift($this->version))
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
		if($this->connected && !$this->progressCreated && (string)$this->vbox) {

			if((string)$this->session) {
				try {$this->session->close();}
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

		$req = &$args[0];
		$response = &$args[1][0]; # fix for allow_call_time_pass_reference = Off setting

		/*
		 *  Check for fatal initialization error
		 */
		if(@constant('VBOX_ERR_FATAL')) {
			throw new Exception(constant('VBOX_ERR_FATAL'),vboxconnector::PHPVB_ERRNO_FATAL);
		}

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
			if(!$this->cacheSettings[$fn]) {

				$this->{$fn.'Cached'}($req,$response);

			// cached data exists ? return it : get data, cache data, return data
			} else if(@$req['force_refresh'] || (($response['data'] = $this->cache->get($fn,$this->cacheSettings[$fn])) === false)) {

				$lock = $this->cache->lock($fn);

				// file was modified while attempting to lock.
				// file data is returned
				if($lock === null) {
					$response['data'] = $this->cache->get($fn,$this->cacheSettings[$fn]);

				// lock obtained
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
	 * Set VM Description
	 */
	public function setDescription($args,&$response) {

		$response['data']['vm'] = $args['vm'];

		$this->__vboxwebsrvConnect();

		$this->session = $this->websessionManager->getSessionObject($this->vbox);
		$this->vbox->openSession($this->session, $args['vm']);
		$this->session->machine->description = $args['description'];
		$this->session->machine->saveSettings();
		$this->session->close();
		$this->session = null;

		$this->cache->expire('__getMachine'.$args['vm']);

		return ($response['data']['result'] = 1);
	}

	/*
	 * Enumerate guest properties of vm
	 */
	public function enumerateGuestProperties($args,&$response) {

		$this->__vboxwebsrvConnect();

		$m = $this->__getMachineRef($args['vm']);

		$response['data'] = $m->enumerateGuestProperties($args['pattern']);
		$m->releaseRemote();

		return true;

	}

	/*
	 * Save all VM settings
	 */
	public function saveVM($args,&$response) {

		$this->__vboxwebsrvConnect();

		// create session
		$this->session = &$this->websessionManager->getSessionObject($this->vbox);
		$this->vbox->openSession($this->session, $args['id']);

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
		if($m->snapshotFolder != $args['snapshotFolder']) $m->snapshotFolder = $args['snapshotFolder'];

		$m->VRAMSize = $args['VRAMSize'];

		/* Unsupported at this time
		$m->monitorCount = max(1,intval($args['monitorCount']));
		$m->accelerate3DEnabled = $args['accelerate3DEnabled'];
		$m->accelerate2DVideoEnabled = $args['accelerate2DVideoEnabled'];
		*/

		/* Only if acceleration configuration is enabled */
		if($this->settings['enableAccelerationConfig']) {
			$m->setHWVirtExProperty('Enabled',($args['HWVirtExProperties']['Enabled'] ? 1 : 0));
			$m->setHWVirtExProperty('NestedPaging', ($args['HWVirtExProperties']['NestedPaging'] ? 1 : 0));
		}

		$m->RTCUseUTC = ($args['RTCUseUTC'] ? 1 : 0);


		$m->setCpuProperty('PAE', ($args['CpuProperties']['PAE'] ? 1 : 0));

		$m->setExtraData('GUI/SaveMountedAtRuntime', ($args['GUI']['SaveMountedAtRuntime'] == 'no' ? 'no' : 'yes'));

		// IOAPIC
		$m->BIOSSettings->IOAPICEnabled = ($args['BIOSSettings']['IOAPICEnabled'] ? 1 : 0);

		// VRDP settings
		if(!$version['ose']) {
			$m->VRDPServer->enabled = $args['VRDPServer']['enabled'] ? 1 : 0;
			$m->VRDPServer->ports = $args['VRDPServer']['ports'];
			$m->VRDPServer->authType = ($args['VRDPServer']['authType'] ? $args['VRDPServer']['authType'] : null);
			$m->VRDPServer->authTimeout = intval($args['VRDPServer']['authTimeout']);
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
		$attached = array();
		foreach($scs as $sc) {
			$mas = $m->getMediumAttachmentsOfController($sc->name);
			foreach($mas as $ma) {
				if((string)$ma->medium && $ma->medium->id) $attached[$ma->medium->id] = true;
				if($ma->controller) {
					$m->detachDevice($ma->controller,$ma->port,$ma->device);
				}
			}
			$scname = $sc->name;
			$m->removeStorageController($scname);
		}

		// Add New
		foreach($args['storageControllers'] as $sc) {

			$sc['name'] = trim($sc['name']);
			$name = ($sc['name'] ? $sc['name'] : $sc['bus'].' Controller');

			$bust = new StorageBus(null,$sc['bus']);
			$c = $m->addStorageController($name,$bust);
			$c->controllerType = $sc['controllerType'];
			$c->useHostIOCache = ($sc['useHostIOCache'] ? 1 : 0);

			// Medium attachments
			foreach($sc['mediumAttachments'] as $ma) {
				if($ma['medium']['id']) unset($attached[$ma['medium']['id']]);
				$m->attachDevice($name,$ma['port'],$ma['device'],$ma['type'],($ma['medium'] && $ma['medium']['id'] ? $ma['medium']['id'] : null));
				if($ma['type'] == 'DVD') $m->passthroughDevice($name,$ma['port'],$ma['device'],($ma['passthrough'] ? true : false));
			}
		}
		// Expire storage
		$expire[] = '__getStorageControllers'.$args['id'];
		// Expire mediums?
		if(count($attached))
			$expire[] = '__getMediums';


		/*
		 *
		 * When changing the following items, try our best to preserve existing
		 * cache at the expense of some PHP processing
		 *
		 */


		// Network Adapters
		$netchanged = false;
		$netprops = array('adapterType','enabled','MACAddress','hostInterface','internalNetwork','NATNetwork','cableConnected','attachmentType');
		$adapters = $this->__getCachedMachineData('__getNetworkAdapters',$args['id'],$this->session->machine);
		for($i = 0; $i < count($args['networkAdapters']); $i++) {

			// Is there a property diff?
			$ndiff = false;
			foreach($netprops as $p) {
				if($args['networkAdapters'][$i][$p] == $adapters[$i][$p]) continue;
				$ndiff = true;
				break;
			}

			if(!$ndiff) { continue; }
			$netchanged = true;


			$n = $m->getNetworkAdapter($i);
			for($p = 0; $p < (count($netprops) - 1); $p++) {
				$n->$netprops[$p] = $args['networkAdapters'][$i][$netprops[$p]];
			}

			switch($args['networkAdapters'][$i]['attachmentType']) {
				case 'Bridged':
					$n->attachToBridgedInterface();
					break;
				case 'Internal':
					$n->attachToInternalNetwork();
					break;
				case 'HostOnly':
					$n->attachToHostOnlyInterface();
					break;
				case 'NAT':
					$n->attachToNAT();
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


		// Shared Folders
		$sharedchanged = false;
		$sharedEx = array();
		$sharedNew = array();
		foreach($this->__getCachedMachineData('__getSharedFolders',$args['id'],$m) as $s) {
			$sharedEx[$s['name']] = array('name'=>$s['name'],'hostPath'=>$s['hostPath'],'writable'=>(bool)$s['writable']);
		}
		foreach($args['sharedFolders'] as $s) {
			$sharedNew[$s['name']] = array('name'=>$s['name'],'hostPath'=>$s['hostPath'],'writable'=>(bool)$s['writable']);
		}
		// Compare
		if(count($sharedEx) != count($sharedNew) || (@serialize($sharedEx) != @serialize($sharedNew))) {
			$sharedchanged = true;
			foreach($sharedEx as $s) { $m->removeSharedFolder($s['name']);}
			try {
				foreach($sharedNew as $s) {
					$m->createSharedFolder($s['name'],$s['hostPath'],$s['writable']);
				}
			} catch (Exception $e) { $this->errors[] = $e; }
		}
		// Expire shared folders?
		if($sharedchanged) $expire[] = '__getSharedFolders'.$args['id'];


		// USB Filters
		if(!$version['ose']) {

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

					// Remove existing
					for($i = 0; $i < $max; $i++) {

						// Only if filter differs
						if(@serialize($usbc['deviceFilters'][$i]) != @serialize($args['USBController']['deviceFilters'][$i])) {

							// Remove existing?
							if(count($usbc['deviceFilters'][$i]))
								$m->USBController->removeDeviceFilter($i);

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
							}
						}

					}

				} catch (Exception $e) { $this->errors[] = $e; }

			}

			// Expire USB info?
			if($usbchanged) $expire[] = '__getUSBController'.$args['id'];
		}


		$this->session->machine->saveSettings();
		$this->session->close();
		$this->session = null;

		// Expire cache
		foreach(array_unique($expire) as $ex)
			$this->cache->expire($ex);

		$response['data']['result'] = 1;

		return true;
	}


	/*
	 * Return cached result from machine request
	 */
	private function __getCachedMachineData($fn,$key,&$item,$force_refresh=false) {

		// do not cache
		if(!$this->cacheSettings[$fn] || !$key) {

			return $this->$fn($item);

		// Cached data exists?
		} else if(!$force_refresh && ($result = $this->cache->get($fn.$key,$this->cacheSettings[$fn])) !== false) {

			return $result;

		} else {

			$lock = $this->cache->lock($fn.$key);

			// file was modified while attempting to lock.
			// file data is returned
			if($lock === null) {

				return $this->cache->get($fn.$key,$this->cacheSettings[$fn]);

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
				$session = $this->websessionManager->getSessionObject($vbox);
				// Force web call
				if((string)$session->state) {}
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
					if(!$response['data']['info']['canceled'] && (string)$progress->errorInfo) {
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
				if((string)$progress->errorInfo) {
					$this->errors[] = new Exception($progress->errorInfo->text);
				}
			} catch (Exception $null) {}

			// Some progress operations seem to go away after completion
			// probably the result of automatic session closure
			if(!((string)$session && (string)$session->state == 'Closed'))
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

				$session = $this->websessionManager->getSessionObject($vbox);

				if((string)$session && (string)$session->state != 'Closed')
					$session->close();

			} catch (Exception $null) { }


			// Logoff
			$this->websessionManager->logoff($vbox);

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
		$this->vbox->systemProperties->defaultHardDiskFolder = $args['SystemProperties']['defaultHardDiskFolder'];
		$this->vbox->systemProperties->remoteDisplayAuthLibrary = $args['SystemProperties']['remoteDisplayAuthLibrary'];

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
			if((string)$progress->errorInfo) {
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
			$d->setFinalValues($args['descriptions'][$a][5],$args['descriptions'][$a][3],$args['descriptions'][$a][4]);
			$a++;
		}

		$progress = $app->importMachines();

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress,'getMediums');

		$response['data']['progress'] = (string)$progress;

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

			$response['data'][] = array(
				'name' => $machine->name,
				'state' => (string)$machine->state,
				'OSTypeId' => $machine->getOSTypeId(),
				'id' => $machine->id,
				'description' => $machine->description
			);
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
			if((string)$progress->errorInfo) {
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
				$desc[] = (string)$ddesc;
			}
			$response['data']['descriptions'][$i][0] = $desc;
			$i++;
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
			$m = $this->vbox->getMachine($vm['id']);
			$desc = $m->export($app);
			$props = $desc->getDescription();
			$ptypes = array();
			foreach($props[0] as $p) {$ptypes[] = (string)$p;}
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
			$enabled = array_pad(array(),count($props[3]),(bool)true);
			$desc->setFinalValues($enabled,$props[3],$props[4]);
			$desc->releaseRemote();
			$m->releaseRemote();
		}

		$progress = $app->write(($args['format'] ? $args['format'] : 'ovf-1.0'),$args['file']);

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress);

		$response['data']['progress'] = (string)$progress;

		return true;
	}
	/*
	 * Get host networking info
	 */
	private function getHostNetworkingCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		/*
		 * NICs
		 */
		foreach($this->vbox->host->networkInterfaces as $d) {
			$response['data']['networkInterfaces'][] = array(
				'name' => $d->name,
				'interfaceType' => (string)$d->interfaceType,
			);
		}

		/*
		 * Existing Networks
		 */
		$networks = array();
		foreach($this->vbox->machines as $machine) {

			for($i = 0; $i < $this->settings['nicMax']; $i++) {

				$h = &$machine->getNetworkAdapter($i);

				if($h->enabled && $h->internalNetwork)
					$networks[$h->internalNetwork] = 1;

			}
		}
		$response['data']['networks'] = array_keys($networks);

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
		foreach($this->vbox->host->networkInterfaces as $d) {

			if($d->interfaceType != 'HostOnly') continue;

			// Get DHCP Info
			try {
				$dhcp = $this->vbox->findDHCPServerByNetworkName($d->networkName);
			} catch (Exception $e) {};

			if((string)$dhcp) {
				$dhcp = array(
					'enabled' => $dhcp->enabled,
					'IPAddress' => $dhcp->IPAddress,
					'networkMask' => $dhcp->networkMask,
					'networkName' => $dhcp->networkName,
					'lowerIP' => $dhcp->lowerIP,
					'upperIP' => $dhcp->upperIP
				);
			} else {
				$dhcp = array();
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
				'dhcpServer' => $dhcp
			);
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
			if((string)$dhcp) {
				$dhcp->enabled = (bool)@$nics[$i]['dhcpServer']['enabled'];
				$dhcp->setConfiguration($nics[$i]['dhcpServer']['IPAddress'],$nics[$i]['dhcpServer']['networkMask'],$nics[$i]['dhcpServer']['lowerIP'],$nics[$i]['dhcpServer']['upperIP']);
			}

		}

		return ($response['data']['result'] = 1);

	}

	/*
	 * Add Host-only interface
	 */
	public function createHostOnlyInterface($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->vbox->host->createHostOnlyNetworkInterface();

		if(!(is_array($progress) && (string)$progress[0])) return false;
		$progress = array_shift($progress);

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress);

		$response['data']['progress'] = (string)$progress;

		return true;

	}


	/*
	 * Remove network interface
	 */
	public function removeHostOnlyInterface($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->vbox->host->removeHostOnlyNetworkInterface($args['id']);

		if(!(string)$progress) return false;

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		// Save progress
		$this->__storeProgress($progress);

		$response['data']['result'] = 1;
		$response['data']['progress'] = (string)$progress;

		return true;
	}

	/*
	 * Populate a list of Guest OS types
	 */
	private function getGuestOSTypesCached($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$ts = $this->vbox->getGuestOSTypes();

		foreach($ts as $g) {

			$response['data'][] = array(
				'familyId' => $g->familyId,
				'familyDescription' => $g->familyDescription,
				'id' => $g->id,
				'description' => $g->description,
				'is64Bit' => $g->is64Bit,
				'recommendedRAM' => $g->recommendedRAM,
				'recommendedHDD' => $g->recommendedHDD
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
			'forgetSavedState' => array('result'=>'poweredOff','session'=>'direct','force'=>true)
		);

		// Check for valid state
		if(!is_array($states[$state])) {
			$response['data']['result'] = 0;
			throw new Exception('Invalid state: ' . $state);
		}

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		// Machine state
		$m = $this->__getMachineRef($vm);
		$mstate = $m->state;
		$m->releaseRemote();

		// If state has an expected result, check
		// that we are not already in it
		if($states[$state]['result']) {
			if($mstate == $states[$state]['result']) {
				$response['data']['result'] = 0;
				throw new Exception('Machine is already in requested state.');
			}
		}

		// Special case for power up
		if($state == 'powerUp' && $mstate == 'Paused') {
			return $this->__setVMState($vm,'resume',$response);
		} else if($state == 'powerUp') {
			return $this->__openRemoteSession($vm,$response);
		}

		// Open session to machine
		$this->session = &$this->websessionManager->getSessionObject($this->vbox);

		if($states[$state]['session'] == 'direct') {
			$this->vbox->openSession($this->session, $vm);
		} else {

			// VRDP is not supported in OSE
			$version = $this->getVersion();
			$sessionType = ($version['ose'] ? 'headless' : 'vrdp');

			$this->vbox->openExistingSession($this->session, $vm, $sessionType, '');
		}

		// If this operation returns a progress object save progress
		$progress = null;
		if($states[$state]['progress']) {

			$progress = $this->session->console->$state();

			if(!(string)$progress) {

				// should never get here
				try {
					$this->session->close();
					$this->session = null;
				} catch (Exception $e) {};

				$response['data']['result'] = 0;
				throw new Exception('Unknown error settings machine to requested state.');
			}

			// Does an exception exist?
			try {
				if((string)$progress->errorInfo) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					return false;
				}
			} catch (Exception $null) {}

			// Save progress
			$this->__storeProgress($progress,null);

			$response['data']['progress'] = (string)$progress;

		// Operation does not return a progress object
		// Just call the function
		} else {

			$this->session->console->$state(($states[$state]['force'] ? true : null));

		}

		// Check for ACPI button
		if($states[$state]['acpi'] && !$this->session->console->getPowerButtonHandled()) {
			$this->session->console->releaseRemote();
			$response['data']['result'] = 0;
			$this->session->close();
			$this->session = null;
			throw new Exception(trans('ACPI event not handled'));
		}


		if(!(string)$progress) {
			$this->session->console->releaseRemote();
			$this->session->close();
			$this->session=null;
		}

		$response['data']['result'] = 1;

		return true;

	}

	/*
	 *
	 * Open a remote session for machine. This starts a VM
	 *
	 */
	private function __openRemoteSession($vm, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		# Try opening session for VM
		try {
			// create session
			$this->session = &$this->websessionManager->getSessionObject($this->vbox);

			// VRDP is not supported in OSE
			$version = $this->getVersion();
			$sessionType = ($version['ose'] ? 'headless' : 'vrdp');

			$progress = $this->vbox->openRemoteSession($this->session, $vm, $sessionType, '');

		} catch (Exception $e) {
			// Error opening session
			$this->errors[] = $e;
			return ($response['data']['result'] = 0);
		}

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,null);

		$response['data']['progress'] = (string)$progress;

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
			'Acceleration3DAvailable' => $host->Acceleration3DAvailable,
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
		 * NICs
		 */
		foreach($host->networkInterfaces as $d) {
			$response['data']['networkInterfaces'][] = array(
				'name' => $d->name,
				'IPAddress' => $d->IPAddress,
				'networkMask' => $d->networkMask,
				'IPV6Address' => $d->IPV6Address,
				'IPV6NetworkMaskPrefixLength' => $d->IPV6NetworkMaskPrefixLength,
				'status' => (string)$d->status,
				'mediumType' => (string)$d->mediumType,
				'interfaceType' => (string)$d->interfaceType,
				'hardwareAddress' => $d->hardwareAddress,
				'networkName' => $d->networkName,
			);
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

		return true;
	}

	/*
	 * Get a list of connected USB devices
	 */
	public function getHostUSBDevices($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		// Not supported in OSE
		$version = $this->getVersion();
		if($version['ose']) return true;


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
				'state' => (string)$d->state,
				);
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
		if($args['vm'] == 'host') return @$this->getHostDetails($args, $response);



		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$version = $this->getVersion();

		//Get registered machine or snapshot machine
		if($snapshot) {

			$machine = &$snapshot;

		} else {

			$machine = &$this->__getMachineRef($args['vm']);


			// For correct caching, always use id
			$args['vm'] = $machine->id;

			// Check for accessibility
			if(!$machine->accessible) {

				$response['data'] = array(
					'name' => $machine->id,
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

		// Shared Folders
		$data['sharedFolders'] = $this->__getCachedMachineData('__getSharedFolders',@$args['vm'],$machine,@$args['force_refresh']);


		// USB Filters
		if(!$version['ose']) {
			$data['USBController'] = $this->__getCachedMachineData('__getUSBController',@$args['vm'],$machine,@$args['force_refresh']);
		}

		// Non-cached items when not obtaining
		// snapshot machine info
		if(!$snapshot) {

			$data['state'] = (string)$machine->state;
			$data['currentSnapshot'] = ((string)$machine->currentSnapshot ? array('id'=>$machine->currentSnapshot->id,'name'=>$machine->currentSnapshot->name) : null);
			$data['snapshotCount'] = $machine->snapshotCount;
			$data['sessionState'] = (string)$machine->sessionState;
			$data['currentStateModified'] = $machine->currentStateModified;
			$machine->releaseRemote();

		}

		$data['accessible'] = 1;
		$response['data'] = $data;

		return true;
	}

	/*
	 *
	 * Remove a Virtual Machine
	 *
	 */
	public function removeVM($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		// Only unregister or delete?
		if($args['delete']) {

			// Open session
			$this->session = &$this->websessionManager->getSessionObject($this->vbox);
			$this->vbox->openSession($this->session, $args['vm']);

			// Detach any mediums ( may not be needed? )
			foreach($this->session->machine->mediumAttachments as $ma) {
				try {
					$mac = array('controller'=>$ma->controller,'port'=>$ma->port,'device'=>$ma->device);
					$this->session->machine->detachDevice($mac['controller'],$mac['port'],$mac['device']);
				} catch (Exception $e) { $this->errors[] = $e; }
			}

			// Close session and save
			$this->session->machine->saveSettings();
			$this->session->close();
			$this->session = null;

			$m = $this->vbox->unregisterMachine($args['vm']);
			$m->deleteSettings();
			$m->releaseRemote();

		} else {

			$m = $this->vbox->unregisterMachine($args['vm']);
			$m->releaseRemote();

		}

		// Clear caches
		foreach(array('getMachine','getNetworkAdapters','getStorageControllers','getSharedFolders','getUSBController') as $ex) {
			$this->cache->expire('__'.$ex.$args['vm']);
		}
		$this->cache->expire('getMediums');

		return ($response['data']['result'] = 1);
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
		$m = $this->vbox->createMachine($args['name'],$args['ostype'],null,null);

		// Set memory
		$m->memorySize = intval($args['memory']);


		// Save and register
		$m->saveSettings();
		$this->vbox->registerMachine($m);
		$vm = $m->id;
		$m->releaseRemote();

		try {

			$this->session = $this->websessionManager->getSessionObject($this->vbox);
			$this->vbox->openSession($this->session, $vm);

			// OS defaults
			$defaults = $this->vbox->getGuestOSType($args['ostype']);


			// Always set
			$this->session->machine->setExtraData('GUI/SaveMountedAtRuntime', 'yes');
			if(!$version['ose']) {
				$this->session->machine->USBController->enabled = true;
				$this->session->machine->USBController->enabledEhci = true;
				$this->session->machine->VRDPServer->authTimeout = 5000;
			}

			// Other defaults
			$this->session->machine->BIOSSettings->IOAPICEnabled = $defaults->recommendedIOAPIC;
			$this->session->machine->setHWVirtExProperty('Enabled',$defaults->recommendedVirtEx);
			$this->session->machine->setCpuProperty('PAE',$defaults->recommendedPae);
			$this->session->machine->RTCUseUTC = $defaults->recommendedRtcUseUtc;
			$this->session->machine->firmwareType = (string)$defaults->recommendedFirmware;
			if(intval($defaults->recommendedVRAM) > 0) $this->session->machine->VRAMSize = intval($defaults->recommendedVRAM);

			/*
			 * Hard Disk and DVD/CD Drive
			 */
			$DVDbusType = (string)$defaults->recommendedDvdStorageBus;
			$DVDconType = (string)$defaults->recommendedDvdStorageController;

			// Attach harddisk?
			if($args['disk']) {

				$HDbusType = (string)$defaults->recommendedHdStorageBus;
				$HDconType = (string)$defaults->recommendedHdStorageController;

				$bus = new StorageBus(null,$HDbusType);
				$sc = $this->session->machine->addStorageController(trans($HDbusType.' Controller'),$bus);
				$sc->controllerType = $HDconType;
				$sc->releaseRemote();

				$this->session->machine->attachDevice(trans($HDbusType.' Controller'),0,0,'HardDisk',(string)$args['disk']);


			}

			// Attach DVD/CDROM
			if($DVDbusType) {

				if(!$args['disk'] || ($HDbusType != $DVDbusType)) {

					$bus = new StorageBus(null,$DVDbusType);
					$sc = $this->session->machine->addStorageController(trans($DVDbusType.' Controller'),$bus);
					$sc->controllerType = $DVDconType;
					$sc->releaseRemote();
				}

				$this->session->machine->attachDevice(trans($DVDbusType.' Controller'),1,0,'DVD',null);

			}

			$this->session->machine->saveSettings();
			$this->session->close();
			$this->session = null;

			if($args['disk']) $this->cache->expire('getMediums');

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

		//Get a list of registered machines
		$machines = $this->vbox->machines;

		foreach ($machines as $machine) {

			try {
				$response['data'][] = array(
					'name' => $machine->name,
					'state' => (string)$machine->state,
					'OSTypeId' => $machine->getOSTypeId(),
					'id' => $machine->id,
					'sessionState' => (string)$machine->sessionState,
					'currentSnapshot' => ((string)$machine->currentSnapshot ? $machine->currentSnapshot->name : '')
				);

			} catch (Exception $e) {

				if($machine) {

					$response['data'][] = array(
						'name' => $machine->id,
						'state' => 'Inaccessible',
						'OSTypeId' => 'Other',
						'id' => $machine->id,
						'sessionState' => 'Inaccessible',
						'currentSnapshot' => ''
					);

				} else {
					$this->errors[] = $e;
				}
			}
		}
		if(!is_array($response['data']) || !count($response['data'])) $response['data']['empty'] = 1;
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
			'adapterType' => (string)$n->adapterType,
			'slot' => $n->slot,
			'enabled' => $n->enabled,
			'MACAddress' => $n->MACAddress,
			'attachmentType' => (string)$n->attachmentType,
			'hostInterface' => $n->hostInterface,
			'internalNetwork' => $n->internalNetwork,
			'NATNetwork' => $n->NATNetwork,
			'cableConnected' => $n->cableConnected,
			'lineSpeed' => $n->lineSpeed
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
				'active' => (string)$df->active,
				'vendorId' => $df->vendorId,
				'productId' => $df->productId,
				'revision' => $df->revision,
				'manufacturer' => $df->manufacturer,
				'product' => $df->product,
				'serialNumber' => $df->serialNumber,
				'port' => $df->port,
				'remote' => $df->remote
				);
		}
		return array(
			'enabled' => $u->enabled,
			'enabledEhci' => $u->enabledEhci,
			'deviceFilters' => $deviceFilters);
	}

	/*
	 *
	 *
	 * Fill Machine data
	 *
	 */
	private function __getMachine(&$m) {

		$version = $this->getVersion();

		return array(
			'name' => $m->name,
			'description' => $m->description,
			'id' => $m->id,
			'OSTypeId' => $m->OSTypeId,
			'CPUCount' => $m->CPUCount,
			'memorySize' => $m->memorySize,
			'VRAMSize' => $m->VRAMSize,
			'accelerate3DEnabled' => $m->accelerate3DEnabled,
			'accelerate2DVideoEnabled' => $m->accelerate2DVideoEnabled,
			'BIOSSettings' => array(
				'ACPIEnabled' => $m->BIOSSettings->ACPIEnabled,
				'IOAPICEnabled' => $m->BIOSSettings->IOAPICEnabled,
				'timeOffset' => $m->BIOSSettings->timeOffset
				),
			'firmwareType' => (string)$m->firmwareType,
			'snapshotFolder' => $m->snapshotFolder,
			'monitorCount' => $m->monitorCount,
			'VRDPServer' => ($version['ose'] ? null : array(
				'enabled' => $m->VRDPServer->enabled,
				'ports' => $m->VRDPServer->ports,
				'netAddress' => $m->VRDPServer->netAddress,
				'authType' => (string)$m->VRDPServer->authType,
				'authTimeout' => $m->VRDPServer->authTimeout
				)),
			'audioAdapter' => array(
				'enabled' => $m->audioAdapter->enabled,
				'audioController' => (string)$m->audioAdapter->audioController,
				'audioDriver' => (string)$m->audioAdapter->audioDriver,
				),
			'clipboardMode' => (string)$m->clipboardMode,
			'RTCUseUTC' => $m->RTCUseUTC,
			'HWVirtExProperties' => array(
				'Enabled' => $m->getHWVirtExProperty('Enabled'),
				'NestedPaging' => $m->getHWVirtExProperty('NestedPaging')
				),
			'CpuProperties' => array(
				'PAE' => $m->getCpuProperty('PAE')
				),
			'bootOrder' => $this->__getBootOrder($m),
			'GUI' => array('SaveMountedAtRuntime' => $m->getExtraData('GUI/SaveMountedAtRuntime')),

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
			if(($b = (string)$m->getBootOrder($i + 1)) == 'Null') continue;
			$return[] = $b;
		}
		return $return;
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
				'lastAccessError' => $sf->lastAccessError
			);
		}
		return $return;
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
				'medium' => ((string)$ma->medium ? array('id'=>$ma->medium->id) : null),
				'controller' => $ma->controller,
				'port' => $ma->port,
				'device' => $ma->device,
				'type' => (string)$ma->type,
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

		$vm = $this->__getMachineRef($args['vm']);

		$snapshot = $vm->getSnapshot($args['snapshot']);
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

		$vm = $this->__getMachineRef($args['vm']);
		$snapshot = $vm->getSnapshot($args['snapshot']);
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
	public function restoreSnapshot($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = &$this->websessionManager->getSessionObject($this->vbox);
			$this->vbox->openSession($this->session, $args['vm']);

			$snapshot = $this->session->machine->getSnapshot($args['snapshot']);

			$progress = $this->session->console->restoreSnapshot($snapshot);

			// Does an exception exist?
			try {
				if((string)$progress->errorInfo) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('__getMachine'.$args['vm'],'getMediums','__getStorageControllers'.$args['vm']));

		} catch (Exception $e) {

			$this->errors[] = $e;

			if((string)$this->session) {
				try{$this->session->close();}catch(Exception $e){}
			}
			return ($response['data']['result'] = 0);
		}

		$response['data']['progress'] = (string)$progress;

		return ($response['data']['result'] = 1);

	}

	/*
	 * Delete snapshot of machine
	 */
	public function deleteSnapshot($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$progress = $this->session = null;

		try {

			// Open session to machine
			$this->session = $this->websessionManager->getSessionObject($this->vbox);
			$this->vbox->openSession($this->session, $args['vm']);

			$progress = $this->session->console->deleteSnapshot($args['snapshot']);

			// Does an exception exist?
			try {
				if((string)$progress->errorInfo) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('__getMachine'.$args['vm'],'getMediums','__getStorageControllers'.$args['vm']));


		} catch (Exception $e) {

			$this->errors[] = $e;

			if((string)$this->session) {
				try{$this->session->close();$this->session=null;}catch(Exception $e){}
			}

			$response['data']['result'] = 0;
			return;
		}

		$response['data']['progress'] = (string)$progress;
		return ($response['data']['result'] = 1);
	}

	/*
	 * Take snapshot of machine
	 */
	public function takeSnapshot($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->__getMachineRef($args['vm']);
		$state = (string)$m->sessionState;
		$m->releaseRemote();

		$progress = $session = null;

		try {

			// VRDP is not supported in OSE
			$version = $this->getVersion();
			$sessionType = ($version['ose'] ? 'headless' : 'vrdp');

			// Open session to machine
			$session = &$this->websessionManager->getSessionObject($this->vbox);
			if($state == 'Closed') $this->vbox->openSession($session, $args['vm']);
			else $this->vbox->openExistingSession($session, $args['vm'], $sessionType, '');

			$progress = $session->console->takeSnapshot($args['name'],$args['description']);

			// Does an exception exist?
			try {
				if((string)$progress->errorInfo) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,array('__getMachine'.$args['vm'],'getMediums','__getStorageControllers'.$args['vm']));

		} catch (Exception $e) {

			$this->errors[] = $e;

			$response['data']['error'][] = $e->getMessage();
			$response['data']['progress'] = (string)$progress;
			$response['data']['result'] = 0;

			if(!(string)$progress && (string)$session) {
				try{$session->close();}catch(Exception $e){}
			}

			return;
		}

		$response['data']['progress'] = (string)$progress;
		return ($response['data']['result'] = 1);
	}

	/*
	 * Return a list of Snapshots for machine
	 */
	public function getSnapshots($args, &$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$machine = &$this->__getMachineRef($args['vm']);

		/* No snapshots? Empty array */
		if($machine->snapshotCount < 1) {
			$response['data'] = array();
		} else {

			$s = $machine->getSnapshot(null);
			$response['data'] = $this->__getSnapshot($s,true);
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
			foreach($s->children as $c) $children[] = $this->__getSnapshot($c, true);

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
				'bus' => (string)$c->bus,
				'controllerType' => (string)$c->controllerType,
				'mediumAttachments' => $this->__getMediumAttachments($m->getMediumAttachmentsOfController($c->name), $m->id)
			);
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

		$src = $this->vbox->getHardDisk($args['id']);

		$type = new MediumVariant(null,($args['type'] == 'fixed' ? 'Fixed' : 'Standard'));

		$progress = $src->cloneTo($target,$type,null);

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,'getMediums');

		$response['data'] = array('progress' => (string)$progress);

		return true;
	}

	/*
	 * Make a medium immutable
	 */
	public function mediumSetType($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$m = $this->vbox->getHardDisk($args['id']);
		$m->type = $args['type'];
		$m->releaseRemote();

		$this->cache->expire('getMediums');

		$response['data'] = array('result' => 1,'id' => $args['id']);

		return true;
	}

	/*
	 * Add existing medium
	 */
	public function mediumAdd($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		$acl = new AccessMode(null,'ReadWrite');

		switch($args['type']) {
			case 'HardDisk':
				$m = $this->vbox->openHardDisk($args['path'],$acl,false,null,false,null);
				break;
			case 'DVD':
				$m = $this->vbox->openDVDImage($args['path'],null);
				break;
			case 'Floppy':
				$m = $this->vbox->openFloppyImage($args['path'],null);
				break;

		}
		$id = null;
		if($m) {
			$id = $m->id;
			$m->releaseRemote();
		}
		$this->cache->expire('getMediums');
		return ($response['data']['result'] = 1);
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

		$type = new MediumVariant(null,($args['type'] == 'fixed' ? 'Fixed' : 'Standard'));
		$progress = $hd->createBaseStorage(intval($args['size']),$type);

		// Does an exception exist?
		try {
			if((string)$progress->errorInfo) {
				$this->errors[] = new Exception($progress->errorInfo->text);
				return false;
			}
		} catch (Exception $null) {}

		$this->__storeProgress($progress,'getMediums');

		$response['data'] = array('progress' => (string)$progress,'id' => $hd->id);

		return true;
	}

	/*
	 * Release medium from all attachments
	 */
	public function mediumRelease($args,&$response) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		switch($args['type']) {
			case 'DVD':
				$m = $this->vbox->getDVDImage($args['id']);
				break;
			case 'Floppy':
				$m = $this->vbox->getFloppyImage($args['id']);
				break;
			default:
				$m = $this->vbox->getHardDisk($args['id']);
		}
		// connected to...
		$machines = $m->machineIds;
		foreach($machines as $uuid) {

			// Find medium attachment
			try {
				$mach = $this->vbox->getMachine($uuid);
			} catch (Exception $e) {
				// TODO: error message indicating machine no longer exists?
				continue;
			}
			$attach = $mach->mediumAttachments;
			$remove = array();
			foreach($attach as $a) {
				if((string)$a->medium && $a->medium->id == $args['id']) {
					$remove[] = array(
						'controller' => $a->controller,
						'port' => $a->port,
						'device' => $a->device);
					break;
				}
			}
			// save state
			$state = (string)$mach->sessionState;
			$mach->releaseRemote();

			if(!count($remove)) continue;

			// create session
			$this->session = &$this->websessionManager->getSessionObject($this->vbox);

			// Hard disk requires machine to be stopped
			if($args['type'] == 'HardDisk' || $state == 'Closed') {
				$this->vbox->openSession($this->session, $uuid);
			} else {

				// VRDP is not supported in OSE
				$version = $this->getVersion();
				$sessionType = ($version['ose'] ? 'headless' : 'vrdp');

				$this->vbox->openExistingSession($this->session, $uuid, $sessionType, '');
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
			$this->session->close();
			$this->session->releaseRemote();

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

		switch($args['type']) {
			case 'DVD':
				$m = $this->vbox->getDVDImage($args['id']);
				break;
			case 'Floppy':
				$m = $this->vbox->getFloppyImage($args['id']);
				break;
			default:
				$m = $this->vbox->getHardDisk($args['id']);
		}

		if($args['delete'] && $this->settings['deleteOnRemove'] && $m->deviceType == 'HardDisk') {

			$progress = $m->deleteStorage();

			// Does an exception exist?
			try {
				if((string)$progress->errorInfo) {
					$this->errors[] = new Exception($progress->errorInfo->text);
					return false;
				}
			} catch (Exception $null) {}

			$this->__storeProgress($progress,'getMediums');
			$response['data']['progress'] = (string)$progress;

		} else {
			$m->close();
			$this->cache->expire('getMediums');
		}

		return($reponse['data']['result'] = 1);
	}

	/*
	 * Mount a medium on a given medium attachment (port/device)
	 */
	public function mediumMount($args,&$response,$save=false) {

		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		// Find medium attachment
		$mach = $this->__getMachineRef($args['vm']);
		$state = (string)$mach->sessionState;
		$save = ($save || $mach->getExtraData('GUI/SaveMountedAtRuntime'));
		$mach->releaseRemote();

		// create session
		$this->session = $this->websessionManager->getSessionObject($this->vbox);

		if($state == 'Closed') {
			$this->vbox->openSession($this->session, $args['vm']);
			$save = true; // force save on closed session as it is not a "run-time" change
		} else {

			// VRDP is not supported in OSE
			$version = $this->getVersion();
			$sessionType = ($version['ose'] ? 'headless' : 'vrdp');

			$this->vbox->openExistingSession($this->session, $args['vm'], $sessionType, '');
		}

		$this->session->machine->mountMedium($args['controller'],$args['port'],$args['device'],$args['medium'],true);

		if($save) $this->session->machine->saveSettings();

		$this->session->machine->releaseRemote();
		$this->session->close();
		$this->session->releaseRemote();

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

		foreach($m->children as $c) $children[] = $this->__getMedium($c);

		foreach($machines as $mid) {
			$sids = $m->getSnapshotIds($mid);
			try {
				$mid = $this->vbox->getMachine($mid);
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
						$name = $mid->getSnapshot($sids[$i])->name;
						$sids[$i] = $name;
					} catch(Exception $e) { }
				}
			}
			$attachedTo[] = array('machine'=>$mid->name,'snapshots'=>$sids);
		}

		return array(
				'id' => $m->id,
				'description' => $m->description,
				'state' => (string)$m->refreshState(),
				'location' => $m->location,
				'name' => $m->name,
				'deviceType' => (string)$m->deviceType,
				'hostDrive' => $m->hostDrive,
				'size' => (string)$m->size, /* (string) to support large disks. Bypass integer limit */
				'format' => $m->format,
				'type' => (string)$m->type,
				'parent' => (((string)$m->deviceType == 'HardDisk' && (string)$m->parent) ? $m->parent->id : null),
				'children' => $children,
				'base' => (((string)$m->deviceType == 'HardDisk' && (string)$m->base) ? $m->base->id : null),
				'readOnly' => $m->readOnly,
				'logicalSize' => $m->logicalSize,
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

		$inprogress[(string)$progress] = array(
			'session'=>(string)$this->vbox->handle,
			'progress'=>(string)$progress,
			'cancelable'=>$cancelable,
			'expire'=> $expire,
			'started'=>time());

		$this->cache->store('ProgressOperations',$inprogress);

		/* Do not destroy login session / reference to progress operation */
		$this->progressCreated = true;

		return (string)$progress;
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
			'maxVDISize' => (string)$this->vbox->systemProperties->maxVDISize,
			'networkAdapterCount' => (string)$this->vbox->systemProperties->networkAdapterCount,
			'maxBootPosition' => (string)$this->vbox->systemProperties->maxBootPosition,
			'defaultMachineFolder' => (string)$this->vbox->systemProperties->defaultMachineFolder,
			'defaultHardDiskFolder' => (string)$this->vbox->systemProperties->defaultHardDiskFolder,
			'defaultHardDiskFormat' => (string)$this->vbox->systemProperties->defaultHardDiskFormat,
			'remoteDisplayAuthLibrary' => (string)$this->vbox->systemProperties->remoteDisplayAuthLibrary,
			'defaultAudioDriver' => (string)$this->vbox->systemProperties->defaultAudioDriver,
			'maxGuestMonitors' => $this->vbox->systemProperties->maxGuestMonitors
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

		$m = $this->__getMachineRef($args['vm']);
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

		$m = $this->__getMachineRef($args['vm']);
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
	 * Return ref to vbox machine object
	 *
	 */
	public function &__getMachineRef(&$id) {
		// Connect to vboxwebsrv
		$this->__vboxwebsrvConnect();

		// Simple UUID passed
		if(!$id || strpos($id,'-')) {
			return $this->vbox->getMachine($id);
		}
		// VM name passed. Update ID after getting vm
		$res = $this->vbox->findMachine($id);
		$id = $res->id;
		return $res;
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
