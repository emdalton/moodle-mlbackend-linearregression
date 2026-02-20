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
 * Strings for component 'local_coursefinalgrade'
 *
 * @package   local_coursefinalgrade
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Course final grade prediction';

// Target strings.
$string['target:coursefinalgrade']      = 'Predicted course final grade';
$string['target:coursefinalgrade_help'] = 'Predicts each enrolled student\'s final grade in a course as a percentage (0–100), based on their engagement and activity patterns during the course. Requires the linear regression ML backend.';

// Insight strings.
$string['studentfinalgradeprediction']  = 'Predicted final grade for students in {$a}';
$string['finalgradepredictionmessage']  = 'Based on current engagement in {$a->coursename}, {$a->userfirstname}\'s predicted final grade has been calculated.';

// Validation strings.
$string['nocoursegrades']    = 'This course has no grade items configured.';
$string['nocourseactivity']  = 'Not enough student activity to generate a reliable prediction.';

// Privacy.
$string['privacy:metadata'] = 'The course final grade prediction plugin does not store any personal data.';
