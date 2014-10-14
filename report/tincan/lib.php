<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library of interface functions and constants for module tincan
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 * All the tincan specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package report_tincan
 * @copyright  LEO
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

class report_tincan {
	public static function tincan_quiz_attempt_started($event){
		global $CFG, $DB;
		$course  = $DB->get_record('course', array('id' => $event->courseid));
		$quiz    = $DB->get_record('quiz', array('id' => $event->quizid));
		$cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
		$attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));
		$registrationUID = self::createTincanUID($attempt->id);
		$statementUID = self::tincanrpt_gen_uuid();
		$parentid = $CFG->wwwroot . '/mod/course/view.php?id=' . $course->id;
		$parentObjType = "Activity";
		$statement = array(
			'id' => $statementUID,
			'actor' => self::tincan_getactor(), 
			'verb' => array(
				'id' => 'http://adlnet.gov/expapi/verbs/attempted',
				'display' => array(
					'en-US' => 'attempted',
					'en-GB' => 'attempted',
				),
			),
			'object' => array(
				'id' =>  $CFG->wwwroot . '/mod/quiz/view.php?id='. $quiz->id, 
				'definition' => array(
					'name' => array(
						'en-US' => $quiz->name,
					),
					'description' => array(
						'en-US' => $quiz->intro,
					), 
					'type' => 'http://adlnet.gov/expapi/activities/assessment',
					'extensions' => array('http://id.tincanapi.co.uk/extension/legacy-id' => self::get_legacy_quiz($quiz->id)),
				),
			),
			'result' => array(
			"completion" => false,
			),            
		   // everything after this is standard
		   'context' => self::tincan_getcontext($registrationUID, $parentid, $parentObjType, self::get_legacy_quiz_revision($quiz->id)),
		   'timestamp' => date(DATE_ATOM),
		);

		try {
			$statementid = self::tincanrpt_gen_uuid(); 
			$version = get_config('report_tincan', 'lrsversion');
			if (empty($version)){
				$version = '1.0.0';
			}
			$url = get_config('report_tincan', 'lrsendpoint');
			$basicLogin = get_config('report_tincan', 'lrslogin');
			$basicPass = get_config('report_tincan', 'lrspass');

			$lrsresp = self::tincanrpt_save_statement($statement, $url, $basicLogin, $basicPass, $version, $statementUID);

			//JoeB 19/08/2014 - add DonC's error handling code
			$lrsRespResponseArr = $lrsresp['meta'];
           	$lrsRespResponseCode = $lrsRespResponseArr["wrapper_data"][0]; 
  
 			if ((strpos($lrsRespResponseCode, "204") == false) && (strpos($lrsRespResponseCode, "200") == false)) { 
				// update the tincan statements log with details for the failed transmission
				$record = new stdClass();
				$record->statement = json_encode($statement);
				$record->statement_id = $statementUID;
				$failId = $DB->insert_record('report_tincan_fails', $record);

				$failLog = new stdClass();
				$failLog->timestamp = date(DATE_ATOM);
				$failLog->error = $lrsRespResponseCode;
				$failLog->flagged =   false;
				$failLog->statement = $failId;
				$insIdx = $DB->insert_record('report_tincan_failslog', $failLog);
	        }
	        //end error handling

		}
		catch(Exception $e) {
		  	//exception handling here
		}

		return true;
	}

	public static function tincan_quiz_attempt_submitted($event){
		global $CFG, $DB, $USER;
		$course  = $DB->get_record('course', array('id' => $event->courseid));
		$quiz    = $DB->get_record('quiz', array('id' => $event->quizid));
		$cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
		$attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));

		$statementUID = self::tincanrpt_gen_uuid();
		$registrationUID = self::findTincanUID($attempt->id);

		$parentid = $CFG->wwwroot."/course/view.php?id=" . $event->quizid;
		$parentObjType = "Activity";
		 
		// ###### this looks to be standard 
		$statement = array(
			'id' => $statementUID,
			'actor' => self::tincan_getactor(),
			// #### everything before is standard            
			'verb' => self::tincan_getverb('completed'),
			// ##### this looks to be standard    
			'object' => array(
				'id' =>  $CFG->wwwroot . '/mod/quiz/view.php?id='. $quiz->id, 
				'objectType' => 'Activity',
			),
			// this is specific to completed processing                
			'result' => array(
				"completion" => true
			),
			//  everything after this is standard        
			'context' => self::tincan_getcontext($registrationUID, $parentid, $parentObjType, self::get_legacy_quiz_revision($quiz->id)),
			'timestamp' => date(DATE_ATOM), 
		);

		$statements =array(); 
		array_push($statements,$statement);
		//send it
		
		//add pass or fail block for quiz
		$outcomeStatement = self::tincan_write_quiz_outcome($event, $quiz, $attempt, $registrationUID);
		if ($outcomeStatement){
			array_push($statements,$outcomeStatement);
		}

		// for each question in quiz - submit the block for the question  
		$questions_array = self::tincan_write_question_submits($event,$registrationUID);
			
		foreach ($questions_array as $question_statement){
			array_push($statements,$question_statement);
		}

		try {
			$version = get_config('report_tincan', 'lrsversion');
			if (empty($version)){
				$version = '1.0.0';
			}
			$url = get_config('report_tincan', 'lrsendpoint');
			$basicLogin = get_config('report_tincan', 'lrslogin');
			$basicPass = get_config('report_tincan', 'lrspass');

			$lrsresp = self::tincanrpt_save_statements($statements, $url, $basicLogin, $basicPass, $version);

			//JoeB 19/08/2014 - add DonC's error handling code
			$lrsRespResponseArr = $lrsresp['meta'];
           	$lrsRespResponseCode = $lrsRespResponseArr["wrapper_data"][0]; 
  
 			if ((strpos($lrsRespResponseCode, "204") == false) && (strpos($lrsRespResponseCode, "200") == false)) {
				// update the tincan statements log with details for the failed transmission
				foreach ($statements as $failedstatement) {
					$record = new stdClass();
					$record->statement = json_encode($failedstatement);
					$record->statement_id = $statementUID;
					$failId = $DB->insert_record('report_tincan_fails', $record);

					$failLog = new stdClass();
					$failLog->timestamp = date(DATE_ATOM);
					$failLog->error = $lrsRespResponseCode;
					$failLog->flagged =   false;
					$failLog->statement = $failId;
					$insIdx = $DB->insert_record('report_tincan_failslog', $failLog);
				}
	        }
	        //end error handling

		}
		catch(Exception $e) {
		  	//exception handling here
		}
		return true;            
	}

	public static function tincan_quiz_attempt_submitted_child($event,$questionid,$quiz,$attempt,$questionsattempt, $registrationUID){
		global $CFG, $DB, $USER;
		$course  = $DB->get_record('course', array('id' => $event->courseid));
		$cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
		
		$interactionType = "choice";
		$answers_array = $DB->get_records('question_answers', array('question' => $questionsattempt->questionid));

		// User answer respond reference from the table 
		/*
		$respondAnswerRefQuery = $DB->get_recordset_sql('SELECT clralr.areference FROM mdl_question_answers quan, x_question_answer_legacyref clralr WHERE quan.question = '.$questionsattempt->questionid.' AND quan.answer LIKE "%'.$questionsattempt->responsesummary.'%" AND quan.id = clralr.questionanswer');
		*/

		$respondAnswerRefSQL = "SELECT clralr.areference FROM mdl_question_answers quan, x_question_answer_legacyref clralr 
			WHERE quan.question = ? 
			AND quan.answer LIKE ? 
			AND quan.id = clralr.questionanswer";

		$params = array ("question"=>$questionsattempt->questionid, "answer"=>"%" . $questionsattempt->responsesummary . "%");
        
        $recordset = $DB->get_recordset_sql($respondAnswerRefSQL, $params); 

		foreach ($recordset as $value) {
			$respondAnswerRef = $value->areference;
		}

		$recordset->close();
		
		$respondAnswerRef = self::tincanrpt_stripSquareBraces($respondAnswerRef);

		// User answer respond reference from the table 
		/*
		$rightAnswerRefQuery = $DB->get_recordset_sql('SELECT clralr.areference FROM mdl_question_answers quan, x_question_answer_legacyref clralr WHERE quan.question = '.$questionsattempt->questionid.' AND quan.answer LIKE "%'.$questionsattempt->rightanswer.'%" AND quan.id = clralr.questionanswer');
		*/

		$rightAnswerRefSQL = "SELECT clralr.areference FROM mdl_question_answers quan, x_question_answer_legacyref clralr 
			WHERE quan.question = ? 
			AND quan.answer LIKE ? 
			AND quan.id = clralr.questionanswer";

		$params = array ("question"=>$questionsattempt->questionid, "answer"=>"%" . $questionsattempt->rightanswer . "%");
        
        $recordset = $DB->get_recordset_sql($rightAnswerRefSQL, $params); 

		foreach ($recordset as $value) {
			$rightAnswerRef = $value->areference;
		}

		$recordset->close();

		$rightAnswerRef = self::tincanrpt_stripSquareBraces($rightAnswerRef);

		// All answer references from the table 
		$choices = array();
		/*
		$AnswerRefQuery = $DB->get_recordset_sql("SELECT clralr.areference FROM mdl_question_answers quan, x_question_answer_legacyref clralr WHERE quan.question = ".$questionsattempt->questionid." AND quan.id = clralr.questionanswer");
		*/

		$answerRefQuerySQL = "SELECT clralr.areference FROM mdl_question_answers quan, x_question_answer_legacyref clralr 
			WHERE quan.question = ? 
			AND quan.id = clralr.questionanswer";

		$params = array ("question"=>$questionsattempt->questionid);
    
        $recordset = $DB->get_recordset_sql($answerRefQuerySQL, $params); 

		foreach ($recordset as $value) {
			array_push(
				$choices, array(
					"id" => self::tincanrpt_stripSquareBraces($value->areference)
				)
			);
		}

		$recordset->close();

		// answer respond correct or not 
		if ($respondAnswerRef == $rightAnswerRef) {
			$success = true;
		} else {
			$success = false;
		}

		$correctResponsesPattern = array($rightAnswerRef);
		$response = $respondAnswerRef;

		$statementUID = self::tincanrpt_gen_uuid();
		$statementsection = array(
			'id' => $statementUID,
			'actor' => self::tincan_getactor(),
			'verb' => array(
				'id' => 'http://adlnet.gov/expapi/verbs/answered',
				'display' => array(
					'en-US' => 'answered',
				),
			),
			'object' => array(
				'id' =>  $CFG->wwwroot.'/question/preview.php?id='.$questionsattempt->questionid, 
				'definition' => array(
					'type' => "http://adlnet.gov/expapi/activities/cmi.interaction",
					'interactionType' => $interactionType,
					'correctResponsesPattern' => $correctResponsesPattern,
					'extensions' => array('http://id.tincanapi.co.uk/extension/legacy-id' => self::get_legacy_question($questionsattempt->questionid)),
					'choices' => $choices
				),
				'objectType' => 'Activity'
			),
			'result' => array(
				"completion" => true,
				"success" => $success
			),
			'context' => array(
				"registration" => $registrationUID,
				"contextActivities" => 
				array(
					"grouping" => array(array(
						"id" =>  $CFG->wwwroot."/course/view.php?id=".$course->id,
						"objectType" => "Activity",
					)),
					"parent" => array(array(
						"id" =>   $CFG->wwwroot . '/mod/quiz/view.php?id='. $quiz->id,
						"objectType" => "Activity",
					)),
				),    			
				"revision" => self::get_legacy_quiz_revision($quiz->id),
				"platform" => $_SERVER['HTTP_USER_AGENT'],
				"language" => self::tincanrpt_get_moodle_langauge(),
			),
			"timestamp" => date(DATE_ATOM), 
		);
     
		if (!is_null($respondAnswerRef)) { 
			$statementsection['result']['response'] = $response;
		};	
		
		//send it
		try {
			return $statementsection; // just return the array section as this is part of the overall statement submitted on complete
		}
		catch(Exception $e) {
		  	//exception handling here
		}
		return true;
	}

	public static function tincan_quiz_attempt_passing($event,$registrationUID){
		global $CFG, $DB, $USER;
		$course  = $DB->get_record('course', array('id' => $event->courseid));
		$quiz    = $DB->get_record('quiz', array('id' => $event->quizid));
		$cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
		$attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));

		$parentid = $CFG->wwwroot."/course/view.php?id=" . $event->quizid;
		$parentObjType = "Activity";

		$statementUID = self::tincanrpt_gen_uuid();
		$statementsection = array( 
			'id' => $statementUID,
			'actor' => self::tincan_getactor(),
			'verb' => self::tincan_getverb('passed'),
			'object' => array(
				'id' =>  $CFG->wwwroot . '/mod/quiz/view.php?id='. $quiz->id, 
				'objectType' => 'Activity',
			),
			// this is specific to completed processing        
			'result' => array(
				"completion" => true,
				"success" => true,
				"score" => array(
					'scaled' => (($attempt->sumgrades)/($quiz->sumgrades)),
					'raw' => (($attempt->sumgrades)/($quiz->sumgrades))*($quiz->grade),
					'min' => 0, 
					'max' => floatval($quiz->grade), 
				)
			),
			'context' => self::tincan_getcontext($registrationUID, $parentid, $parentObjType, self::get_legacy_quiz_revision($quiz->id)),
			'timestamp' => date(DATE_ATOM),  
		);
		try {
			// as this will be one for a series of statements - just return this json fragment
			// just return the array section as this is part of the overall statement submitted on complete
			return ($statementsection);
		}
		catch(Exception $e) {
		  	//exception handling here
		}
		return true;
	}

	public static function tincan_quiz_attempt_failure($event,$registrationUID){
		global $CFG, $DB, $USER;
		$course  = $DB->get_record('course', array('id' => $event->courseid));
		$quiz    = $DB->get_record('quiz', array('id' => $event->quizid));
		$cm      = get_coursemodule_from_id('quiz', $event->cmid, $event->courseid);
		$attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));

		$parentid = $CFG->wwwroot."/course/view.php?id=" . $course->id;
		$parentObjType = "Activity";
		$statementUID = self::tincanrpt_gen_uuid();
		$statementsection = array( 
			'id' => $statementUID,
			'actor' => self::tincan_getactor(),
			'verb' => self::tincan_getverb('failed'),
			'object' => array(
				'id' =>  $CFG->wwwroot . '/mod/quiz/view.php?id='. $quiz->id, 
				'objectType' => 'Activity'
			),
			'result' => array(
				"completion" => true,
				"success" => false,
				"score" => array(
					'scaled' => (($attempt->sumgrades)/($quiz->sumgrades)),
					'raw' => (($attempt->sumgrades)/($quiz->sumgrades))*($quiz->grade),
					'min' => 0,
					'max' => floatval($quiz->grade),
				),
			),
			'context' => self::tincan_getcontext($registrationUID, $parentid, $parentObjType, self::get_legacy_quiz_revision($quiz->id)), 
			'timestamp' => date(DATE_ATOM),
		);
		try {   
			// as this will be one for a series of statements - just return this json fragment
			// just return the array section as this is part of the overall statement submitted on complete
			return ($statementsection);
		}
		catch(Exception $e) {
		  	//exception handling here
		}
		return true;
	}

	public static function tincan_getactor(){
		global $USER, $CFG;
		//For this project, never use e-mail but instead use a pipe delimited combo of username and idnumber. TODO for future projects: make this a config setting. 
		/*if ($USER->email){
			return array(
				"name" => fullname($USER),
				"mbox" => "mailto:".$USER->email,
				"objectType" => "Agent"
			);
		}
		else{*/
		if ($USER->idnumber){
			return array(
				"name" => fullname($USER),
				"account" => array(
					"homePage" => 'https://example.com',
					"name" => $USER->idnumber
				),
				"objectType" => "Agent"
			);
		}
		else{
			return array(
				"name" => fullname($USER),
				"account" => array(
					"homePage" => $CFG->wwwroot,
					"name" => $USER->username
				),
				"objectType" => "Agent"
			);
		}
	}

	public static function tincan_getverb($verbname){
		return (
			array(
				'id' => 'http://adlnet.gov/expapi/verbs/' . $verbname,
				'display' => array(
					'en-US' => $verbname,
				),
			)
		);
	}

	public static function tincan_getcontext($tincanid, $parentid, $parentObjType, $revision){
		// routine writes the tincan report context section
		// tincanid : The unique tincanid relating to a specific report
		// parentid : The id relating to th tincan report context
		// parentObjType : The type of object relating to the context
		// revision: revision value from quiz table quiz->revision
		return (
			array(
				//  everything after this is standard
				"registration"=>$tincanid,
				"contextActivities" => array(
					"parent" => array( 
						array(
							"id" => $parentid,
							"objectType" => $parentObjType
						)
					)
				),
				"revision" => $revision,
				"platform" => $_SERVER['HTTP_USER_AGENT'],
				"language" => self::tincanrpt_get_moodle_langauge(),
			)
		);
	} 

	public static function tincan_write_quiz_outcome($event, $quiz, $attempt,$registrationUID){
		global $CFG, $DB, $USER;
		// Using the list of qustions from the quiz
		// get a list of the questions from moodle
		$passing_grade = 0;
		if ($quiz_passing_grade = $DB->get_record('grade_items', array('itemmodule' => 'quiz', 'iteminstance' =>$quiz->id))) {  
			$passing_grade = $quiz_passing_grade->gradepass;
		}
		$scaled_grade = $attempt->sumgrades / $quiz->sumgrades * $quiz->grade;
		if ($passing_grade > 0){
			// get actual and passing grade and compare them    
			if ($scaled_grade >= $passing_grade){
				// write the block for the pass
				return self::tincan_quiz_attempt_passing($event,$registrationUID);
			}else{
				// write the block for the fail
				return self::tincan_quiz_attempt_failure($event,$registrationUID);
			}
		} else{
			// do nothing
			return NULL;
		}
	}

	public static function tincan_write_question_submits($event,$registrationUID){
		global $CFG, $DB, $USER;
		// Using the list of qustions from the quiz
		// get a list of the questions from moodle
		$quiz    = $DB->get_record('quiz', array('id' => $event->quizid));   
		$statementsection =  array();
		$attempt = $DB->get_record('quiz_attempts', array('id' => $event->attemptid));
		$questionsattempts = $DB->get_records('question_attempts',array('questionusageid'=>$attempt->uniqueid));
		
		foreach($questionsattempts as $questionsattempt){
			// add the section relating to this question submission on here
			
			
			$question_id = $questionsattempt->questionid;
			if (($question_id != 0 ) &&($questionsattempt->responsesummary))  {
				$submittedsection[] = self::tincan_quiz_attempt_submitted_child($event,$question_id,$quiz,$attempt,$questionsattempt,$registrationUID);
			}
		}
		
		return $submittedsection;
	}

	public static function createTincanUID($attemptid){
		global $DB;
		$newtincanid = self::tincanrpt_gen_uuid();
		$record = new stdClass();   
		$record->registration_id = $newtincanid;
		$record->attemptid = $attemptid;
		$record->transmission_type = 'CREATE';
		$record->status = 'new';
		$record->statustext = 'new transmission attempt';
		$record->firstsendattempt = date(DATE_ATOM);
		$record->lastsendattempt  = date(DATE_ATOM); 
		$record->tincanjson =  "";
		$DB->insert_record('report_tincan_attempts', $record);  
		return $newtincanid;
	}

	public static function findTincanUID($attemptid){
		global $DB;
		// retrieve registration uid code
		// Create connection
		$queryparms = array ("attemptid"=>$attemptid);
		$SQLGetTincanUID = "SELECT id, registration_id, attemptid  FROM mdl_report_tincan_attempts WHERE attemptid = ?";
		// Look for failed status records  - using the Moodle DB API
		$records = $DB->get_records_sql($SQLGetTincanUID,$queryparms);      
		foreach ($records as $record) {
			return ($record->registration_id); 
		}
		// no match found so return an empty string  - triggering creation of a new record
		return ("");
	}
	  
	public static function update_Tincan_record($quiz_id, $reg_id, $jsonstatement){
		// get the internal id for the tincan record matching the quiz_id and registraton id 
		// Create connection
		$queryparms = array ("quizid"=>$quiz_id, "registration_id"=>$reg_id);
		$SQLGetAttempts = "SELECT id, sendattempts, quizattemptdata FROM mdl_sendquizattempt WHERE quizid = ? AND registration_id = ?";
		// Look for failed status records  - using the Moodle DB API
		$records = $DB->get_record_sql($SQLGetAttempts,$queryparms);      
		foreach ($records as $record) {
			// get the id of the update record relating to this tincan report registration
			$attemptID    =  $record->id;
			$attemptSendNumbers= $record->sendattempts;
		}
		// push a new update including the 
		$record = new stdClass();
		$record->id = (int)$attemptID;        

		//increment $sendattempts by on
		$attemptSendNumbers = (int) $attemptSendNumbers++;
		$record->sendattempts = (int) $attemptSendNumbers;
		$record->transmission_type = 'UPDATE';
		$record->status = 'update';
		$record->statustext = 'update';
		$record->quizid = $quiz_id;        
		$record->lastsendattempt  = date(DATE_ATOM);
		$record->tincanjson =  $jsonstatement;
		$DB->update_record('report_tincan_attempts', $record);
	}

	public static function get_legacy_quiz($moodle_quiz_id){
		global $DB;
		// Create connection
		$queryparms = array ("id"=>$moodle_quiz_id);
		$SQLGetlegquizUID = "SELECT treference  FROM x_quiz_legacyref WHERE quiz = ?";
		// no match found so return an empty string  - triggering creation of a new record
		$legquiz = "no match";
		// Look for failed status records  - using the Moodle DB API
		$records = $DB->get_records_sql($SQLGetlegquizUID,$queryparms);      
		foreach ($records as $record) {
			$legquiz = $record->treference; 
		}
		return self::tincanrpt_stripSquareBraces($legquiz);
	}

	public static function get_legacy_quiz_revision($moodle_quiz_id){
		global $DB;
		$queryparms = array ("id"=>$moodle_quiz_id);
		$SQLGetlegquizUID = "SELECT tversion  FROM x_quiz_legacyref WHERE quiz = ?";
		// no match found so return an empty string  - triggering creation of a new record
		$legrevision = "no match";
		// Look for failed status records  - using the Moodle DB API
		$records = $DB->get_records_sql($SQLGetlegquizUID,$queryparms);      
		foreach ($records as $record) {
			$legrevision = $record->tversion; 
		}
		return $legrevision;
	}
	  
	public static function get_legacy_question($moodle_question_id){
		global $DB;
		$queryparms = array ("id"=>$moodle_question_id);
		$SQLGetlegquestionUID = "SELECT qreference  FROM x_question_legacyref WHERE question = ?";
		// Look for failed status records  - using the Moodle DB API
		$records = $DB->get_records_sql($SQLGetlegquestionUID,$queryparms);      
		foreach ($records as $record) {
			return (self::tincanrpt_stripSquareBraces($record->qreference)); 
		}

		return ("");
	}

	public static function  storeTincanRetry($statement, $lrsreciept, $quiz_id){
		global $CFG,$DB;
		// update the tincan attempts table with details of the failure
		$record = new stdClass();       
		$record->quizid                 = $quiz_id;
		$record->userid                 = 0;
		
		$record->registration_id        = "TODO";        
		$record->transaction_type       = "json"; 
		
		$record->status                 = "FAILED";  
		$record->statustext             = $lrsreciept;        
		$record->sendattempts           = 1;
		$record->firstsendattempt       = "";
		$record->lastsendattempt        = "";       
		$record->tincanjson        = $statement;
	   
		$DB->insert_record('report_tincan_attempts', $record);  

		// update the tincan failed statements table with a row for the failure
		$record = new stdClass();  
		$record->Statement = $statement;
		$record->statement_id = $statementUID;
		$DB->insert_record('report_tincan_fails', $record);  

		// update the tincan statements log with details for the failed transmission
		$record = new stdClass();  
		$record->timestamp = date(DATE_ATOM);
		$record->error = $lrsreciept;
		$record->flagged =   false;  // this is an original log message for a failure - so we do want to flag this one as not having been emailed
		$DB->insert_record('report_tincan_failslog', $record);  
	}

	public static function tincanrpt_get_moodle_langauge(){
		$lang = current_language();
		$langArr = explode('_',$lang);
		if(count($langArr) == 2){
			return $langArr[0].'-'.strtoupper($langArr[1]);
		}else{
			return $lang;
		}
	}

	// added to overcome issues in the rustici driver
	public static function tincanrpt_gen_uuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

			// 16 bits for "time_mid"
			mt_rand( 0, 0xffff ),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand( 0, 0x0fff ) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand( 0, 0x3fff ) | 0x8000,

			// 48 bits for "node"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
 
	public static function tincanrpt_save_statement($data, $url, $basicLogin, $basicPass, $version, $statementid) {
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
				'content' => self::tincanrpt_myJson_encode($data), 
			), 
		);
		
		$streamparams = array(
			'statementId' => $statementid,
		);

		$context = stream_context_create($streamopt);	        
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

	public static function tincanrpt_save_statements($data, $url, $basicLogin, $basicPass, $version) {
		$return_code = "";
		$streamopt = array(
			'ssl' => array(
				'verify-peer' => false, 
				), 
			'http' => array(
				'method' => 'POST', 
				'ignore_errors' => false, 
				'header' => array(
					'Authorization: Basic ' . base64_encode( $basicLogin . ':' . $basicPass), 
					'Content-Type: application/json', 
					'Accept: application/json, */*; q=0.01',
					'X-Experience-API-Version: '.$version
				), 
				'content' => self::tincanrpt_myJson_encode($data), 
			), 
		);
		
		$streamparams = array();
		
		$context = stream_context_create($streamopt);
		// to switch off the last error reported
		// var_dump or anything else, as this will never be called because of the 0
		set_error_handler('var_dump', 0);
		restore_error_handler();

		$stream = fopen(trim($url) . 'statements'.'?'.http_build_query($streamparams,'','&'), 'rb', false, $context);

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
	public static function tincanrpt_myJson_encode($obj){
		// deal with the carriage return line feed 
		//$str_nolf =  str_replace("\n", " ", $str);    
		//$str_nocrlf =  str_replace("\r", " ", $str_nolf);
		return strip_tags(str_replace('\\/', '/',json_encode($obj)));        
	} 
	
	public static function tincanrpt_stripSquareBraces($str){
		$str = str_replace('[', '', $str);
		$str = str_replace(']', '', $str);
		return $str;
	}

}
