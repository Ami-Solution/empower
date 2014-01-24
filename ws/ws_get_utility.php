<?php
/**
 * Returns the entire dataset for a given client
 */

# Includes
require_once("inc/error.inc.php");
require_once("inc/security.inc.php");
require_once("inc/json.pdo.inc.php");

# Performs the query and returns XML or JSON
try {
	$p_postcode = $_REQUEST['postcode'];
	$url = 'https://mpp.switchon.vic.gov.au/create/relevant-offers/ajax/postcode/'.$p_postcode;
	$ret = file_get_contents($url);

	// Required to cater for IE
	header("Content-Type: application/json");
	echo $ret;
}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>