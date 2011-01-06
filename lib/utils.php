<?php
/*
 * Common PHP utils
 *
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 *
 */

/*
 * Clean request
 */
function __vbx_stripslash(&$a) { $a = stripslashes($a); }
function clean_request() {
	$r = array_merge($_GET,$_POST);
	if(get_magic_quotes_gpc()) {array_walk_recursive($r,'__vbx_stripslash');}
	return $r;
}

/*
 * Support for PHP compiled with --disable-json
 */
if(!function_exists('json_encode')) {
/* http://au.php.net/manual/en/function.json-encode.php#82904 */
function json_encode($a=false) {
    if (is_null($a)) return 'null';
    if ($a === false) return 'false';
    if ($a === true) return 'true';
    if (is_scalar($a)) {

      if (is_float($a)) {
        // Always use "." for floats.
        return floatval(str_replace(",", ".", strval($a)));
      }

      if (is_string($a)) {
        static $jsonReplaces = array(array("\\", "/", "\n", "\t", "\r", "\b", "\f", '"'), array('\\\\', '\\/', '\\n', '\\t', '\\r', '\\b', '\\f', '\"'));
        return '"' . str_replace($jsonReplaces[0], $jsonReplaces[1], $a) . '"';
      } else return $a;

    }

    $isList = true;
    for ($i = 0, reset($a); $i < count($a); $i++, next($a)) {
      if (key($a) !== $i) {
        $isList = false;
        break;
      }
    }

    $result = array();
    if ($isList) {
      foreach ($a as $v) $result[] = json_encode($v);
      return '[' . join(',', $result) . ']';
    } else {
      foreach ($a as $k => $v) $result[] = json_encode($k).':'.json_encode($v);
      return '{' . join(',', $result) . '}';
    }

  }
}