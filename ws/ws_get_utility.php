<?php
/**
 * Returns the entire dataset for a given client
 */

# Includes
require_once("inc/error.inc.php");
require_once("inc/database.inc.php");
require_once("inc/security.inc.php");
require_once("inc/json.pdo.inc.php");

# Performs the query and returns XML or JSON
try {
	$p_postcode = $_REQUEST['postcode'];

	$sql = <<<ENDSQL
select d.label as distributor 
from distribution_area d,postcode_2011 p
where ST_Intersects(p.the_geom,d.the_geom) and p.poa_code='$p_postcode'
ENDSQL;

	//echo $sql;
	$pgconn = pgConnection();

    /*** fetch into an PDOStatement object ***/
    $recordSet = $pgconn->prepare($sql);
    $recordSet->execute();

	// Required to cater for IE
	header("Content-Type: text/html");
	echo rs2json($recordSet);
}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>