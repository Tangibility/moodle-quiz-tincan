<?php

//include('../../report/tincan/lib.php');

//include('JSONFailMailer.php');

/*
 * JoeB 22/08/2014 - a local plugin that only exists as a means to run the 
 * cron script originally developed as part of the /report/tincan plugin.
 *
 * The cron script looks for failed tincan statements from the DB, attempts to resend them, 
 * and sends an email containing a digest of the failed statements to the specified admin email address.
 */

function local_resendtincandata_cron() {

    global $DB;

    // pull from the table identifying all the outstanding cron messages
    // is the interval from the last cron cycle greater than the required cron cycle interval
    // 
    // get the configuration interval
    // get the last update timestamp from the failed statements log 
    // 
    // if we are due a new reattempt
    //     get all the current failure rows
    //     for each row
    //        reattempt the row submission 
    //        if row submission is a success this time 
    //          remove the row for this submission from the failed table 
    //        otherwise if the row submission is a success then 
    //          resubmit the row to the lrs and process the receipt message
    //          add the notification line to the email digest of activity for the administrator
    //     end of rows
    //     
    //     if a digest has been created then we send it to the configured recipient
    // otherwise we are not due a cron based processing run so return not having done any thing
    
    // Setting for the cron transmission interval - see 
    $config_CronDiff = 5; // number of days of since last transmission which we want to use to poll cron
    $current_timestamp =  time();

    // TODO - failed rows which are older that the current inteval minus the configured amount 

    //$lrs = tincan_setup_lrs(); 
    $revisedTimestamp = $current_timestamp - ($config_CronDiff * 24 * 60 * 60);

    //$SQLGetFailRows = "SELECT id, timestamp, statement FROM mdl_report_tincan_failslog"; //JoeB - discuss what how often this should run - comment out cron date filter for now?
    $SQLGetFailRows = "SELECT id, statement FROM mdl_report_tincan_fails"; //JoeB - discuss what how often this should run - comment out cron date filter for now?
    $queryparams = array (); //JoeB - comment out code to the right - it won't work? // array ("revTimestamp"=>$newTimestamp);

    // if there are some records to process
    // start our email digest of the email statements
    // Look for failed status records  - using the Moodle DB API
    $count = $DB->get_records_sql($SQLGetFailRows, $queryparams);

    // if ($DB->count_records_sql($SQLGetFailRows, $queryparams) > 0) {
    if ($count) {

      $email_from = get_config('report_tincan', 'digestaddrfrom');
      $email_to = get_config('report_tincan', 'digestaddrto');
      $email_subject = 'TinCan tracking fails';
      $email_message = "A digest of failure information since the last Tincan report generation. <br>";
      $email_message .= "Failed JSON transaction rows follow:";
      // get the rows representing the fails from our log which are due a resend  
      $recordset = $DB->get_recordset_sql($SQLGetFailRows,$queryparams); 

      foreach ($recordset as $record) {
        $JSONStatement = $record->statement;
        $statement = json_decode($JSONStatement);
        // not now using this method -  $lrsReceipt = safeLRSSave($statement);
        $version = get_config('report_tincan', 'lrsversion'); //$tincanlaunchsettings['tincanlaunchlrsversion'];
        if (empty($version)){
          $version = '1.0.0'; //default TODO: put this default somewhere central
        }

        $url = get_config('report_tincan', 'lrsendpoint'); //$tincanlaunchsettings['tincanlaunchlrsendpoint']; 
        $basicLogin = get_config('report_tincan', 'lrslogin'); //$tincanlaunchsettings['tincanlaunchlrslogin'];
        $basicPass = get_config('report_tincan', 'lrspass'); //$tincanlaunchsettings['tincanlaunchlrspass'];
            

        $lrsresp = tincanrpt_save_statement($statement, $url, $basicLogin, $basicPass, $version, $statement->id);

        $lrsRespResponseArr = $lrsresp['meta'];
        $lrsRespResponseCode = $lrsRespResponseArr["wrapper_data"][0]; 
    
        if ((strpos($lrsRespResponseCode, "204") == false) && (strpos($lrsRespResponseCode, "200") == false) && (strpos($lrsRespResponseCode, "409") == false)) {
          // update the tincan statements log with details for the failed transmission
          $failLog = new stdClass();  
          $failLog->timestamp = date(DATE_ATOM);
          $failLog->error = $lrsRespResponseCode;
          $failLog->flagged =   false;  // this is an original log message for a failure - so we do want to flag this one as not having been emailed
          $failLog->statement = $record->id;
          $insIdx = $DB->insert_record('report_tincan_failslog', $failLog);   
          //add statement here
          // $email_message .= json_encode($statement) . "\n\n";
        } else {
          $DB->delete_records('report_tincan_fails', array('id' => $record->id));
        }
        // remove the row on the failed statements log table relating to this failure 
        // we will either have a success or a more recent try which we want to wait till the next batch before we resend
      }

      $recordset->close();
      // we have finished the traversal of the recordset - so we need to finalise and send the mailer 
      //$JSONMailer->sendJSONFailEmail();  

      $emailHasDataContents = false;
      // email the failed statements log
      $failedStatements = $DB->get_records('report_tincan_failslog',array('flagged'=>'0'));
      foreach ($failedStatements as $failedStatement) {
        $emailHasDataContents = true;
        $email_message .= "<br> Timestamp : " .$failedStatement->timestamp;
        $email_message .=  "<br> Error : ". $failedStatement->error;
        $grabIDs = $DB->get_records('report_tincan_fails', array('id'=>$failedStatement->statement));

        // Statement ID in to the email.
        // foreach ($grabIDs as $grabID) {
        //   $email_message .=  "<br> Statement ID : ". $grabID->statement_id;
        // }
      }

      // tincan duration from settings
      $settingsDuration = get_config('report_tincan', 'digestduration');
      $timeSeconds = ($settingsDuration * 60);

      // last mail sent timestamp
      $durationTable = $DB->get_records('report_tincan',array());
      if($durationTable){
        foreach ($durationTable as $durationTable) {
          $timelast_sent = $durationTable->tincan_timelast_sent;
        }
        // last sent time in to sec
        $lastSentInSec = strtotime($timelast_sent);
      }else{
        $timeNow = date(0);
        $lastSentInSec = strtotime($timeNow);

        $newTime = new stdClass(); 
        $newTime->tincan_timelast_sent = $timeNow;
        $insIdx = $DB->insert_record('report_tincan', $newTime);
      }

      // check the time ready for send mail
      $timeStamp = date(DATE_ATOM);
      $timeNowInSec = strtotime($timeStamp);
      if ( ($lastSentInSec + $timeSeconds) < $timeNowInSec  && $emailHasDataContents){
        if (mail($email_to, $email_subject, $email_message)) {
			echo ('mail sent:'.$email_message);
          $DB->execute("UPDATE mdl_report_tincan_failslog SET flagged = 1 ");
          $DB->execute("UPDATE mdl_report_tincan SET tincan_timelast_sent = '$timeStamp'");
        }
      }
    }
}

