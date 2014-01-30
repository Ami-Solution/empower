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
	$p_url = $_REQUEST['url'];

	// This script orchestrate the processing of a smart meter data file
    // 1) file detection
    //  a. Type (PDF/txt)
	$file_in_a_string = file_get_contents($p_url);
	$file_info = new finfo(FILEINFO_MIME);  // object oriented approach!
	$mime_type = $file_info->buffer($file_in_a_string);  // e.g. gives "image/jpeg"
	# Note: we should probably use stricter URL detection to only consider files we trust (i.e. from our server or other trusted location)

	// Writing the file to staging area. Do we loose anything in this process?
	$staged_file_path = realpath('../staging').'/'.basename($p_url);
	file_put_contents($staged_file_path , $file_in_a_string);

	# First part of mime-type is interesting, second part is character set
	$mt = explode(";",$mime_type)[0];

	// Purpose of the switch is to determine the method for loading the data in the DB
	$method = "UNKNOWN";
    //  b. Format (heuristics to recognise distributor or retailer)
    switch($mt) {
	   case "application/pdf":
	        //echo "PDF detected - processing as Origin file ..";
	        $method = "ORIGIN1";
	        break;
	    case "text/plain":
	        //echo "Plain text - needs further analysis to determine source ..";
	        $method = "";
	        break;
	    default:
	        echo "Un-recognised MIME type!";
	        break;
    }

    // 2) upload in our DB

    switch($method){
    	case "ORIGIN1":
		    //  a. Into staging
    		//echo "Loading ".$staged_file_path." ...\n";
    		$staging_script = shell_exec(realpath('../heatmap').'/load/origin.sh '.$staged_file_path);
    		//echo "Returned output from staging script:".$staging_script."\n";

    		// Extracting the last line, it contains the start date
			$lines=explode("\n", $staging_script);
			$date_start = $lines[count($lines)-2];
			//echo "Extracted date start:".$date_start."\n";

    		// Client ID
		    //echo "Obtaining a client ID from the DB ... \n";
			$sql = "select nextval('client_id_seq')";
			$pgconn = pgConnection();
		    $recordSet = $pgconn->prepare($sql);
		    $recordSet->execute();
		    while ($row  = $recordSet->fetch())
		    {
		        $client_id = $row[0];
		    }

		    // Properly formatted date
		    //echo "Obtaining a client ID from the DB ... \n";
			$sql = "select to_char(to_date('".$date_start."','DD-Mon-YYYY'),'DD/MM/YYYY')";
			$pgconn = pgConnection();
		    $recordSet = $pgconn->prepare($sql);
		    $recordSet->execute();
		    while ($row  = $recordSet->fetch())
		    {
		        $date_start = $row[0];
		    }

		    //  b. From staging to overall consumption table
		    if ($date_start && $client_id)
		    {
			    //echo "Running the parameterised query to populate the consumption table ... \n";
			    $query_in_a_string = file_get_contents(realpath('../heatmap').'/load/stc-origin1.sql');

			    // Variable substitution
				$vars = array(
				  '{$v_client_id}'	=> $client_id,
				  '{$v_date_start}'	=> $date_start
				);
				$sql = strtr($query_in_a_string, $vars);
				//echo $sql;

				// Query in a string
				$pgconn = pgConnection();
			    $recordSet = $pgconn->prepare($sql);
			    $recordSet->execute();
		    }
    		break;
    	case "ORIGIN2":
    		break;
    	case "AGL":
    		break;
    	case "LUMO":
    		break;
    	default:
    		echo "Unknown loading method";
    }

    // 3) cleanup procedure
    //  a. Remove uploaded file
    //  b. Remove staged data
    // 4) User information
    //  a. Upload complete
    //  b. Link to the newly uploaded data

	// Required to cater for IE
	header("Content-Type: text/html");
	echo '{"client_id":'.$client_id.',"start_date":"'.$date_start.'","method":"'.$method.'"}';
}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>