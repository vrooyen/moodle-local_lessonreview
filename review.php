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
 * Displays the historical attempts and answers for a specific lesson.
 *
 * @package    local_lessonreview
 * @copyright  2026 Marinda Faasen
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$retry = optional_param('retry', 0, PARAM_INT); // Default to 0

$cm = get_coursemodule_from_id('lesson', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lesson = $DB->get_record('lesson', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

// 1. "Allow student review" must be set to 'Yes'
if (empty($lesson->review)) {
    throw new \moodle_exception('Reviewing is not permitted for this lesson. Please check the lesson settings.');
}

// 2. Evaluate Moodle's completion conditions
require_once($CFG->libdir . '/completionlib.php');
$completion = new \completion_info($course);
$iscompleted = false;

if ($completion->is_enabled($cm)) {
    $cdata = $completion->get_data($cm, false, $USER->id);
    $iscompleted = in_array($cdata->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS]);
} else {
    $iscompleted = $DB->record_exists('lesson_grades', ['lessonid' => $lesson->id, 'userid' => $USER->id]);
}

if (!$iscompleted) {
    throw new \moodle_exception('You must complete all required conditions for this lesson before accessing the review.');
}

$PAGE->set_url('/local/lessonreview/review.php', ['cmid' => $cmid, 'retry' => $retry]);
$PAGE->set_title(format_string($lesson->name) . ' - Review');
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading('Review: ' . format_string($lesson->name) . ' (Attempt ' . ($retry + 1) . ')');

// 1. Fetch all pages and answers into memory for fast mapping
$pages = $DB->get_records('lesson_pages', ['lessonid' => $lesson->id]);
$answers = $DB->get_records('lesson_answers', ['lessonid' => $lesson->id]);

$concat_attempt = $DB->sql_concat("'a_'", 'a.id');
$concat_branch  = $DB->sql_concat("'b_'", 'b.id');

$sql = "
    SELECT $concat_attempt AS uniqueid, 'attempt' AS eventtype, a.id, a.pageid, a.timeseen, a.useranswer, a.correct, a.answerid
    FROM {lesson_attempts} a
    JOIN (
        SELECT pageid, MAX(id) AS maxid
        FROM {lesson_attempts}
        WHERE lessonid = :lessonid1 AND userid = :userid1 AND retry = :retry1
        GROUP BY pageid
    ) a_max ON a.id = a_max.maxid
    
    UNION
    
    SELECT $concat_branch AS uniqueid, 'branch' AS eventtype, b.id, b.pageid, b.timeseen, NULL AS useranswer, NULL AS correct, NULL AS answerid
    FROM {lesson_branch} b
    JOIN (
        SELECT pageid, MAX(id) AS maxid
        FROM {lesson_branch}
        WHERE lessonid = :lessonid2 AND userid = :userid2 AND retry = :retry2
        GROUP BY pageid
    ) b_max ON b.id = b_max.maxid
    
    ORDER BY timeseen ASC
";

$events = $DB->get_records_sql($sql, [
    'lessonid1' => $lesson->id, 'userid1' => $USER->id, 'retry1' => $retry,
    'lessonid2' => $lesson->id, 'userid2' => $USER->id, 'retry2' => $retry
]);

if ($events) {
    foreach ($events as $event) {
        if (!isset($pages[$event->pageid])) {
            continue; // Safety check in case a page was deleted
        }
        
        $page = $pages[$event->pageid];
        
        echo html_writer::start_tag('div', ['class' => 'card mb-4']);
        echo html_writer::start_tag('div', ['class' => 'card-header']);
        echo html_writer::tag('h3', format_string($page->title), ['class' => 'h5 mb-0']);
        echo html_writer::end_tag('div');
        
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        
        // Render the core page content (applies to both questions and content pages)
    // Rewrite placeholders to actual URLs for embedded videos and images
$rewrittencontents = file_rewrite_pluginfile_urls(
    $page->contents,
    'pluginfile.php',
    $context->id,
    'mod_lesson',
    'page_contents',
    $page->id
);

// Format the rewritten text using the page's specific format
echo html_writer::tag('div', format_text($rewrittencontents, $page->contentsformat), ['class' => 'mb-3']);
        // 3. Skip evaluation logic if it is a Content Page (qtype 20)
        if ($event->eventtype === 'branch' || $page->qtype == 20) {
            // It's a content page, do nothing else.
        } else {
            // It is a Question page, process the answer
            $useranswertext = '';
            
       // Short Answer (1) and Numerical (8) store raw text
            if (in_array($page->qtype, [1, 8])) {
                $useranswertext = s($event->useranswer);
            } 
            // Essay (10) stores a serialized PHP object
            elseif ($page->qtype == 10) {
                $essayinfo = @unserialize($event->useranswer);
                
                if ($essayinfo !== false && isset($essayinfo->answer)) {
                    // 1. Extract and format the student's essay text
                    $useranswertext = format_text($essayinfo->answer, $essayinfo->answerformat);
                    
                    // 2. If it has been graded, append the teacher's feedback
                    if (!empty($essayinfo->graded) && !empty($essayinfo->response)) {
                        $useranswertext .= html_writer::start_tag('div', ['class' => 'alert alert-info mt-3 p-2']);
                        $useranswertext .= html_writer::tag('strong', 'Instructor Feedback:') . '<br>';
                        $useranswertext .= format_text($essayinfo->response, $essayinfo->responseformat);
                        $useranswertext .= html_writer::end_tag('div');
                    }
                } else {
                    $useranswertext = '<em>Error parsing essay data.</em>';
                }
            }
            // Multichoice Multi-select (3 with option 1) stores comma-separated IDs
            elseif ($page->qtype == 3 && $page->qoption == 1) {
                $selectedids = explode(',', $event->useranswer);
                $texts = [];
                foreach ($selectedids as $sid) {
                    if (isset($answers[$sid])) {
                        $texts[] = '• ' . format_string($answers[$sid]->answer);
                    }
                }
                $useranswertext = implode('<br>', $texts);
            } 
            // Matching (5) stores comma-separated IDs in the response column
      // Matching (5) stores comma-separated IDs mapped by chronological order
            elseif ($page->qtype == 5) {
                $selectedvals = explode(',', $event->useranswer);
                $texts = [];
                
                // Get all answers for this page to reconstruct the Question -> Match pairs
                $pageanswers = $DB->get_records('lesson_answers', ['pageid' => $page->id], 'id ASC');
                
                // In Lesson matching, the first 2 records are generic Correct/Wrong jumps. 
                // The actual pairs start at the 3rd record.
                $matchingpairs = array_slice($pageanswers, 2);
                
                $i = 0;
                foreach ($matchingpairs as $pair) {
                    // Skip extra wrong answers (distractors) which have no question text
                    if (trim($pair->answer) === '') {
                        continue;
                    }
                    
                    $val = isset($selectedvals[$i]) ? trim($selectedvals[$i]) : '';
                    $selectedtext = $val; // Fallback to raw value
                    
                    // If the stored value is an ID, fetch the matching response text
                    if (is_numeric($val) && isset($answers[$val])) {
                        $selectedtext = $answers[$val]->response;
                    }
                    
                    $texts[] = '• <strong>' . format_string($pair->answer) . ':</strong> ' . format_string($selectedtext);
                    $i++;
                }
                $useranswertext = implode('<br>', $texts);
            } 
            // Standard True/False or Single Choice
            else {
                if (!empty($event->answerid) && isset($answers[$event->answerid])) {
                    $useranswertext = format_string($answers[$event->answerid]->answer);
                }
            }
            
            if (empty($useranswertext)) {
                $useranswertext = '<em>No answer recorded</em>';
            }
            
            $statusclass = $event->correct ? 'alert-success' : 'alert-danger';
            $statustext = $event->correct ? 'Correct' : 'Incorrect';
            
            echo html_writer::tag('p', '<strong>Your Answer:</strong><br>' . $useranswertext);
            echo html_writer::tag('div', $statustext, ['class' => "alert $statusclass py-1 px-2 d-inline-block"]);
        }
        
        echo html_writer::end_tag('div'); // close card-body
        echo html_writer::end_tag('div'); // close card
    }
} else {

    // Edge Case: The user triggered completion (e.g., via 'View' only) but clicked no buttons, 
    // so no database records were created. We know they at least saw the very first page.
    $firstpage = $DB->get_record('lesson_pages', ['lessonid' => $lesson->id, 'prevpageid' => 0]);
    
    if ($firstpage) {
        echo html_writer::tag('div', '<strong>Note:</strong> You viewed this lesson but did not navigate through any internal buttons, so only the introductory page was recorded.', ['class' => 'alert alert-warning mt-3 mb-4']);
        
        echo html_writer::start_tag('div', ['class' => 'card mb-4']);
        echo html_writer::start_tag('div', ['class' => 'card-header']);
        echo html_writer::tag('h3', format_string($firstpage->title), ['class' => 'h5 mb-0']);
        echo html_writer::end_tag('div');
        
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        
        $rewrittencontents = file_rewrite_pluginfile_urls(
            $firstpage->contents,
            'pluginfile.php',
            $context->id,
            'mod_lesson',
            'page_contents',
            $firstpage->id
        );
        echo html_writer::tag('div', format_text($rewrittencontents, $firstpage->contentsformat), ['class' => 'mb-3']);
        
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    } else {
        echo html_writer::tag('p', 'No attempt data found for this lesson.');
    }
}

echo $OUTPUT->footer();