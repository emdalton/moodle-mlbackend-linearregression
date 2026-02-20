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
 * Analytics models provided by local_coursefinalgrade.
 *
 * This file registers the default prediction model for course final grade.
 * The model uses the linear regression backend (mlbackend_linearregression)
 * and the student enrolments analyser with a quarters time splitting method.
 *
 * Indicators listed here are the same core indicators used by the dropout
 * model, as engagement patterns are strongly correlated with final grade.
 * Additional or alternative indicators can be configured by site administrators
 * through the Analytics UI after installation.
 *
 * @package   local_coursefinalgrade
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$models = [
    [
        'target'     => '\local_coursefinalgrade\analytics\target\course_final_grade',
        'indicators' => [
            '\core\analytics\indicator\any_access_after_end',
            '\core\analytics\indicator\any_access_before_start',
            '\core\analytics\indicator\any_write_action',
            '\core\analytics\indicator\any_write_action_in_course',
            '\core\analytics\indicator\cognitive_depth',
            '\core\analytics\indicator\social_breadth',
            '\core_course\analytics\indicator\completion_enabled',
            '\core_course\analytics\indicator\potential_cognitive_depth_level',
            '\core_course\analytics\indicator\potential_social_breadth_level',
        ],
        'timesplitting' => '\core\analytics\time_splitting\quarters',
        'enabled'       => false, // Site admin must review and enable after installation.
    ],
];
