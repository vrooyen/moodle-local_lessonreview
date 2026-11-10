# Local Lesson Review

A local plugin for Moodle that injects a dynamic "Review" button into the Lesson module, allowing students to review their completed attempts, including their answers and instructor feedback.

## Features
* Automatically injects a review button on completed lessons.
* Supports multiple lesson attempts, generating distinct review buttons for each completed attempt.
* Hides review buttons while a student is in the middle of an active attempt.
* Reconstructs the exact chronological path taken through branching lessons.
* Review button is generated if the Lesson is set to "Allow student review" and any set Activity Completion settings are met.

## Requirements
* Moodle 5.0 or later (Tested on Moodle 5.2).

## Installation
1. Clone or download this repository.
2. Extract the folder into your Moodle installation at `/local/lessonreview`.
3. Log in as an administrator and complete the installation process, or run `php admin/cli/upgrade.php`.

## Usage
To enable the review button for a specific lesson:
1. Edit the Lesson activity settings.
2. Expand the **Flow control** section.
3. Set **Allow student review** to **Yes**.
4. Ensure the student has met the activity completion criteria.

## License
This plugin is licensed under the [GNU General Public License v3.0](http://www.gnu.org/copyleft/gpl.html).