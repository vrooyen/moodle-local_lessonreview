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
 * Version details.
 *
 * @package    local_lessonreview
 * @copyright  2026 Marinda Faasen <faasenmarinda@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lessonreview;

defined('MOODLE_INTERNAL') || die();
/**
     * Hook callbacks for the local_lessonreview plugin.
     * 
     * This class contains the callback methods required to inject 
     * custom UI elements into the Moodle core rendering pipeline.
     */

class hooks {
    /**
         * Injects the lesson review button into the page.
         * 
         * Evaluates the current page context, lesson settings, and the student's 
         * completion status to dynamically render historical attempt review buttons.
         *
         * @param \core\hook\output\before_standard_head_html_generation $hook The hook execution object.
         * @return void
         */
    
    public static function inject_review_button(\core\hook\output\before_standard_head_html_generation $hook) {
        global $PAGE, $USER, $DB, $CFG;

        if ($PAGE->pagetype === 'mod-lesson-view') {
            
            $cm = $PAGE->cm;
            if (!$cm) { return; }
            
            $context = \context_module::instance($cm->id);
            $course = $DB->get_record('course', ['id' => $cm->course]);
            $lesson = $DB->get_record('lesson', ['id' => $cm->instance]);

            if (empty($lesson->review)) { return; }

            require_once($CFG->libdir . '/completionlib.php');
            $completion = new \completion_info($course);
            $iscompleted = false;

            if ($completion->is_enabled($cm)) {
                $cdata = $completion->get_data($cm, false, $USER->id);
                $iscompleted = in_array($cdata->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS]);
            } else {
                $hasgrade = $DB->record_exists('lesson_grades', ['lessonid' => $lesson->id, 'userid' => $USER->id]);
                $hastimer = $DB->record_exists('lesson_timer', ['lessonid' => $lesson->id, 'userid' => $USER->id, 'completed' => 1]);
                $hasbranch = $DB->record_exists('lesson_branch', ['lessonid' => $lesson->id, 'userid' => $USER->id]);
                $iscompleted = $hasgrade || $hastimer || $hasbranch;
            }

            if (!$iscompleted || !has_capability('mod/lesson:view', $context)) {
                return;
            }

            // 1. Calculate how many attempts the student has definitively finished
            $grades_count = $DB->count_records('lesson_grades', ['lessonid' => $lesson->id, 'userid' => $USER->id]);
            $timers_count = $DB->count_records('lesson_timer', ['lessonid' => $lesson->id, 'userid' => $USER->id, 'completed' => 1]);
            $completed_count = max($grades_count, $timers_count);
            
            // Fallback: If it's a branching lesson with no grades/timers, but marked complete, they finished at least 1.
            if ($completed_count == 0 && $iscompleted) { 
                $completed_count = 1; 
            }

            // 2. Find all distinct retries started in the logs (0-indexed)
            $retries_sql = "
                SELECT DISTINCT retry FROM {lesson_attempts} WHERE lessonid = :lid1 AND userid = :uid1
                UNION
                SELECT DISTINCT retry FROM {lesson_branch} WHERE lessonid = :lid2 AND userid = :uid2
                ORDER BY retry ASC
            ";
            
            $retries = $DB->get_fieldset_sql($retries_sql, [
                'lid1' => $lesson->id, 'uid1' => $USER->id,
                'lid2' => $lesson->id, 'uid2' => $USER->id
            ]);

            if (empty($retries)) { $retries = [0]; }
            
            // 3. Check for an active, unfinished attempt
            $max_retry = max($retries);
            
     // If the highest retry index is >= the number of completed attempts, they are busy taking it!
        if ($max_retry >= $completed_count) {
            $attemptnum = $max_retry + 1;
            $logmessage = get_string('reviewactiveattempt', 'local_lessonreview', $attemptnum);
            $PAGE->requires->js_amd_inline("console.info(" . json_encode($logmessage) . ");");
            return;
        }
            
         

            $string_review = get_string('review', 'local_lessonreview');
            $buttons_js = "";

            foreach ($retries as $retry) {
                // Safety check to only render buttons for completed attempts
                if ($retry < $completed_count) {
                    $attemptnum = $retry + 1; 
                    $url = new \moodle_url('/local/lessonreview/review.php', ['cmid' => $cm->id, 'retry' => $retry]);
                    $btn_text = addslashes($string_review . " (Attempt " . $attemptnum . ")");
                    
                    $buttons_js .= "
                        const btn{$retry} = document.createElement('a');
                        btn{$retry}.href = '" . $url->out(false) . "';
                        btn{$retry}.className = 'btn btn-primary btn-custom-lesson-review';
                        btn{$retry}.style.marginRight = '10px';
                        btn{$retry}.style.marginBottom = '10px';
                        btn{$retry}.textContent = '{$btn_text}';
                        btnContainer.appendChild(btn{$retry});
                    ";
                }
            }
            
            if (empty($buttons_js)) {
                return;
            }
            
            $js = "
            require([], function() {
                const targetArea = document.querySelector('[role=\"main\"]') || document.querySelector('#region-main');
                
                if (targetArea && !document.querySelector('.btn-custom-lesson-review')) {
                    const btnContainer = document.createElement('div');
                    btnContainer.className = 'custom-lesson-review-container mt-4 mb-4 d-flex flex-wrap';
                    
                    $buttons_js
                    
                    targetArea.insertBefore(btnContainer, targetArea.firstChild);
                }
            });
            ";
            
            $PAGE->requires->js_amd_inline($js);
        }
    }
}