<?php
# Includes
require_once("inc/error.inc.php");
require_once("inc/database.inc.php");
require_once("inc/security.inc.php");
require_once("inc/json.pdo.inc.php");

# Mail wrapper
if (file_exists('../../../phpmailer/PHPMailerAutoload.php'))
	include('../../../phpmailer/PHPMailerAutoload.php');

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
	    	"AGL"		=> "agl",
	    	"NOTSURE"	=> "notsure",
	    	"LUMO"		=> "lumo",
	    	"CLICK"		=> "click",
	    	"EA"		=> "ea",
	    	"CITIPOWER-POWERCOR" => "citipower_powercor"
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

	// Extension for XLSX spreadsheet
	$ext = pathinfo($staged_file_path, PATHINFO_EXTENSION);
	if (strtolower($ext) == 'xlsx')
	{
		$mime_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;';
	}

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
			$first_line = strtok($file_in_a_string, "\n\r");

			// Library of smart meter data file headers
	        $line1_origin2 = 'NMI,METER SERIAL NUMBER,CON/GEN,DATE,ESTIMATED?,00:00 - 00:30,00:30 - 01:00,01:00 - 01:30,01:30 - 02:00,02:00 - 02:30,02:30 - 03:00,03:00 - 03:30,03:30 - 04:00,04:00 - 04:30,04:30 - 05:00,05:00 - 05:30,05:30 - 06:00,06:00 - 06:30,06:30 - 07:00,07:00 - 07:30,07:30 - 08:00,08:00 - 08:30,08:30 - 09:00,09:00 - 09:30,09:30 - 10:00,10:00 - 10:30,10:30 - 11:00,11:00 - 11:30,11:30 - 12:00,12:00 - 12:30,12:30 - 13:00,13:00 - 13:30,13:30 - 14:00,14:00 - 14:30,14:30 - 15:00,15:00 - 15:30,15:30 - 16:00,16:00 - 16:30,16:30 - 17:00,17:00 - 17:30,17:30 - 18:00,18:00 - 18:30,18:30 - 19:00,19:00 - 19:30,19:30 - 20:00,20:00 - 20:30,20:30 - 21:00,21:00 - 21:30,21:30 - 22:00,22:00 - 22:30,22:30 - 23:00,23:00 - 23:30,23:30 - 00:00';
	        $line1_jemena =  '"NMI","METER SERIAL NUMBER","CON/GEN","DATE","ESTIMATED?","00:00 - 00:30","00:30 - 01:00","01:00 - 01:30","01:30 - 02:00","02:00 - 02:30","02:30 - 03:00","03:00 - 03:30","03:30 - 04:00","04:00 - 04:30","04:30 - 05:00","05:00 - 05:30","05:30 - 06:00","06:00 - 06:30","06:30 - 07:00","07:00 - 07:30","07:30 - 08:00","08:00 - 08:30","08:30 - 09:00","09:00 - 09:30","09:30 - 10:00","10:00 - 10:30","10:30 - 11:00","11:00 - 11:30","11:30 - 12:00","12:00 - 12:30","12:30 - 13:00","13:00 - 13:30","13:30 - 14:00","14:00 - 14:30","14:30 - 15:00","15:00 - 15:30","15:30 - 16:00","16:00 - 16:30","16:30 - 17:00","17:00 - 17:30","17:30 - 18:00","18:00 - 18:30","18:30 - 19:00","19:00 - 19:30","19:30 - 20:00","20:00 - 20:30","20:30 - 21:00","21:00 - 21:30","21:30 - 22:00","22:00 - 22:30","22:30 - 23:00","23:00 - 23:30","23:30 - 00:00"';

			$line1_agl = '"AccountNumber","NMI","DeviceNumber","DeviceType","RegisterCode","RateTypeDescription","StartDate","EndDate","ProfileReadValue","RegisterReadValue","QualityFlag",';

			$line1_lumo = 'NMI,IntervalReadDate,MeterSerialNo,EnergyDirection,UOM,RegisterID,ControlledLoad,0:15,T1,0:30,T2,0:45,T3,1:00,T4,1:15,T5,1:30,T6,1:45,T7,2:00,T8,2:15,T9,2:30,T10,2:45,T11,3:00,T12,3:15,T13,3:30,T14,3:45,T15,4:00,T16,4:15,T17,4:30,T18,4:45,T19,5:00,T20,5:15,T21,5:30,T22,5:45,T23,6:00,T24,6:15,T25,6:30,T26,6:45,T27,7:00,T28,7:15,T29,7:30,T30,7:45,T31,8:00,T32,8:15,T33,8:30,T34,8:45,T35,9:00,T36,9:15,T37,9:30,T38,9:45,T39,10:00,T40,10:15,T41,10:30,T42,10:45,T43,11:00,T44,11:15,T45,11:30,T46,11:45,T47,12:00,T48,12:15,T49,12:30,T50,12:45,T51,13:00,T52,13:15,T53,13:30,T54,13:45,T55,14:00,T56,14:15,T57,14:30,T58,14:45,T59,15:00,T60,15:15,T61,15:30,T62,15:45,T63,16:00,T64,16:15,T65,16:30,T66,16:45,T67,17:00,T68,17:15,T69,17:30,T70,17:45,T71,18:00,T72,18:15,T73,18:30,T74,18:45,T75,19:00,T76,19:15,T77,19:30,T78,19:45,T79,20:00,T80,20:15,T81,20:30,T82,20:45,T83,21:00,T84,21:15,T85,21:30,T86,21:45,T87,22:00,T88,22:15,T89,22:30,T90,22:45,T91,23:00,T92,23:15,T93,23:30,T94,23:45,T95,0:00,T96';

			//$line1_click = '200,6102514557,E1,E1,E1,N1,A8125048,KWH,30,20140331,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,';
			$line1_click = '200,';

			$line1_ea = 'Name,';

			// Issue with how the EA file was encoded - need to remove Byte Mark Order
			// Source: http://stackoverflow.com/questions/4057742/how-to-remove-efbbbf-in-php-string/4057875#4057875
			if (strcmp(addcslashes($first_line,"\0..\37!@\177..\377"),$first_line) !== 0)
			{
				logmsg('File seems to have an UTF BOM - attempting conversion ...');
				$enc = mb_detect_encoding($first_line);
				$first_line = substr(mb_convert_encoding($first_line, "ASCII", $enc),1,strlen($first_line));				
			}

			//logmsg('1st line:'.addcslashes($first_line,"\0..\37!@\177..\377").','.strlen($first_line));

			switch (true) {
				case (strcmp($first_line,$line1_origin2) == 0):
					$method = "ORIGIN2";
					break;
				case (strcmp($first_line,$line1_jemena) == 0):
					$method = "ORIGIN2";
					break;
				case (strcmp($first_line,$line1_agl) == 0):
					$method = "AGL";
					break;
				case (strcmp($first_line,$line1_lumo) == 0):
					$method = "LUMO";
					break;
				case (strcmp(substr($first_line,0,4),$line1_click) == 0):
					$method = "CLICK";
					break;
				case (strcmp(substr($first_line,0,5),$line1_ea) == 0):
					$method = "EA";
					break;
				case (substr_count($first_line, ',') == 11):
					$method = "CITIPOWER-POWERCOR";
					break;					
				default:
					$message = "Un-recognised text file!";
					mySendMail('File upload unsucessful with first line: '.$first_line,$message,$staged_file_path);
					break;
			}
	        break;
	    default:
	        $message = "Un-recognised MIME type: ".$mt;
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

    	case "ORIGIN2":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading Origin CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/origin-csv.sh '.$staged_file_path);

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char((case when (length(date)=7) then to_date('0'||date,'DD/MM/YY') when (length(date)=8) then to_date(date,'DD/MM/YY') when (length(dt)=9) then to_date('0'||dt,'DD/MM/YYYY') when (length(date)=10) then to_date(date,'DD/MM/YYYY') else to_date(date,'DD/Mon/YYYY') end),'DD/MM/YYYY') as startdate from staging_origin2 where id=(select min(a.id) from staging_origin2 a);");

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
			$date_start = singleValueDBQuery("select to_char(to_date(day,'DD/MM/YY'),'DD/MM/YYYY') as startdate from staging_notsure where id=(select min(a.id) from staging_notsure a)");

    		break;

    	case "LUMO":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading Lumo CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/lumo.sh '.$staged_file_path);

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date(intervalreaddate,'DD/Mon/YY'),'DD/MM/YYYY') as startdate from staging_lumo where id=(select min(a.id) from staging_lumo a);");
    		break;

    	case "CLICK":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading CLICK CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/click.sh '.$staged_file_path.' '.realpath('../staging'));

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date(date,'YYYYMMDD'),'DD/MM/YYYY') as startdate from staging_click where id=(select min(a.id) from staging_click a);");

    		break;

    	case "EA":
		    //  a. Into staging
		    $pb->advance(0.3,'Loading EA CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/ea.sh '.$staged_file_path.' '.realpath('../staging'));

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select to_char(to_date(date,'YYYY-MM-DD'),'DD/MM/YYYY') as startdate from staging_ea where id=(select min(a.id) from staging_ea a);");

    		break;


    	case "CITIPOWER-POWERCOR":
 		    //  a. Into staging
		    $pb->advance(0.3,'Loading CITIPOWER/POWERCOR CSV data into database...');
    		$staging_script = shell_exec(realpath('../heatmap').'/load/citipower-powercor.sh '.$staged_file_path.' '.realpath('../staging'));

		    // Properly formatted start date
		    $pb->advance(0.5,'Formatting the start date...');
			$date_start = singleValueDBQuery("select case length(date) when 9 then '0'||date else date end as startdate from staging_citipower_powercor where id=(select min(a.id) from staging_citipower_powercor a);");

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
