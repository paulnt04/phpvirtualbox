<?php
/*
 * $Id$
 */
require_once(dirname(dirname(__FILE__)).'/lib/language.php');

error_reporting(0);

Header("Content-type: text/javascript; utf-8", true);


/*
 * Dump in JavaScript
 */
echo('var langData = ' . json_encode(language::$trans) .";\n\n");
?>

var __vboxLangContext = null;
var __vboxLangContexts = [];

function trans(w) {
	if(__vboxLangContext && langData[__vboxLangContext+'::'+w])
		return langData[__vboxLangContext+'::'+w];

	return (langData[w] ? langData[w] : w);
	// debug -
	//return (langData[w] ? langData[w] : alert("'" + w + "' not defined in language file."));
}

function setLangContext(w) {
	__vboxLangContexts[__vboxLangContexts.length] = w;
	__vboxLangContext = w;
}

function unsetLangContext(w) {
	if(__vboxLangContexts.length > 0) {
		__vboxLangContext = __vboxLangContexts[(__vboxLangContexts.length - 1)];
		delete __vboxLangContexts[__vboxLangContexts.length];
	} else {
		__vboxLangContext = null;
	}

}
