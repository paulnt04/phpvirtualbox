<?php
/*
 * Simple RDP connection file generator
 *
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 *
 */

/*
 * Check for port range or list of ports
 */
if(preg_match('/[^\d]/',$_REQUEST['port'])) {

	require_once(dirname(__FILE__).'/config.php');
	require_once(dirname(__FILE__).'/lib/vboxconnector.php');

	$vbox = new vboxconnector();
	$vbox->connect();

	$args = array('vm'=>$_REQUEST['vm']);
	$response = array();
	$vbox->getVMDetails($args,$response);

	$_REQUEST['port'] = $response['data']['consolePort'];

}

header("Content-type: application/x-rdp",true);
header("Content-disposition: attachment; filename=\"". $_REQUEST['vm'] .".rdp\"",true);

echo('
auto connect:i:1
full address:s:'.$_REQUEST['host'].($_REQUEST['port'] ? ':'.$_REQUEST['port'] : '').'
compression:i:1
displayconnectionbar:i:1
');
