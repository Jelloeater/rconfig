<?php
/*
 * Why i am creating a new downloadNowScript when a lot of the code is already in the showCmdScript already?
 * well, most of the code is similar for sure, but the functionality between the required features is different, where
 * the showCmdScript is based of the Task ID (tid) and this script is based off the routers own DB ID. More than
 * that, I will build JS call backs into the script so that the GUI is updated with the device output. I will also remove the reporting
 * elements as they appear in the showCmdScript script.
*/

// requires - full path required
require("/home/rconfig/classes/db.class.php");
require("/home/rconfig/classes/ADLog.class.php");
require("/home/rconfig/classes/compareClass.php");
require('/home/rconfig/classes/sshlib/Net/SSH2.php'); // this will be used in connection.class.php 
require("/home/rconfig/classes/connection.class.php");
require("/home/rconfig/classes/debugging.class.php");
require("/home/rconfig/classes/textFile.class.php");
require_once("/home/rconfig/config/config.inc.php");
require_once("/home/rconfig/config/functions.inc.php");

// declare DB Class
$db = new db();

// check and set timeZone
$q      = $db->q("SELECT timeZone FROM settings");
$result = mysql_fetch_assoc($q);
$timeZone = $result['timeZone'];
date_default_timezone_set($timeZone);

// declare Logging Class
$log = ADLog::getInstance();
$log->logDir = $config_app_basedir."logs/";

// create array for json output to return to downloader window
$jsonArray = array();

// check if this script was CLI Invoked and throw an error to the CLI if it was.
if (php_sapi_name() == 'cli') {  // if invoked from CLI
	$text = "You are not allowed to invoke this script from the CLI - unable to run script";
	echo $text."\n";
	$log->Fatal("Error: ".$text." (File: ".$_SERVER['PHP_SELF'].")");
	die();
} 

// set vars passed from ajaxDownloadNow.php on require()
$rid = $passedRid;
$providedUsername = $passedUsername;
$providedPassword = $passedPassword;

// Log the script start
$log->Info("The ".$_SERVER['PHP_SELF']." script was run manually invoked with Router ID: $rid "); // logg to file

// get time-out setting from DB
$timeoutSql = $db->q("SELECT deviceConnectionTimout FROM settings");
$result = mysql_fetch_assoc($timeoutSql);
$timeout = $result['deviceConnectionTimout'];

// Get active nodes for a given task ID
// Query to retrieve row for given ID (tidxxxxxx is stored in nodes and is generated when task is created)
$getNodesSql = "SELECT 
					id, 
					deviceName, 
					deviceIpAddr, 
					devicePrompt, 
					deviceUsername, 
					devicePassword, 
					deviceEnableMode, 
					deviceEnablePassword, 
					nodeCatId, 
					deviceAccessMethodId, 
					connPort 
					FROM nodes WHERE id = " . $rid . " AND status = 1";

