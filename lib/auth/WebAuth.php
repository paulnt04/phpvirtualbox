<?php
/*
 * $Id$
 */

class phpvbAuthWebAuth {
	
	var $capabilities = array(
			'canChangePassword' => false,
			'canLogout' => false
		);
	
	var $config = array(
		'serverKey' => 'HTTP_X_WEBAUTH_USER',
		'adminUser' => null
	);
	
	function phpvbAuthWebAuth($userConfig = null) {
		if($userConfig) $this->config = array_merge($this->config,$userConfig);
	}
	
	function login($username, $password)
	{
	}
	
	function autoLoginHook()
	{
		// WebAuth passthrough
		if ( isset($_SERVER[$this->config['serverKey']]) )
		{
			$_SESSION['valid'] = true;
			$_SESSION['webauth'] = true;
			$_SESSION['user'] = $_SERVER[$this->config['serverKey']];
			$_SESSION['admin'] = ($_SESSION['user'] === $this->config['adminUser']);
			$_SESSION['authCheckHeartbeat'] = time();
			$_SESSION['uHash'] = hash('sha512', $_SERVER[$this->config['serverKey']]);
			
		}
	}
	
	function heartbeat()
	{
		if ( isset($_SERVER[$this->config['serverKey']]) )
		{
			$_SESSION['valid'] = true;
			$_SESSION['authCheckHeartbeat'] = time();
		}
	}
	
	function changePassword($old, $new)
	{
	}
	
	function logout(&$response)
	{
		$response['data']['result'] = 1;
		if ( isset($this->config['logoutURL']) )
		{
			$response['data']['url'] = $this->config['logoutURL'];
		}
	}
	
	function listUsers()
	{
		
	}
	
	function updateUser($vboxRequest, $skipExistCheck)
	{
		
	}
	
	function deleteUser($user)
	{
		
	}
}
