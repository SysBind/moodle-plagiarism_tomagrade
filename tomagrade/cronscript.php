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
 * automatically submit a files to plagscan for analysis
 * (when assignment deadline is reached)
 *
 * @since 2.0
 * @package    plagiarism_tomagrade
 * @author     Ruben Olmedo based on work by Davo Smith
 * @copyright  @2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

mtrace("Define INTERNAL");
defined('MOODLE_INTERNAL') || die();
global $DB, $CFG;
require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/plagiarism/tomagrade/lib.php');

mtrace("Starting the TomaGrade cron");
// $data = $DB->get_records("plagiarism_tomagrade", array("status" => 0, "updatestatus" => 0));



 $log = "cron job log: ";

 function logAndPrint($msg,&$log) {
    echo $msg;
    echo "\n";

    $log .=  "\n" . $msg;
 }

 
if (checkEnabled()) {

    $connection = new tomagrade_connection;
   
  

    echo ("TomaConnection:");

    $DB->execute(" delete FROM {plagiarism_tomagrade_config} where cm not in ( SELECT id from {course_modules} )");


        // #### UPDATE RENDERING  - START
        $response = $connection->get_request("GetUnDownloadedCourses", "/assigns");
        $response = json_decode($response, true);
        if (isset($response['Exams'])) {
             $exams = $response['Exams']; 
        } else {
            logAndPrint("error in tomagrade server, GetUnDownloadedCourses did not response",$log);

            $exams = array();
        }
    
    

        $moodleAssignsArr = array();
        foreach($exams as $exam) {
            if (strpos($exam['ExamID'], '_') !== false) {
                // this is not a moodle assignment
                continue;
            }
          
            array_push($moodleAssignsArr,$exam['ExamID']);
        }



  
        $examsCmidsList = "";
        $examsIDsInCurrentMoodleServer = array();
        if (count($moodleAssignsArr)>0) {
            $moodleAssignsStr =  "";
            $isFirst = true;
            foreach($moodleAssignsArr as $examid) {
                if ($isFirst) {
                    $moodleAssignsStr .= "'".$examid."'";
                    $isFirst = false;
                } else {
                    $moodleAssignsStr .= ",'".$examid."'";
                }
            }

            $examsInThisMoodle = $DB->get_records_sql(" select cm,examid from {plagiarism_tomagrade_config} where examid in ($moodleAssignsStr) ");
            $isFirst = true;
            foreach ($examsInThisMoodle as $key=>$value) {
                if ($isFirst) {
                    $examsCmidsList .= "'".$value->cm."'";
                    $isFirst = false;
                } else {
                    $examsCmidsList .= ",'".$value->cm."'";
                }
                array_push($examsIDsInCurrentMoodleServer,$value->examid);
            }
        }

    
    
    
        if (empty($examsCmidsList) == false) {

                $NotRendered = $DB->execute("
    update {plagiarism_tomagrade}  set finishrender = 1 where id in (  select id from ( select student.id as id  from {plagiarism_tomagrade_config} as config
     inner join {plagiarism_tomagrade} as student on config.cm = student.cmid 
     where cmid in ($examsCmidsList) ) as x ) ");
    
            if ($NotRendered == true) {

                logAndPrint("all the exams $examsCmidsList has been synced and rendered",$log);
                
                foreach($examsIDsInCurrentMoodleServer as $exam) {
                    try {


                        $connection->checkCourse($exam);
            
                        $res = $connection->get_request("SaveDownloadDate", "/$exam");
                        $res = json_decode($res,true);
                        $result = $res['Response'];
    
                        if ($result == 'Failed') {
                            logAndPrint("error in SaveDownloadDate for exam $exam",$log);
                        }

                    } catch (Exception $e) {
                        logAndPrint('happend in checkCourse - for ' . $currentCmid . " cmid.",$log);
                        logAndPrint($e,$log);
                    }
                }
            }
        } else {
            logAndPrint("there are no exams that rendered since the last sync",$log);
        }
        // #### UPDATE RENDERING  - END

        $data = $DB->get_records_sql("
select * from {plagiarism_tomagrade_config} as config
 inner join {plagiarism_tomagrade} as student on config.cm = student.cmid 
 where complete = 0 and upload != 0 and status = 0 and updatestatus = 1 order by cmid");


    // foreach ($data as $key=>$value) {
    //     var_dump($key);
    $keys = array_keys($data);
    foreach(array_keys($keys) as $index){       
        $current_key = current($keys); // or $current_key = $keys[$index];
        $value = $data[$current_key]; // or $current_value = $a[$keys[$index]];

        $next_key = next($keys); 
        $next_value = $data[$next_key] ?? null; // for php version >= 7.0

        $sendMail = false;
        if (empty($value->share_teachers) == false) {
            if (isset($next_value) == false) {
                $sendMail = true;
            } else if ($value->cmid != $next_value->cmid) {
                $sendMail = true;
            }
        }
      



        // if ($value->status == 0 || $value->updatestatus == 1) {
        try {
            $context = context_module::instance($value->cm);
        } catch (Exception $e) {
            continue;
        }
        if (empty($context) || $context == null) {
    
            logAndPrint("context is empty.. -",$log);
            continue;
        }
        $contextid = $context->id;
        try {
            switch ($value->upload) {
                case plagiarism_plugin_tomagrade::RUN_IMMEDIATLY:
                    // mtrace("Should upload immediately.");
                    logAndPrint("Should upload immediately.",$log);
                    $tmpLog = $connection->uploadExam($contextid, $value,$sendMail);
                    logAndPrint($tmpLog,$log);
                    break;
                case plagiarism_plugin_tomagrade::RUN_MANUAL:
                    // mtrace("Should be uploaded manual.");
                    logAndPrint("Should be uploaded manual.",$log);
                    break;
                case plagiarism_plugin_tomagrade::RUN_AFTER_FIRST_DUE_DATE:
                    // mtrace("Should be uploaded at first due date.");
                    logAndPrint("Should be uploaded at first due date.",$log);
                    $checkdate = $DB->get_record("event", array('id' => $value->cmid));
                    if ($checkdate->timestart < time()) {
                        $tmpLog = $connection->uploadExam($contextid, $value,$sendMail);
                        logAndPrint($tmpLog,$log);
                    }
                    break;
            }
        } catch (Exception $e) {
            logAndPrint("Couldn't Sync Student, Exception:",$log);
            logAndPrint($e,$log);
        }
        // }
    }



    // $event = \plagiarism_tomagrade\event\assigns_syncedWithTG::create(array(
    //     'context' => context_system::instance(),
    //     'userid' => -1,
    //     'other' => $log
    // ));
    // $event->trigger();
 


    function resetforDev()
    {
        global $DB, $CFG;
        $data = $DB->get_records("plagiarism_tomagrade");
        foreach ($data as $val) {
            $newdata = new stdClass();
            $newdata->id = $val->id;
            $newdata->status = 0;
            $DB->update_record('plagiarism_tomagrade', $newdata);
        }
    }
}