<?php
# Includes
require_once("inc/error.inc.php");
require_once("inc/database.inc.php");
require_once("inc/security.inc.php");
require_once("inc/json.pdo.inc.php");

# Mail wrapper
#require_once('../../../phpmailer/PHPMailerAutoload.php');

# For communication of updates to the calling script
require_once 'AJAX_PROGRESS.class.php';

date_default_timezone_set('Australia/Melbourne');

function logmsg($msg) 
{ 
    $date = date('d.m.Y h:i:s'); 
    $log = $date." | ".$msg."\n"; 
    error_log($log, 3, '/var/log/php/error.log'); 
} 

$pb = new AJAX_PROGRESS();

// Opening up a connection to the database
$pgconn = pgConnection();

// Convenience function for SQL queries that return only 1 value 
function singleValueDBQuery ($sql) {
	global $pgconn;
    $recordSet = $pgconn->prepare($sql);
    $recordSet->execute();
    while ($row  = $recordSet->fetch())
    {
        $first_val = $row[0];
    }
    return $first_val;
}

// Convenience function for mail
function mySendMail ($sub,$bod,$att){

	logmsg("Mail sent: ".$sub." (".$bod.") for file ".$att);

	# Mailer library
	$mail = new PHPMailer;

	// Other email parameters
	$mail->From = 'php@empowerme.org.au';
	$mail->FromName = 'empower.me';
	$mail->addAddress('admin@empowerme.org.au');
	$mail->isHTML(true);                                  // Set email format to HTML

	$mail->Subject = $sub;
	$mail->Body    = $bod;
	$mail->addAttachment($att);	

	if(!$mail->send()) {
	   echo 'Message could not be sent.';
	   echo 'Mailer Error: ' . $mail->ErrorInfo;
	   exit;
	}
}


function executeParamLoadQuery ($method,$client_id,$date_start) {
	global $pb,$pgconn;
    if ($date_start && $client_id)
    {
	    $pb->advance(0.7,'Loading staged data to main table...');
	    // Correspondance method to query file suffix
	    $corr = array(
	    	"ORIGIN1" 	=> "origin1",
	    	"ORIGIN2" 	=> "origin2",
	    	"JEMENA"	=> "jemena",
	    	"AGL"		=> "agl",
	    	"NOTSURE"	=> "notsure"
	    );

	    // Getting the parameterised query
	    $query_in_a_string = file_get_contents(realpath('../heatmap').'/load/stc-'.$corr[$method].'.sql');
	    // Variable substitution
		$vars = array(
		  '{$v_client_id}'	=> $client_id,
		  '{$v_date_start}'	=> $date_start
		);
		$sql = strtr($query_in_a_string, $vars);
		logmsg($sql);

		// Preparing and executing the query
	    $recordSet = $pgconn->prepare($sql);
	    $recordSet->execute();
    }	
}

