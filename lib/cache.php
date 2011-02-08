<?php
/*
 * Simple data -> filesystem caching class
 *
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 *
 */

class cache {

	var $path = '/tmp';
	var $ext = 'dat';
	var $prefix = 'pvbx';
	var $locked = array();
	var $force_refresh = false;
	var $expire_multiplier = 1; # 1 second
	var $open = array();
	var $logfile = null;

	/* set up temp path */
	function __construct() {
		if($_ENV['TEMP'] && @is_writable($_ENV['TEMP'])) {
			$this->path = $_ENV['TEMP'];
		} else if($_ENV['TMP'] && @is_writable($_ENV['TMP'])) {
			$this->path = $_ENV['TMP'];
		// PHP >= 5.2.1
		} else if(function_exists('sys_get_temp_dir')) {
			$this->path = sys_get_temp_dir();
		}
	}

	/* Do our best to ensure all filehandles are closed
	 * and flocks removed even in an unclean shutdown */
	function __destruct() {
		$keys = array_keys($this->open);
		foreach($keys as $k) {
			@flock($this->open[$k],LOCK_UN);
			@fclose($this->open[$k]);
		}
	}

	/* get cached item */
	function get($key,$expire=60) {
		# Is file cached?
		if(!$this->cached($key,$expire)) return false;
		$d = $this->_getCachedData($key);
		if($this->logfile) $this->_log("Returning cached data for {$key}");
		return ($d === false ? $d : unserialize($d));
	}

	/* get date last modified for */
	function getDLM($key,$expire=60) {
		if(!$this->cached($key,$expire)) return time();
		return @filemtime($this->_fname($key)) || time();
	}

	/*
	 * Blocking lock on cache item
	 */
	function lock($key) {

		$fname = $this->_fname($key);

		$prelock = intval(@filemtime($fname));

		if(($fp=fopen($fname, "a")) === false) {
			if(function_exists('error_get_last')) {
				$e = error_get_last();
				throw new Exception($e['message'],vboxconnector::PHPVB_ERRNO_FATAL);
			}
			return false;
		}
			
		$this->open[$key] = &$fp;

		chmod($fname, 0600);


		flock($fp,LOCK_SH);
		flock($fp,LOCK_EX);


		/* Written while blocking ? */
		clearstatcache();
		if($prelock > 0 && @filemtime($fname) > $prelock) {
			if($this->logfile) $this->_log("{$key} prelock: {$prelock} postlock: ". filemtime($fname) ." NOT writing.");
			flock($fp,LOCK_UN);
			fclose($fp);
			unset($this->open[$key]);
			return null;
		}

		if($this->logfile) $this->_log("{$key} prelock: {$prelock} postlock: ". filemtime($fname) ." writing.");

		$this->locked[$key] = &$fp;

		return true;
	}

	/*
	 * Store locked cache item
	 */
	function store($key,&$data) {

		if(!$this->locked[$key]) return false;

		if($this->logfile) $this->_log("{$key} writing at ".time());

		ftruncate($this->locked[$key],0);
		fwrite($this->locked[$key], serialize($data));
		flock($this->locked[$key],LOCK_UN);
		$this->unlock($key);
		return $data;
	}

	/*
	 * Remove exclusive lock
	 */
	function unlock($key) {
		flock($this->locked[$key],LOCK_UN);
		fclose($this->locked[$key]);
		unset($this->open[$key]);
		unset($this->locked[$key]);
	}

	/*
	 * Determine if file is cached and has not expired
	 */
	function cached($key,$expire=60) {
		return (!$this->force_refresh && @file_exists($this->_fname($key)) && ($expire === false || (@filemtime($this->_fname($key)) > (time() - ($this->expire_multiplier * $expire)))));
	}


	/*
	 *  Expire (unlink) cached item
	 */
	function expire($key) {
		if($this->locked[$key]) $this->unlock($key);
		clearstatcache();
		if(!file_exists($this->_fname($key))) return;
		for(;file_exists($this->_fname($key)) && !@unlink($this->_fname($key));) { sleep(1); clearstatcache(); }
	}

	/*
	 * Logging used for debugging
	 */
	function _log($s) {
		if(!$this->logfile) return;
		$f = fopen($this->path.'/'.$this->logfile,'a');
		fputs($f,$s."\n");
		fclose(f);
	}

	/*
	 * Lock aware file read
	 */
	private function _getCachedData($key) {

		$fname = $this->_fname($key);

		// Pre-existing locked read
		if($this->locked[$key]) {
			@fseek($this->locked[$key],0);
			$str = @fread($this->locked[$key],@filesize($fname));
			@fseek($this->locked[$key],0);
			return $str;
		}

		$fp=fopen($fname, "r");
		if($fp === false) return false;
		$this->open[$key] = &$fp;
		flock($fp,LOCK_SH);
		// The following 2 lines handle cases where fopen (above) was called
		// on an empty file that was created by cache::lock()
		clearstatcache();
		fseek($fp,0);
		$str = @fread($fp,@filesize($fname));
		flock($fp,LOCK_UN);
		fclose($fp);
		unset($this->open[$key]);
		if(@filesize($fname) == 0) return false;
		return $str;
	}

	/* Generate file name */
	private function _fname($key) { return $this->path.'/'.$this->prefix.md5($key).'.'.$this->ext; }

}




