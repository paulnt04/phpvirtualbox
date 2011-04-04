<?php
/*
 * Built-in authentication. Uses vbox->get / set ExtraData
 * $Id$
 * 
 */
class phpvbAuthBuiltin {
	
	var $capabilities = array(
			'canChangePassword' => true,
			'canModifyUsers' => true,
			'canLogout' => true
		);
	
	function login($username, $password)
	{
		global $_SESSION;
		
		$vbox = new vboxconnector(true);
		$vbox->skipSessionCheck = true;
		$vbox->connect();
		$p = $vbox->vbox->getExtraData('phpvb/users/'.$username.'/pass');
		
		// Check for initial login
		if($username == 'admin' && !$p && !$vbox->vbox->getExtraData('phpvb/usersSetup')) {
			$vbox->vbox->setExtraData('phpvb/usersSetup','1');
			$vbox->vbox->setExtraData('phpvb/users/'.$username.'/pass', hash('sha512', 'admin'));
			$vbox->vbox->setExtraData('phpvb/users/'.$username.'/admin', '1');
			$p = hash('sha512', 'admin');
		}
		
		if($p == hash('sha512', $password)) {
			$_SESSION['valid'] = true;
			$_SESSION['user'] = $username;
			$_SESSION['admin'] = intval($vbox->vbox->getExtraData('phpvb/users/'.$username.'/admin'));
			$_SESSION['authCheckHeartbeat'] = time();
			$_SESSION['uHash'] = $p;
		}
	}
	
	function changePassword($old, $new, &$response)
	{
		global $_SESSION;
		
		// Use main / auth server
		$vbox = new vboxconnector(true);
		$vbox->connect();
		$p = $vbox->vbox->getExtraData('phpvb/users/'.$_SESSION['user'].'/pass');
		
		if($p == hash('sha512', $old)) {
			$np = hash('sha512', $new);
			$vbox->vbox->setExtraData('phpvb/users/'.$_SESSION['user'].'/pass', $np);
			$response['data']['result'] = 1;
			$_SESSION['uHash'] = $np;
		}
	}
	
	function heartbeat($vbox)
	{
		global $_SESSION;
		
		// Check to see if we only have 1 server or are already connected
		// to the authentication master server
		if($vbox->settings['authMaster'] || count($vbox->settings['servers']) == 1) {
			$vbcheck = &$vbox;
		} else {
			$vbcheck = new vboxconnector(true);
		}
		
		$vbcheck->connect();
		$p = $vbcheck->vbox->getExtraData('phpvb/users/'.$_SESSION['user'].'/pass');
		if(!$p || $_SESSION['uHash'] != $p) {
			session_destroy();
			unset($_SESSION['valid']);
		} else {
			$_SESSION['admin'] = intval($vbcheck->vbox->getExtraData('phpvb/users/'.$_SESSION['user'].'/admin'));
			$_SESSION['authCheckHeartbeat'] = time();
		}
		
		if(!$_SESSION['valid'])
			throw new Exception(trans('Not logged in.'), vboxconnector::PHPVB_ERRNO_FATAL);
	}
	
	function logout(&$response)
	{
		session_destroy();
		$response['data']['result'] = 1;
	}
	
	function listUsers()
	{
		$response = array();
		
		// Use main / auth server
		$vbox = new vboxconnector(true);
		$vbox->connect();
		
		$keys = $vbox->vbox->getExtraDataKeys();
		foreach($keys as $k) {
			if(strpos($k,'phpvb/users/') === 0) {
				$user = substr($k,12,strpos($k,'/',13)-12);
				if(isset($response[$user])) continue;
				$admin = intval($vbox->vbox->getExtraData('phpvb/users/'.$user.'/admin'));
				$response[$user] = array('username'=>$user,'admin'=>$admin);
			}
		}
		return $response;
	}
	
	function updateUser($vboxRequest, $skipExistCheck)
	{
		global $_SESSION;
		
		// Must be an admin
		if(!$_SESSION['admin']) break;

		// Use main / auth server
		$vbox = new vboxconnector(true);
		$vbox->connect();
		
		// See if it exists
		if(!$skipExistCheck && $vbox->vbox->getExtraData('phpvb/users/'.$vboxRequest['u'].'/pass'))
			break;
		
		if($vboxRequest['p'])
			$vbox->vbox->setExtraData('phpvb/users/'.$vboxRequest['u'].'/pass', hash('sha512', $vboxRequest['p']));
			
		$vbox->vbox->setExtraData('phpvb/users/'.$vboxRequest['u'].'/admin', ($vboxRequest['a'] ? '1' : '0'));
	}
	
	function deleteUser($user)
	{
		// Use main / auth server
		$vbox = new vboxconnector(true);
		$vbox->connect();
		
		$vbox->vbox->setExtraData('phpvb/users/'.$user.'/pass','');
		$vbox->vbox->setExtraData('phpvb/users/'.$user.'/admin','');
		$vbox->vbox->setExtraData('phpvb/users/'.$user,'');
	}
}