// This script orchestrate the processing of a smart meter data file
try {
	$p_url = $_REQUEST['url'];
	logmsg("Processing file:".$p_url);
    // 1) file detection
    //  a. Type (PDF/txt)

    // File upload is supposed to have taken 10% = 0.1 of the process
    // hence we start the file analysis at 15% = 0.15
    $pb->advance(0.15,'Detecting file type...');
	$file_in_a_string = file_get_contents($p_url);
	$file_info = new finfo(FILEINFO_MIME);
	$mime_type = $file_info->buffer($file_in_a_string);  // e.g. gives "image/jpeg"
	// TODO: we should probably use stricter URL detection to only consider files we trust (i.e. from our server or other trusted location)

	// Writing the file to staging area. Do we loose anything in this process?
	$staged_file_path = realpath('../staging').'/'.basename($p_url);
	file_put_contents($staged_file_path , $file_in_a_string);

	// First part of mime-type is interesting, second part is character set
	$mt_arr = explode(';',$mime_type);
	$mt = $mt_arr[0];

	// Purpose of the switch: determine the method for loading the data in the DB
	$method = "UNKNOWN";

    //  b. Format (heuristics to recognise distributor / retailer)
    switch($mt) {
	    case "application/pdf":
	        $method = "ORIGIN1";
	        break;
	    case "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet":
	    	$method = "NOTSURE";
	    	break;
	    case "text/plain":
	    	// Further investigation needed as there are many providers of plan text files
	        // Sniffing the first line of the file
			$first_line = strtok($file_in_a_string, "\r\n");

			// Library of smart meter data file headers
	        $line1_origin2 = 'NMI,METER SERIAL NUMBER,CON/GEN,DATE,ESTIMATED?,00:00 - 00:30,00:30 - 01:00,01:00 - 01:30,01:30 - 02:00,02:00 - 02:30,02:30 - 03:00,03:00 - 03:30,03:30 - 04:00,04:00 - 04:30,04:30 - 05:00,05:00 - 05:30,05:30 - 06:00,06:00 - 06:30,06:30 - 07:00,07:00 - 07:30,07:30 - 08:00,08:00 - 08:30,08:30 - 09:00,09:00 - 09:30,09:30 - 10:00,10:00 - 10:30,10:30 - 11:00,11:00 - 11:30,11:30 - 12:00,12:00 - 12:30,12:30 - 13:00,13:00 - 13:30,13:30 - 14:00,14:00 - 14:30,14:30 - 15:00,15:00 - 15:30,15:30 - 16:00,16:00 - 16:30,16:30 - 17:00,17:00 - 17:30,17:30 - 18:00,18:00 - 18:30,18:30 - 19:00,19:00 - 19:30,19:30 - 20:00,20:00 - 20:30,20:30 - 21:00,21:00 - 21:30,21:30 - 22:00,22:00 - 22:30,22:30 - 23:00,23:00 - 23:30,23:30 - 00:00';
	        $line1_jemena =  '"NMI","METER SERIAL NUMBER","CON/GEN","DATE","ESTIMATED?","00:00 - 00:30","00:30 - 01:00","01:00 - 01:30","01:30 - 02:00","02:00 - 02:30","02:30 - 03:00","03:00 - 03:30","03:30 - 04:00","04:00 - 04:30","04:30 - 05:00","05:00 - 05:30","05:30 - 06:00","06:00 - 06:30","06:30 - 07:00","07:00 - 07:30","07:30 - 08:00","08:00 - 08:30","08:30 - 09:00","09:00 - 09:30","09:30 - 10:00","10:00 - 10:30","10:30 - 11:00","11:00 - 11:30","11:30 - 12:00","12:00 - 12:30","12:30 - 13:00","13:00 - 13:30","13:30 - 14:00","14:00 - 14:30","14:30 - 15:00","15:00 - 15:30","15:30 - 16:00","16:00 - 16:30","16:30 - 17:00","17:00 - 17:30","17:30 - 18:00","18:00 - 18:30","18:30 - 19:00","19:00 - 19:30","19:30 - 20:00","20:00 - 20:30","20:30 - 21:00","21:00 - 21:30","21:30 - 22:00","22:00 - 22:30","22:30 - 23:00","23:00 - 23:30","23:30 - 00:00"';

			$line1_agl = '"AccountNumber","NMI","DeviceNumber","DeviceType","RegisterCode","RateTypeDescription","StartDate","EndDate","ProfileReadValue","RegisterReadValue","QualityFlag",';

			switch($first_line)
			{
				case $line1_origin2:
					$method = "ORIGIN2";
					break;
				case $line1_jemena:
					$method = "JEMENA";
					break;
				case $line1_agl:
					$method = "AGL";
					break;
				default:
					$message = "Un-recognised text file!";
					mySendMail('File upload unsucessful',$message,$staged_file_path);
					break;
			}
	        break;
	    default:
	        $message = "Un-recognised MIME type!";
	        mySendMail('File upload unsucessful',$message,$staged_file_path);
	        break;
    }

	// Client ID - obtaining one from a sequence in the database
    $pb->advance(0.2,'Obtaining an ID from database...('.$method.')');
	$client_id = singleValueDBQuery("select nextval('client_id_seq')");

    // 2) upload in our DB
    switch($method){
    	case "ORIGIN1":
		    //  a. Into staging
		    $pb->advance(0.3,'Extracting data from Origin PDF to database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/origin.sh '.$staged_file_path.' '.realpath('../staging'));

    		// Extracting the penultimate line of the previous command output, it contains the start date
			$lines=explode("\n", $staging_script);
			$date_start = $lines[count($lines)-2];

		    // Properly formatted date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date('".$date_start."','DD-Mon-YYYY'),'DD/MM/YYYY')");

    		break;

    	case "JEMENA":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading Jemena CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/jemena.sh '.$staged_file_path);

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date(date,'DD-MM-YY'),'DD/MM/YYYY') as startdate from staging_jemena where id=(select min(a.id) from staging_jemena a);");

    		break;

    	case "ORIGIN2":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading Origin CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/origin-csv.sh '.$staged_file_path);

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date(date,'DD-MM-YY'),'DD/MM/YYYY') as startdate from staging_origin2 where id=(select min(a.id) from staging_origin2 a);");

    		break;

    	case "AGL":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading AGL CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/agl.sh '.$staged_file_path.' '.realpath('../staging'));

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select substring(startdate,1,position(' ' in startdate)-1) as startdate from staging_agl1 where id=(select min(a.id) from staging_agl1 a)");

    		break;
    	case "NOTSURE":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading XLSX data into database...');
		    $cmd = realpath('../heatmap').'/load/notsure.sh '.$staged_file_path.' '.realpath('../staging');
    		$staging_script = shell_exec($cmd);

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date(day,'MM/DD/YY'),'DD/MM/YYYY') as startdate from staging_notsure where id=(select min(a.id) from staging_notsure a)");

    		break;

    	case "LUMO":
    		break;
    	default:
    		break;
	}

    //  b. From staging to overall consumption table
    executeParamLoadQuery($method,$client_id,$date_start);


    //$pb->advance(0.8,'Cleaning up...');
    // 3) cleanup procedure (do not implement right now, to be able to see+fix errors)
    //  a. Remove uploaded file
    //  b. Remove staged data

    //$pb->advance(0.9,'Finalising...');
    // 4) User information
    //  a. Upload complete

	//this destroys the progress object, and ends the output to the client
	//it also emits a special call to the client-side function, with a progress value
	//of -1, which tells the client that the task is completed
    $pb->advance(1,'Finished');
    $pb->advance(2,'{"client_id":"'.$client_id.'","date_start":"'.$date_start.'"}');

    //  b. Link to the newly uploaded data
	// Will have to find a way to transfer the data that's not sending back a JSON

    // Nullifying the variable will send an end signal to the client
	$pb=null;

}
catch (Exception $e) {
	$message = $e->getMessage();
    mySendMail('File upload unsucessful',$message,$staged_file_path);
}

?>
