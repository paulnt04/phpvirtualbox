<?php
/*
 * $Id$
 */
require_once(dirname(dirname(__FILE__)).'/lib/language.php');

error_reporting(0);

header("Content-type: text/javascript; utf-8", true);

//Set no caching
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");


/*
 * Dump in JavaScript
 */
echo('var __vboxLangData = ' . json_encode(language::$trans) .";\n\nvar __vboxLangName = '".@constant('VBOXLANG')."';\n\n");

?>

var __vboxLangContext = null;
var __vboxLangContexts = [];

function trans(w) {
	if(__vboxLangContext && __vboxLangData[__vboxLangContext+'::'+w])
		return __vboxLangData[__vboxLangContext+'::'+w];

	return (__vboxLangData[w] ? __vboxLangData[w] : w);
	// debug -
	//return (__vboxLangData[w] ? __vboxLangData[w] : alert("'" + w + "' not defined in language file."));
}

function vboxSetLangContext(w) {
	__vboxLangContexts[__vboxLangContexts.length] = w;
	__vboxLangContext = w;
}

function vboxUnsetLangContext(w) {
	if(__vboxLangContexts.length > 0) {
		__vboxLangContext = __vboxLangContexts[(__vboxLangContexts.length - 1)];
		delete __vboxLangContexts[__vboxLangContexts.length];
	} else {
		__vboxLangContext = null;
	}

}
