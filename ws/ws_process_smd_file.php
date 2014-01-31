<?php
/**
 * Returns the entire dataset for a given client
 */

# Includes
require_once("inc/error.inc.php");
require_once("inc/database.inc.php");
require_once("inc/security.inc.php");
require_once("inc/json.pdo.inc.php");

# For communication of updates to the calling script
require_once 'AJAX_PROGRESS.class.php';
$pb=new AJAX_PROGRESS();

# Performs the query and returns XML or JSON
try {
	$p_url = $_REQUEST['url'];

	// This script orchestrate the processing of a smart meter data file
    // 1) file detection
    //  a. Type (PDF/txt)
    $pb->advance(0.15,'Detecting file type...',"Step 1");

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
	        $message = "Un-recognised MIME type!";
	        break;
    }

    // 2) upload in our DB
    $pb->advance(0.2,'Uploading to DB...',"Step 2");

    switch($method){
    	case "ORIGIN1":
		    //  a. Into staging
    		//echo "Loading ".$staged_file_path." ...\n";
		    $pb->advance(0.3,'Extracting info from PDF to database...',"Step 3");
    		$staging_script = shell_exec(realpath('../heatmap').'/load/origin.sh '.$staged_file_path.' '.realpath('../staging'));
    		//echo "Returned output from staging script:".$staging_script."\n";

    		// Extracting the penultimate line, it contains the start date
			$lines=explode("\n", $staging_script);
			$date_start = $lines[count($lines)-2];
			//echo "Extracted date start:".$date_start."\n";

    		// Client ID
		    //echo "Obtaining a client ID from the DB ... \n";
		    $pb->advance(0.4,'Obtaining an ID...',"Step 4");
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
		    $pb->advance(0.5,'Formatting the start date...',"Step 5");
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
			    $pb->advance(0.6,'Loading staged data to main table...',"Step 6");
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

			    // Setting the message:
			    $message = "Successfully upload smart meter data file";
		    }
    		break;
    	case "ORIGIN2":
    		break;
    	case "AGL":
    		break;
    	case "LUMO":
    		break;
    	default:
    		$message = "Unknown loading method";
    }

    $pb->advance(0.8,'Cleaning up...',"Step 7");
    // 3) cleanup procedure (do not implement right now, to be able to see+fix errors)
    //  a. Remove uploaded file
    //  b. Remove staged data

    $pb->advance(0.9,'Finalising...',"Step 8");
    // 4) User information
    //  a. Upload complete

	//this destroys the progress object, and ends the output to the client
	//it also emits a special call to the client-side function, with a progress value
	//of -1, which tells the client that the task is completed
    $pb->advance(1,'Finished',"Step 9");
	$pb=null;

    //  b. Link to the newly uploaded data
	// Required to cater for IE
	header("Content-Type: text/html");
	echo '{"message":"'.$message.'","client_id":'.$client_id.',"start_date":"'.$date_start.'","method":"'.$method.'"}';
}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>