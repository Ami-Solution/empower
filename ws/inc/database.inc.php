<?php
/**
 * Database Include
 * Handles all database functions required by the REST web services.
 */

/**
 * Return postgres data connection
 * @return 		object		- adodb data connection
 */
function pgConnection() {
	try {
		// Connect to the database passed in the config variable
		$conn = new PDO ("pgsql:host=localhost;dbname=ee;port=5432","ee_app","ee_app", array(PDO::ATTR_PERSISTENT => true));
	    return $conn;
	}
	catch (Exception $e) {
		trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
	}
}

?>
