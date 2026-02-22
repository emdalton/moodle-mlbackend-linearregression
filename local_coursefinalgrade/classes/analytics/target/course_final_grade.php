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
 * Course final grade prediction target.
 *
 * @package   local_coursefinalgrade
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursefinalgrade\analytics\target;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/gradelib.php');

/**
 * Course final grade prediction target.
 *
 * Predicts each enrolled student's final course grade as a continuous value
 * between 0 and 100 (percentage). Extends the core linear target base class
 * and uses the student enrolments analyser, consistent with other course-level
 * targets such as course_dropout and course_completion.
 *
 * This target requires the mlbackend_linearregression plugin, as the built-in
 * PHP and Python backends do not support linear (regression) targets.
 *
 * Unlike binary risk targets (e.g. course_dropout) which surface only at-risk
 * students, this target surfaces predictions for ALL enrolled students. The intent
 * is to give teachers a full picture of grade trajectories — encouraging students
 * who are performing well and offering support to those who may need it. The
 * callback boundary is therefore used purely as a visual indicator in the UI
 * (distinguishing positive from negative outcomes) rather than as a suppression
 * filter. See triggers_callback().
 *
 * @package   local_coursefinalgrade
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_final_grade extends \core_course\analytics\target\course_enrolments {

    /**
     * The minimum possible grade value (percentage).
     */
    const MIN_GRADE = 0.0;

    /**
     * The maximum possible grade value (percentage).
     */
    const MAX_GRADE = 100.0;

    /**
     * Default callback boundary — predictions below this value trigger an insight.
     * Used when the course does not have an explicit passing grade configured.
     */
    const DEFAULT_BOUNDARY = 50.0;

    /**
     * Returns the name of this target.
     *
     * @return \lang_string
     */
    public static function get_name(): \lang_string {
        return new \lang_string('target:coursefinalgrade', 'local_coursefinalgrade');
    }

    /**
     * Declares this target as linear (regression), not binary (classification).
     *
     * Overrides the coding_exception thrown by the parent linear base class.
     * Returning true here causes the analytics engine to route this target
     * through train_regression(), estimate(), and evaluate_regression() on
     * the ML backend rather than the classification methods.
     *
     * @return bool
     */
    public function is_linear() {
        return true;
    }

    /**
     * Returns the maximum value this target can return.
     *
     * @return float
     */
    public static function get_max_value() {
        return self::MAX_GRADE;
    }

    /**
     * Returns the minimum value this target can return.
     *
     * @return float
     */
    public static function get_min_value() {
        return self::MIN_GRADE;
    }

    /**
     * Returns the grade boundary used as a visual indicator in the insights UI.
     *
     * Because this target overrides triggers_callback() to surface all student
     * predictions, this boundary does NOT suppress any insights. It is used
     * exclusively by get_calculation_outcome() to determine the visual treatment
     * of each prediction — students predicted below this value are shown as
     * needing attention (OUTCOME_VERY_NEGATIVE) and those above it are shown
     * positively (OUTCOME_VERY_POSITIVE).
     *
     * Where the course total grade item has a passing grade configured
     * (grade_item::gradepass), that value is normalised to a percentage and used.
     * Falls back to DEFAULT_BOUNDARY (50.0%) when no passing grade is configured.
     *
     * @return float
     */
    protected function get_callback_boundary() {
        // Attempt to read the passing grade from the course total grade item.
        // This is only available during a prediction run when $this->students is
        // populated, which means get_analysable() has already been called.
        if (!empty($this->students)) {
            // We need the course id — retrieve it from any student's enrolment context.
            // The analyser sets the analysable before calculate_sample() is called,
            // but get_callback_boundary() may be called earlier during insight display.
            // Defensive: only attempt grade item lookup if we have a course context.
            $courseid = $this->get_course_id_from_context();
            if ($courseid) {
                $gradeitem = \grade_item::fetch(['courseid' => $courseid, 'itemtype' => 'course']);
                if ($gradeitem && !empty($gradeitem->gradepass) && floatval($gradeitem->gradepass) > 0) {
                    $grademax = floatval($gradeitem->grademax);
                    $grademin = floatval($gradeitem->grademin);
                    if ($grademax > $grademin) {
                        // Normalise gradepass to percentage using Moodle's standardise_score(),
                        // consistent with calculate_sample(). The $grademax > $grademin guard
                        // ensures we don't hit standardise_score()'s degenerate-range fallback.
                        $boundary = \grade_grade::standardise_score(
                            floatval($gradeitem->gradepass),
                            $grademin,
                            $grademax,
                            self::MIN_GRADE,
                            self::MAX_GRADE
                        );
                        return max(self::MIN_GRADE, min(self::MAX_GRADE, $boundary));
                    }
                }
            }
        }
        return self::DEFAULT_BOUNDARY;
    }

    /**
     * How positive is this predicted value?
     *
     * Overrides the generic parent implementation to provide grade-appropriate
     * outcome labels. A predicted grade at or above the callback boundary is
     * considered positive; below it is negative.
     *
     * @param float $value The predicted grade value
     * @param string $ignoredsubtype
     * @return int One of self::OUTCOME_VERY_POSITIVE or self::OUTCOME_VERY_NEGATIVE
     */
    public function get_calculation_outcome($value, $ignoredsubtype = false) {
        if (floatval($value) >= $this->get_callback_boundary()) {
            return self::OUTCOME_VERY_POSITIVE;
        }
        return self::OUTCOME_VERY_NEGATIVE;
    }

    /**
     * All student predictions are surfaced as insights, regardless of predicted grade.
     *
     * Overrides the parent implementation which would suppress predictions above
     * the callback boundary. This target has a different philosophy from binary
     * risk targets: rather than flagging only at-risk students, it gives teachers
     * a full view of predicted grade trajectories across all enrolled students,
     * enabling both encouragement of high performers and support for those at risk.
     *
     * The boundary is still meaningful — it drives get_calculation_outcome() which
     * controls the visual treatment (positive vs. negative) of each insight in the UI.
     *
     * @param mixed $predictedvalue The predicted grade value
     * @param float $predictionscore The model's confidence score
     * @return bool Always true — all predictions trigger an insight.
     */
    public function triggers_callback($predictedvalue, $predictionscore) {
        return true;
    }

    /**
     * Validates that the course is suitable for training or prediction.
     *
     * Extends the parent course_enrolments validation to additionally require
     * that the course has at least one grade item configured, since without
     * grades there is nothing to train or predict against.
     *
     * @param \core_analytics\analysable $course
     * @param bool $fortraining
     * @return true|string True if valid, or a string describing why it is not.
     */
    public function is_valid_analysable(\core_analytics\analysable $course, $fortraining = true) {
        $isvalid = parent::is_valid_analysable($course, $fortraining);

        if (is_string($isvalid)) {
            return $isvalid;
        }

        // Require at least one grade item in the course.
        $gradeitems = \grade_item::fetch_all(['courseid' => $course->get_id(), 'itemtype' => 'course']);
        if (empty($gradeitems)) {
            return get_string('nocoursegrades', 'local_coursefinalgrade');
        }

        if ($fortraining) {
            // For training, require minimum student activity (same threshold as course_dropout).
            if (!$logstore = \core_analytics\manager::get_analytics_logstore()) {
                throw new \coding_exception('No available log stores');
            }

            global $DB;
            $params = [
                'courseid'  => $course->get_id(),
                'anonymous' => 0,
                'start'     => $course->get_start(),
                'end'       => $course->get_end(),
            ];
            [$studentssql, $studentparams] = $DB->get_in_or_equal($this->students, SQL_PARAMS_NAMED, 'student');
            $select = 'courseid = :courseid AND anonymous = :anonymous AND timecreated > :start ' .
                      'AND timecreated < :end AND userid ' . $studentssql;

            $nlogs    = $logstore->get_events_select_count($select, array_merge($params, $studentparams));
            $nstudents = count($this->students);

            if ($nstudents > 0 && ($nlogs / $nstudents) < 10) {
                return get_string('nocourseactivity', 'local_coursefinalgrade');
            }
        }

        return true;
    }

    /**
     * Calculates the target value for a single sample (student enrolment).
     *
     * Returns the student's final course grade as a percentage (0–100), or
     * null if the grade is not yet available or the enrolment was not active
     * during the analysis time window.
     *
     * During training, this is the known historical final grade that the model
     * learns to predict. During prediction, calculate_sample() is not called —
     * the ML backend generates predicted values instead.
     *
     * @param int $sampleid The user_enrolments record id
     * @param \core_analytics\analysable $course
     * @param int|false $starttime Analysis interval start (unix timestamp)
     * @param int|false $endtime   Analysis interval end (unix timestamp)
     * @return float|null Grade percentage (0–100), or null if unavailable/invalid
     */
    protected function calculate_sample($sampleid, \core_analytics\analysable $course,
            $starttime = false, $endtime = false) {

        if (!$this->enrolment_active_during_analysis_time($sampleid, $starttime, $endtime)) {
            return null;
        }

        $userenrol = $this->retrieve('user_enrolments', $sampleid);

        // Fetch the course-level grade item.
        $gradeitem = \grade_item::fetch([
            'courseid' => $course->get_id(),
            'itemtype' => 'course',
        ]);

        if (!$gradeitem) {
            return null;
        }

        // Fetch the student's grade for this item.
        $gradegrade = \grade_grade::fetch([
            'itemid' => $gradeitem->id,
            'userid' => $userenrol->userid,
        ]);

        if (!$gradegrade || is_null($gradegrade->finalgrade)) {
            // Grade not yet assigned — not usable for training.
            return null;
        }

        // Normalise to a 0–100 percentage regardless of the grade item's max.
        $grademax = floatval($gradeitem->grademax);
        $grademin = floatval($gradeitem->grademin);

        if ($grademax <= $grademin) {
            return null;
        }

        // Normalise using Moodle's grade_grade::standardise_score() which handles
        // division by zero and null input. We pass target_min=0, target_max=100
        // to get a direct percentage. The $grademax > $grademin guard above means
        // we will not hit standardise_score()'s degenerate-range fallback.
        $percentage = \grade_grade::standardise_score(
            floatval($gradegrade->finalgrade),
            $grademin,
            $grademax,
            self::MIN_GRADE,
            self::MAX_GRADE
        );

        // Clamp to [0, 100] to guard against any out-of-range grade values.
        return max(self::MIN_GRADE, min(self::MAX_GRADE, $percentage));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Attempts to determine the course id from the current context.
     *
     * Used by get_callback_boundary() to look up the grade item's passing grade.
     * Returns null if the course id cannot be determined safely.
     *
     * @return int|null
     */
    private function get_course_id_from_context(): ?int {
        if (empty($this->students)) {
            return null;
        }
        global $DB;
        $userid = reset($this->students);
        $enrol = $DB->get_record_sql(
            'SELECT e.courseid FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid
           ORDER BY ue.timecreated DESC',
            ['userid' => $userid],
            IGNORE_MULTIPLE
        );
        return $enrol ? (int) $enrol->courseid : null;
    }
}
