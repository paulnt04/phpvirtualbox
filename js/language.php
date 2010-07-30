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

function trans(w) {
	return (langData[w] ? langData[w] : w);
	// debug -
	//return (langData[w] ? langData[w] : alert("'" + w + "' not defined in language file."));
}
