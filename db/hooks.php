<?php
// public/local/lessonreview/db/hooks.php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => 'local_lessonreview\hooks::inject_review_button',
    ],
];