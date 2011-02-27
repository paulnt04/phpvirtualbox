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
if(preg_match('/[^\d]/',$_GET['port'])) {

	require_once(dirname(__FILE__).'/config.php');
	require_once(dirname(__FILE__).'/lib/vboxconnector.php');

	$vbox = new vboxconnector();
	$vbox->connect();

	$args = array('vm'=>$_GET['vm']);
	$response = array();
	$vbox->getVMDetails($args,$response);

	$_GET['port'] = $response['data']['consolePort'];

}

header("Content-type: application/x-rdp",true);
header("Content-disposition: attachment; filename=\"". $_GET['vm'] .".rdp\"",true);

echo('
auto connect:i:1
full address:s:'.$_GET['host'].($_GET['port'] ? ':'.$_GET['port'] : '').'
compression:i:1
displayconnectionbar:i:1
');
