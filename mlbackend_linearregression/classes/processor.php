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
 * Linear regression predictions processor.
 *
 * @package   mlbackend_linearregression
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mlbackend_linearregression;

defined('MOODLE_INTERNAL') || die();

// Use the LeastSquares implementation already bundled with Moodle's PHP ML backend.
// This avoids any external dependencies.
require_once($CFG->dirroot . '/lib/mlbackend/php/phpml/src/Phpml/Regression/LeastSquares.php');
require_once($CFG->dirroot . '/lib/mlbackend/php/phpml/src/Phpml/Regression/Regression.php');
require_once($CFG->dirroot . '/lib/mlbackend/php/phpml/src/Phpml/Math/Matrix.php');
require_once($CFG->dirroot . '/lib/mlbackend/php/phpml/src/Phpml/Math/LinearAlgebra/LUDecomposition.php');
require_once($CFG->dirroot . '/lib/mlbackend/php/phpml/src/Phpml/Helper/Predictable.php');

use Phpml\Regression\LeastSquares;
use Phpml\Math\Statistic\Mean;
use Phpml\Math\Statistic\StandardDeviation;

/**
 * Linear regression predictions processor.
 *
 * This backend implements the \core_analytics\regressor interface using Ordinary
 * Least Squares (OLS) linear regression. It uses the LeastSquares class already
 * bundled with Moodle's mlbackend_php plugin (phpml library) and is designed to
 * work with linear targets such as course final grade prediction.
 *
 * Unlike the PHP backend's classification methods which support batched/partial
 * training, OLS requires the full dataset to solve the normal equations, so the
 * entire dataset is loaded into memory during training. To protect against memory
 * exhaustion on large sites, training is capped at MAX_TRAINING_SAMPLES records,
 * keeping the most recent completed enrolments (which best reflect current course
 * structure and grading behaviour). This cap can be tuned via
 * $CFG->mlbackend_linearregression_max_training_samples, or set to 0 to disable.
 *
 * Classification methods (train_classification, classify, evaluate_classification)
 * are not supported by this backend and will throw a coding_exception if called.
 * Use mlbackend_php for binary classification targets.
 *
 * @package   mlbackend_linearregression
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class processor implements \core_analytics\regressor, \core_analytics\packable {

    /**
     * File name of the serialised model.
     */
    const MODEL_FILENAME = 'model.ser';

    /**
     * Default maximum number of training samples to load into memory at once.
     *
     * OLS requires the full dataset in memory simultaneously (unlike logistic
     * regression which supports incremental/partial training). On large sites
     * with many enrolments this can exhaust available memory.
     *
     * This limit caps training to the N most recent completed enrolment records,
     * which are the most relevant for predicting current student outcomes.
     *
     * Can be overridden per-site via $CFG->mlbackend_linearregression_max_training_samples.
     * Set to 0 to disable the limit entirely (not recommended on large sites).
     */
    const MAX_TRAINING_SAMPLES = 20000;

    /**
     * Checks if the processor is ready to use.
     *
     * @return true|string True if ready, or an error string if not.
     */
    public function is_ready() {
        if (version_compare(phpversion(), '7.0.0') < 0) {
            return get_string('errorphp7required', 'mlbackend_php');
        }
        return true;
    }

    /**
     * Delete the stored model files.
     *
     * @param string $uniqueid
     * @param string $modelversionoutputdir
     * @return null
     */
    public function clear_model($uniqueid, $modelversionoutputdir) {
        remove_dir($modelversionoutputdir);
    }

    /**
     * Delete the output directory.
     *
     * @param string $modeloutputdir
     * @param string $uniqueid
     * @return null
     */
    public function delete_output_dir($modeloutputdir, $uniqueid) {
        remove_dir($modeloutputdir);
    }

    /**
     * Train this processor regression model using the provided supervised learning dataset.
     *
     * Loads the full dataset into memory and fits an OLS linear regression model.
     * The trained model is serialised to disk for later use by estimate().
     *
     * Note: Unlike the PHP classification backend, OLS cannot be trained incrementally.
     * Each call to train_regression() retrains the model from scratch using all
     * available data.
     *
     * @param string $uniqueid
     * @param \stored_file $dataset
     * @param string $outputdir
     * @return \stdClass
     */
    public function train_regression($uniqueid, \stored_file $dataset, $outputdir) {

        $fh = $dataset->get_content_file_handle();

        $metadata = $this->extract_metadata($fh);

        // Skip headers.
        fgets($fh);

        $samples = [];
        $targets = [];
        while (($data = fgetcsv($fh)) !== false) {
            $sampledata = array_map('floatval', $data);
            $samples[] = array_slice($sampledata, 0, $metadata['nfeatures']);
            $targets[] = floatval($data[$metadata['nfeatures']]);
        }
        fclose($fh);

        if (count($samples) < 2) {
            $resultobj = new \stdClass();
            $resultobj->status = \core_analytics\model::NO_DATASET;
            $resultobj->info = [];
            return $resultobj;
        }

        // Cap training samples to protect against memory exhaustion on large sites.
        // We keep the most recent N records since these best reflect current course
        // structure and grading behaviour. Site admins can tune this via
        // $CFG->mlbackend_linearregression_max_training_samples, or set to 0 to disable.
        $totalsamples = count($samples);
        $maxsamples = isset($CFG->mlbackend_linearregression_max_training_samples)
            ? (int) $CFG->mlbackend_linearregression_max_training_samples
            : self::MAX_TRAINING_SAMPLES;

        $limited = false;
        if ($maxsamples > 0 && $totalsamples > $maxsamples) {
            // array_slice from the end to get the most recent records.
            $samples = array_slice($samples, -$maxsamples);
            $targets = array_slice($targets, -$maxsamples);
            $limited = true;
        }

        $regressor = new LeastSquares();
        $regressor->train($samples, $targets);

        // Serialise and store the trained model.
        $modelfilepath = $this->get_model_filepath($outputdir);
        file_put_contents($modelfilepath, serialize($regressor));

        $resultobj = new \stdClass();
        $resultobj->status = \core_analytics\model::OK;
        $resultobj->info = [];

        if ($limited) {
            $a = new \stdClass();
            $a->max   = $maxsamples;
            $a->total = $totalsamples;
            $resultobj->info[] = get_string('trainingsamplelimited', 'mlbackend_linearregression', $a);
        }

        return $resultobj;
    }

    /**
     * Estimates linear values for the provided dataset samples.
     *
     * Loads the serialised model and generates a continuous predicted value
     * (e.g. a grade between 0 and 100) for each sample in the dataset.
     *
     * @param string $uniqueid
     * @param \stored_file $dataset
     * @param string $outputdir
     * @return \stdClass
     */
    public function estimate($uniqueid, \stored_file $dataset, $outputdir) {

        $regressor = $this->load_regressor($outputdir);

        $fh = $dataset->get_content_file_handle();

        $metadata = $this->extract_metadata($fh);

        // Skip headers.
        fgets($fh);

        $sampleids = [];
        $samples = [];
        while (($data = fgetcsv($fh)) !== false) {
            $sampledata = array_map('floatval', $data);
            $sampleids[] = $data[0];
            $samples[] = array_slice($sampledata, 1, $metadata['nfeatures']);
        }
        fclose($fh);

        $resultobj = new \stdClass();
        $resultobj->status = \core_analytics\model::OK;
        $resultobj->info = [];
        $resultobj->predictions = [];

        foreach ($samples as $index => $sample) {
            $predicted = $regressor->predict($sample);
            $resultobj->predictions[$index] = [$sampleids[$index], $predicted];
        }

        return $resultobj;
    }

    /**
     * Evaluates this processor regression model using the provided supervised learning dataset.
     *
     * Uses R² (coefficient of determination) as the evaluation metric, which measures
     * what proportion of variance in the target variable is explained by the model.
     * R² of 1.0 is perfect prediction; 0.0 means the model is no better than
     * predicting the mean; negative values indicate the model performs worse than
     * a mean predictor.
     *
     * The evaluation is repeated $niterations times with random train/test splits
     * to check for result stability, consistent with the approach used by mlbackend_php.
     *
     * @param string $uniqueid
     * @param float $maxdeviation
     * @param int $niterations
     * @param \stored_file $dataset
     * @param string $outputdir
     * @param string $trainedmodeldir
     * @return \stdClass
     */
    public function evaluate_regression($uniqueid, $maxdeviation, $niterations, \stored_file $dataset,
            $outputdir, $trainedmodeldir) {

        $fh = $dataset->get_content_file_handle();

        $metadata = $this->extract_metadata($fh);

        // Skip headers.
        fgets($fh);

        $samples = [];
        $targets = [];
        while (($data = fgetcsv($fh)) !== false) {
            $sampledata = array_map('floatval', $data);
            $samples[] = array_slice($sampledata, 0, $metadata['nfeatures']);
            $targets[] = floatval($data[$metadata['nfeatures']]);
        }
        fclose($fh);

        if (count($samples) < 4) {
            $resultobj = new \stdClass();
            $resultobj->status = \core_analytics\model::NOT_ENOUGH_DATA;
            $resultobj->score = 0;
            $resultobj->info = [get_string('errornotenoughdata', 'mlbackend_linearregression')];
            return $resultobj;
        }

        if ($trainedmodeldir) {
            // Evaluate against a single pre-trained model.
            $niterations = 1;
            $regressor = $this->load_regressor($trainedmodeldir);
            $predicted = array_map([$regressor, 'predict'], $samples);
            $scores = [$this->r_squared($targets, $predicted)];
        } else {
            $scores = [];
            for ($i = 0; $i < $niterations; $i++) {
                // Random 80/20 train/test split.
                [$trainsamples, $traintargets, $testsamples, $testtargets] =
                    $this->random_split($samples, $targets, 0.2);

                $regressor = new LeastSquares();
                $regressor->train($trainsamples, $traintargets);

                $predicted = array_map([$regressor, 'predict'], $testsamples);
                $scores[] = $this->r_squared($testtargets, $predicted);
            }
        }

        return $this->get_evaluation_result_object($scores, $maxdeviation);
    }

    /**
     * Exports the machine learning model.
     *
     * @param string $uniqueid The model unique id
     * @param string $modeldir The directory that contains the trained model.
     * @return string The path to the directory that contains the exported model.
     */
    public function export(string $uniqueid, string $modeldir): string {
        $modelfilepath = $this->get_model_filepath($modeldir);

        if (!file_exists($modelfilepath)) {
            throw new \moodle_exception('errorexportmodelresult', 'analytics');
        }

        return $modeldir;
    }

    /**
     * Imports the provided machine learning model.
     *
     * @param string $uniqueid The model unique id
     * @param string $modeldir The directory that will contain the trained model.
     * @param string $importdir The directory that contains the files to import.
     * @return bool Success
     */
    public function import(string $uniqueid, string $modeldir, string $importdir): bool {
        $importmodelfilepath = $this->get_model_filepath($importdir);
        $modelfilepath = $this->get_model_filepath($modeldir);

        if (!file_exists($importmodelfilepath)) {
            return false;
        }

        $importdata = file_get_contents($importmodelfilepath);
        $object = @unserialize($importdata, ['allowed_classes' => [LeastSquares::class]]);

        if (!$object || get_class($object) === '__PHP_Incomplete_Class') {
            return false;
        }

        file_put_contents($modelfilepath, $importdata);

        return true;
    }

    /**
     * This backend only supports regression. Classification is not supported.
     *
     * @throws \coding_exception
     */
    public function train_classification($uniqueid, \stored_file $dataset, $outputdir) {
        throw new \coding_exception('mlbackend_linearregression does not support classification. Use mlbackend_php.');
    }

    /**
     * This backend only supports regression. Classification is not supported.
     *
     * @throws \coding_exception
     */
    public function classify($uniqueid, \stored_file $dataset, $outputdir) {
        throw new \coding_exception('mlbackend_linearregression does not support classification. Use mlbackend_php.');
    }

    /**
     * This backend only supports regression. Classification is not supported.
     *
     * @throws \coding_exception
     */
    public function evaluate_classification($uniqueid, $maxdeviation, $niterations, \stored_file $dataset,
            $outputdir, $trainedmodeldir) {
        throw new \coding_exception('mlbackend_linearregression does not support classification. Use mlbackend_php.');
    }

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    /**
     * Loads the serialised regressor from disk.
     *
     * @param string $outputdir
     * @return LeastSquares
     * @throws \moodle_exception
     */
    protected function load_regressor(string $outputdir): LeastSquares {
        $modelfilepath = $this->get_model_filepath($outputdir);

        if (!file_exists($modelfilepath)) {
            throw new \moodle_exception('errorcantloadmodel', 'mlbackend_linearregression', '', $modelfilepath);
        }

        $object = unserialize(file_get_contents($modelfilepath), ['allowed_classes' => [LeastSquares::class]]);

        if (!$object instanceof LeastSquares) {
            throw new \moodle_exception('errorcantloadmodel', 'mlbackend_linearregression', '', $modelfilepath);
        }

        return $object;
    }

    /**
     * Returns the path to the serialised model file.
     *
     * @param string $modeldir
     * @return string
     */
    protected function get_model_filepath(string $modeldir): string {
        return $modeldir . DIRECTORY_SEPARATOR . self::MODEL_FILENAME;
    }

    /**
     * Extracts metadata from the dataset file header.
     *
     * The file pointer should be at the top of the file.
     *
     * @param resource $fh
     * @return array
     */
    protected function extract_metadata($fh): array {
        $metadata = fgetcsv($fh);
        return array_combine($metadata, fgetcsv($fh));
    }

    /**
     * Calculates R² (coefficient of determination).
     *
     * R² = 1 - (SS_res / SS_tot)
     * where SS_res is the sum of squared residuals and SS_tot is the total
     * sum of squares relative to the mean of the observed values.
     *
     * @param float[] $actual   Observed target values
     * @param float[] $predicted Predicted values
     * @return float R² score (1.0 = perfect, 0.0 = mean predictor, negative = worse than mean)
     */
    protected function r_squared(array $actual, array $predicted): float {
        $mean = array_sum($actual) / count($actual);

        $sstot = 0.0;
        $ssres = 0.0;
        foreach ($actual as $i => $observed) {
            $sstot += ($observed - $mean) ** 2;
            $ssres += ($observed - $predicted[$i]) ** 2;
        }

        if ($sstot == 0.0) {
            // All target values are identical — R² is undefined; return 0.
            return 0.0;
        }

        return 1.0 - ($ssres / $sstot);
    }

    /**
     * Splits samples and targets into random train/test subsets.
     *
     * @param array $samples
     * @param array $targets
     * @param float $testsize Proportion to use for testing (e.g. 0.2 for 80/20 split)
     * @return array [$trainsamples, $traintargets, $testsamples, $testtargets]
     */
    protected function random_split(array $samples, array $targets, float $testsize): array {
        $indices = array_keys($samples);
        shuffle($indices);

        $ntrain = (int) round(count($indices) * (1 - $testsize));

        $trainindices = array_slice($indices, 0, $ntrain);
        $testindices  = array_slice($indices, $ntrain);

        $trainsamples = array_map(function($i) use ($samples) { return $samples[$i]; }, $trainindices);
        $traintargets = array_map(function($i) use ($targets) { return $targets[$i]; }, $trainindices);
        $testsamples  = array_map(function($i) use ($samples) { return $samples[$i]; }, $testindices);
        $testtargets  = array_map(function($i) use ($targets) { return $targets[$i]; }, $testindices);

        return [$trainsamples, $traintargets, $testsamples, $testtargets];
    }

    /**
     * Builds the evaluation result object from an array of R² scores.
     *
     * @param float[] $scores
     * @param float $maxdeviation
     * @return \stdClass
     */
    protected function get_evaluation_result_object(array $scores, float $maxdeviation): \stdClass {
        $avgscore = count($scores) === 1
            ? reset($scores)
            : array_sum($scores) / count($scores);

        $modeldev = count($scores) === 1
            ? 0.0
            : $this->population_stddev($scores);

        $resultobj = new \stdClass();
        $resultobj->status = \core_analytics\model::OK;
        $resultobj->info = [];
        $resultobj->score = $avgscore;

        if ($modeldev > $maxdeviation) {
            $resultobj->status += \core_analytics\model::NOT_ENOUGH_DATA;
            $a = new \stdClass();
            $a->deviation = $modeldev;
            $a->accepteddeviation = $maxdeviation;
            $resultobj->info[] = get_string('errornotenoughdatadev', 'mlbackend_linearregression', $a);
        }

        if ($resultobj->score < \core_analytics\model::MIN_SCORE) {
            $resultobj->status += \core_analytics\model::LOW_SCORE;
            $a = new \stdClass();
            $a->score = $resultobj->score;
            $a->minscore = \core_analytics\model::MIN_SCORE;
            $resultobj->info[] = get_string('errorlowscore', 'mlbackend_linearregression', $a);
        }

        return $resultobj;
    }

    /**
     * Calculates population standard deviation.
     *
     * @param float[] $values
     * @return float
     */
    protected function population_stddev(array $values): float {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(function($v) use ($mean) { return ($v - $mean) ** 2; }, $values)) / count($values);
        return sqrt($variance);
    }
}
