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
 * Strings for component 'mlbackend_linearregression'
 *
 * @package   mlbackend_linearregression
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['errorcantloadmodel'] = 'Model file {$a} does not exist. The model must be trained before it can be used to make predictions.';
$string['errorlowscore'] = 'The evaluated model prediction accuracy is not very high, so some predictions may not be accurate. Model R² score = {$a->score}, minimum score = {$a->minscore}';
$string['errornotenoughdata'] = 'There is not enough data to evaluate this model using the provided analysis interval.';
$string['errornotenoughdatadev'] = 'The evaluation results varied too much. It is recommended that more data is gathered to ensure the model is valid. Evaluation results standard deviation = {$a->deviation}, maximum recommended standard deviation = {$a->accepteddeviation}';
$string['pluginname'] = 'Linear regression machine learning backend';
$string['privacy:metadata'] = 'The linear regression machine learning backend plugin does not store any personal data.';
$string['trainingsamplelimited'] = 'Training was limited to the {$a->max} most recent enrolment records out of {$a->total} available. To adjust this limit, set $CFG->mlbackend_linearregression_max_training_samples in config.php. Set to 0 to disable the limit (not recommended on large sites).';