if($result = $db->q($getNodesSql)) {

	// push rows to $devices array
	$devices = array();
	while($row = mysql_fetch_assoc($result)){
		array_push($devices, $row);
	}

	foreach($devices as $device){ // iterate over each device - in this scripts case, there will only be a single device

	// ok, verification of host reachability based on fsockopen to host port i.e. 22 or 23. If fails, continue to next foreach iteration		
	$status = getHostStatus($device['deviceIpAddr'], $device['connPort']); // getHostStatus() from functions.php 
	
	if ($status === "<font color=red>Unavailable</font>"){
		$text = "Failure: Unable to connect to ".$device['deviceName']." - ".$device['deviceIpAddr']." when running Router ID ".$rid;
		$jsonArray['connFailMsg'] = $text;
		$log->Conn($text." - getHostStatus() Error:(File: ".$_SERVER['PHP_SELF'].")"); // logg to file
		echo json_encode($jsonArray);
		continue;
	}
	
	// get command list for device. This is based on the catId. i.e. catId->cmdId->CmdName->Node
	$commands = $db->q("SELECT cmd.command 
							FROM cmdCatTbl AS cct
							LEFT JOIN configcommands AS cmd ON cmd.id = cct.configCmdId
							WHERE cct.nodeCatId = ".$device['nodeCatId']);
	$cmdNumRows = mysql_num_rows($commands); 	
		
		// get the category for the device						
	$catNameQ = $db->q("SELECT categoryName FROM categories WHERE id = ".$device['nodeCatId']);	
			
	$catNameRow = mysql_fetch_row($catNameQ);
	$catName = $catNameRow[0]; // select only first value returned

	// check if there are any commands for this devices category, and if not, error and break the loop for this iteration
	if ($cmdNumRows == 0){
		$text = "Failure: There are no commands configured for category ".$catName." when running Router ID ".$rid;
		$log->Conn($text." - Error:(File: ".$_SERVER['PHP_SELF'].")"); // logg to file
		$jsonArray['cmdNoRowsFailMsg'] = $text;
		echo json_encode($jsonArray);
		continue;
	}
	
	// declare file Class based on catName and DeviceName
	$file = new file($catName, $device['deviceName'], $config_data_basedir);	
	
	if (!empty($providedUsername) && !empty($providedPassword) && $providedUsername != "0" && $providedPassword != "0"){
		$conn = new Connection($device['deviceIpAddr'], $providedUsername, $providedPassword, $device['deviceEnableMode'], $providedPassword, $device['connPort'], $timeout);
	}else{
		// Connect for each row returned - might want to do error checking here based on if an IP is returned or not
		$conn = new Connection($device['deviceIpAddr'], $device['deviceUsername'], $device['devicePassword'], $device['deviceEnableMode'], $device['deviceEnablePassword'], $device['connPort'], $timeout);
	}

	$connFailureText = "Failure: Unable to connect to ".$device['deviceName']." - ".$device['deviceIpAddr']." for Router ID ".$rid;
	$connSuccessText = "Success: Connected to ".$device['deviceName']." (".$device['deviceIpAddr'].") for Router ID ".$rid;
			
	// if connection is telnet, connect to device function
	if($device['deviceAccessMethodId'] == '1'){ // 1 = telnet

		if($conn->connectTelnet() === false){
			$log->Conn($connFailureText." - in  Error:(File: ".$_SERVER['PHP_SELF'].")"); // logg to file
			$jsonArray['failTelnetConnMsg'] = $text;
			echo json_encode($jsonArray);
			continue; // continue; probably not needed now per device connection check at start of foreach loop - failsafe?
		}
	
		$jsonArray['telnetConnMsg'] = $connSuccessText.'<br /><br />';
		$log->Conn($connSuccessText." - in (File: ".$_SERVER['PHP_SELF'].")"); // log to file
	} // end if device access method

	$i=0; // set i to prevent php notices	
	// loop over commands for given device
	while($cmds = mysql_fetch_assoc($commands)){
	$i++;
	
		// Set VARs
		$command = $cmds['command'];
		$prompt = $device['devicePrompt'];
		
		if(!$command || !$prompt){
			$text = "Command or Prompt Empty - in (File: ".$_SERVER['PHP_SELF'].")\n";
			$log->Conn("Command or Prompt Empty - for function switch in  Success:(File: ".$_SERVER['PHP_SELF'].")"); // logg to file
			$jsonArray['emptyCommandMsg'.$i] = $text;
			echo json_encode($jsonArray);
			break;
		}

		//create new filepath and filename based on date and command -- see testFileClass for details - $fullpath return for use in insertFileContents method
		$fullpath = $file->createFile($command);
					
		// check for connection type i.e. telnet SSHv1 SSHv2 & run the command on the device
		if($device['deviceAccessMethodId'] == '1'){ // telnet

			$showCmd = $conn->showCmdTelnet($command, $prompt, false);

		} elseif($device['deviceAccessMethodId'] == '3'){ //SSHv2 

			$showCmd =  $conn->connectSSH($command, $prompt);
			
			// if false returned, log failure
			if ($showCmd == false) {
					$sshFailureText = "Failure: Unable to connect via SSH to ".$device['deviceName']." - ".$device['deviceIpAddr']." for command (".$command.")  when running Router ID ".$rid;
					$log->Conn($sshFailureText." - in  Error:(File: ".$_SERVER['PHP_SELF'].")"); // log to file
					$jsonArray['sshConnFailureMsg'] = $sshFailureText;
					// echo json_encode($jsonArray); 
			} else {
				$sshConnectedText = "Success: Connected via SSH to ".$device['deviceName']." (".$device['deviceIpAddr'].") for command (".$command.") for Router ID ".$rid;
				$log->Conn($sshConnectedText." - in (File: ".$_SERVER['PHP_SELF'].")"); // log to file
				$jsonArray['sshConnSuccessMsg'] = $sshConnectedText;
				// echo json_encode($jsonArray);
			}
			
		} else {
			continue;	
		}
						
		// output command json for response to web page
		$jsonArray['cmdMsg'.$i] = "Command '" . $command . "' ran successfully";	

		// create new array with PHPs EOL parameter
		$filecontents = implode(PHP_EOL, $showCmd);

		// insert $filecontents to file
		$file->insertFileContents($filecontents, $fullpath);
					
		$filename = basename($fullpath); // get filename for DB entry
		$fullpath = dirname($fullpath); // get fullpath for DB entry

		// insert info to DB
		$configDbQ ="INSERT INTO configs (deviceId, configDate, configLocation, configFilename) 
					VALUES (
					" . $device['id'] . ", 
					NOW(), 
					'" . $fullpath . "',
					'" . $filename . "'
					)";
						
		if($result = $db->q($configDbQ)) {
			$log->Conn("Success: Show Command '".$command."' for device '". $device['deviceName'] ."' successful (File: ".$_SERVER['PHP_SELF'].")");

		} else {
			$log->Fatal("Failure: Unable to insert config information into DataBase Command (File: ".$_SERVER['PHP_SELF'].") SQL ERROR:". mysql_error());
			die();
		}
		
		//check for last iteration... 
		 if ($i == $cmdNumRows) {
		 
				if($device['deviceAccessMethodId'] == '1'){ // 1 = telnet
				$conn->close('40'); // close telnet connection - ssh already closed at this point
			}
		}
	}// end command while loop
} //end foreach

// final msg
$jsonArray['finalMsg'] = "<b>Manual download completed</b> <br/><br/> <a href='javascript:window.close();window.opener.location.reload();'>close</a>";	

// echo json response for msgs back to page
// echo '<pre>';
// print_r($jsonArray);
echo json_encode($jsonArray);
	
} else {
		echo "Failure: Unable to get Device information from Database Command (File: ".$_SERVER['PHP_SELF'].") SQL ERROR: ". mysql_error();
		$log->Fatal("Failure: Unable to get Device information from Database Command (File: ".$_SERVER['PHP_SELF'].") SQL ERROR: ". mysql_error());
		die();
}

?>