function tincanrpt_save_statement($data, $url, $basicLogin, $basicPass, $version, $statementid) {
    $return_code = "";
    $streamopt = array(
      'ssl' => array(
        'verify-peer' => false, 
        ), 
      'http' => array(
        'method' => 'PUT', 
        'ignore_errors' => false, 
        'header' => array(
          'Authorization: Basic ' . base64_encode( $basicLogin . ':' . $basicPass), 
          'Content-Type: application/json', 
          'Accept: application/json, */*; q=0.01',
          'X-Experience-API-Version: '.$version
        ), 
        'content' => tincanrpt_myJson_encode($data), 
      ), 
    );
    
    $streamparams = array(
      'statementId' => $statementid,
    );

    $context = stream_context_create($streamopt);         
    // Comment out Don's error handling
    // to switch off the last error reported
    // var_dump or anything else, as this will never be called because of the 0
    set_error_handler('var_dump', 0);
    restore_error_handler();

    $stream = fopen(trim($url).'statements'.'?'.http_build_query($streamparams,'','&'), 'rb', false, $context);

	$errLast =  error_get_last();

	if (strpos($errLast["message"],'failed to open stream') == false){
		$ret = stream_get_contents($stream);
		$meta = stream_get_meta_data($stream);
		fclose($stream);
		if ($ret) {
			$ret = json_decode($ret);
		}    
		return array("ret"=>$ret, "meta"=>$meta);
	}else{ 
		
		return (array( "ret"=> array ($errLast["message"]), "meta"=> array('wrapper_data'=>array($errLast["message"]))));           
	}
}

function tincanrpt_myJson_encode($obj){ 
    // deal with the carriage return line feed 
    return strip_tags(str_replace('\\/', '/',json_encode($obj)));        
} 
  
function tincanrpt_stripSquareBraces($str){
    $str = str_replace('[', '', $str);
    $str = str_replace(']', '', $str);
    return $str;
}
