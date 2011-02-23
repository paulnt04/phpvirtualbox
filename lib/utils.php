<?php
/*
 * Common PHP utils
 *
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 *
 */


/*
 * Initialize session
 */
require_once(dirname(__FILE__).'/config.php');
function session_init() {
	
	$settings = new phpVBoxConfigClass();

	// No session support? No login...
	if(!function_exists('session_start') || $settings->noAuth) {
		global $_SESSION;
		$_SESSION['valid'] = true;
		return;
	}
	ini_set('session.use_trans_sid', 0);
	ini_set('session.use_only_cookies', 1);
	
	// Session path
	if($settings->sessionSavePath) {
		session_save_path($settings->sessionSavePath);
	}
	// Session id calculation
	if(intval($settings->sessionSecurityLevel) < 0) $settings->sessionSecurityLevel = 2;
	$remote = explode('.',$_SERVER["REMOTE_ADDR"]);
	$levels = array($_SERVER["HTTP_USER_AGENT"],$remote[0],$remote[1],$remote[2],$remote[3]);
	for($i = 0; $i < intval($settings->sessionSecurityLevel) && $i < count($levels); $i++) $sid .= $levels[$i];
	session_id(md5($sid));
	
	session_name(($settings->session_name ? $settings->session_name : md5('phpvbx'.$_SERVER['DOCUMENT_ROOT'])));
	session_start();
}

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
function json_encode($a=false,$force_string=false) {
    if (is_null($a)) return 'null';
    if ($a === false) return 'false';
    if ($a === true) return 'true';
    if (is_scalar($a)) {

      if (is_float($a)) {
        // Always use "." for floats.
        return floatval(str_replace(",", ".", strval($a)));
      }

      if (is_string($a) || $force_string) {
        static $jsonReplaces = array(array("\\", "/", "\n", "\t", "\r", "\b", "\f", '"'), array('\\\\', '\\/', '\\n', '\\t', '\\r', '\\b', '\\f', '\"'));
        return '"' . json_encodeUnicodeString(str_replace($jsonReplaces[0], $jsonReplaces[1], $a)) . '"';
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
      foreach ($a as $k => $v) $result[] = json_encode($k,true).':'.json_encode($v);
      return '{' . join(',', $result) . '}';
    }

  }

    function json_encodeUnicodeString($value)
    {
        $strlen_var = strlen($value);
        $ascii = "";

        /**
         * Iterate over every character in the string,
         * escaping with a slash or encoding to UTF-8 where necessary
         */
        for($i = 0; $i < $strlen_var; $i++) {
            $ord_var_c = ord($value[$i]);

            switch (true) {
                case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
                    // characters U-00000000 - U-0000007F (same as ASCII)
                    $ascii .= $value[$i];
                    break;

                case (($ord_var_c & 0xE0) == 0xC0):
                    // characters U-00000080 - U-000007FF, mask 110XXXXX
                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                    $char = pack('C*', $ord_var_c, ord($value[$i + 1]));
                    $i += 1;
                    $utf16 = json_utf82utf16($char);
                    $ascii .= sprintf('\u%04s', bin2hex($utf16));
                    break;

                case (($ord_var_c & 0xF0) == 0xE0):
                    // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                    $char = pack('C*', $ord_var_c,
                                 ord($value[$i + 1]),
                                 ord($value[$i + 2]));
                    $i += 2;
                    $utf16 = json_utf82utf16($char);
                    $ascii .= sprintf('\u%04s', bin2hex($utf16));
                    break;

                case (($ord_var_c & 0xF8) == 0xF0):
                    // characters U-00010000 - U-001FFFFF, mask 11110XXX
                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                    $char = pack('C*', $ord_var_c,
                                 ord($value[$i + 1]),
                                 ord($value[$i + 2]),
                                 ord($value[$i + 3]));
                    $i += 3;
                    $utf16 = json_utf82utf16($char);
                    $ascii .= sprintf('\u%04s', bin2hex($utf16));
                    break;

                case (($ord_var_c & 0xFC) == 0xF8):
                    // characters U-00200000 - U-03FFFFFF, mask 111110XX
                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                    $char = pack('C*', $ord_var_c,
                                 ord($value[$i + 1]),
                                 ord($value[$i + 2]),
                                 ord($value[$i + 3]),
                                 ord($value[$i + 4]));
                    $i += 4;
                    $utf16 = json_utf82utf16($char);
                    $ascii .= sprintf('\u%04s', bin2hex($utf16));
                    break;

                case (($ord_var_c & 0xFE) == 0xFC):
                    // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                    // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                    $char = pack('C*', $ord_var_c,
                                 ord($value[$i + 1]),
                                 ord($value[$i + 2]),
                                 ord($value[$i + 3]),
                                 ord($value[$i + 4]),
                                 ord($value[$i + 5]));
                    $i += 5;
                    $utf16 = json_utf82utf16($char);
                    $ascii .= sprintf('\u%04s', bin2hex($utf16));
                    break;
            }
        }

        return $ascii;
     }
    /**
     * Convert a string from one UTF-8 char to one UTF-16 char.
     *
     * Normally should be handled by mb_convert_encoding, but
     * provides a slower PHP-only method for installations
     * that lack the multibye string extension.
     *
     * This method is from the Solar Framework by Paul M. Jones
     *
     * @link   http://solarphp.com
     * @param string $utf8 UTF-8 character
     * @return string UTF-16 character
     */
    function json_utf82utf16($utf8)
    {
        // Check for mb extension otherwise do by hand.
        if( function_exists('mb_convert_encoding') ) {
            return mb_convert_encoding($utf8, 'UTF-16', 'UTF-8');
        }

        switch (strlen($utf8)) {
            case 1:
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return $utf8;

            case 2:
                // return a UTF-16 character from a 2-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x07 & (ord($utf8{0}) >> 2))
                     . chr((0xC0 & (ord($utf8{0}) << 6))
                         | (0x3F & ord($utf8{1})));

            case 3:
                // return a UTF-16 character from a 3-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr((0xF0 & (ord($utf8{0}) << 4))
                         | (0x0F & (ord($utf8{1}) >> 2)))
                     . chr((0xC0 & (ord($utf8{1}) << 6))
                         | (0x7F & ord($utf8{2})));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

}
