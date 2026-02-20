# Course Final Grade Prediction — Moodle Analytics Plugins

This document describes the design decisions made during the development of two companion Moodle plugins that together add linear regression-based course final grade prediction to Moodle's Analytics subsystem.

---

## Overview

The two plugins are:

- **`mlbackend_linearregression`** (`lib/mlbackend/linearregression/`) — A new ML backend plugin that implements Ordinary Least Squares (OLS) linear regression, fulfilling the `\core_analytics\regressor` interface.
- **`local_coursefinalgrade`** (`local/coursefinalgrade/`) — A local plugin that defines a new analytics target, `course_final_grade`, which predicts each enrolled student's final course grade as a continuous percentage value (0–100).

`local_coursefinalgrade` declares `mlbackend_linearregression` as a dependency in its `version.php`. Moodle's plugin installer will enforce this — the backend must be present for the target plugin to install.

---

## Why Two Plugins?

The ML backend and the target were deliberately separated into two plugins for the following reasons:

- **Separation of concerns.** The linear regression backend is a general-purpose mathematical tool that could in principle support other linear targets developed in the future. Bundling it inside `local_coursefinalgrade` would prevent reuse.
- **Independent installability.** A site administrator could install `mlbackend_linearregression` alone to make it available for selection in the Analytics UI, without being required to install any particular target.
- **Consistency with Moodle conventions.** Moodle's ML backend plugins (`mlbackend_php`, `mlbackend_python`) are a distinct plugin type at `lib/mlbackend/`. Creating a new plugin of this type follows the established pattern rather than working around it.
- **Documentation relationship.** Although the plugins are separate, they are documented together here because they were designed as a unit and their design decisions are interdependent.

---

## ML Backend: `mlbackend_linearregression`

### Why OLS Linear Regression?

Moodle's built-in `mlbackend_php` uses Logistic Regression for binary classification (e.g. dropout risk: yes/no). The `\core_analytics\local\target\linear` base class has existed in Moodle core since 3.4 to support continuous value prediction, but was never activated because no ML backend implemented the regression interface — `train_regression()`, `estimate()`, and `evaluate_regression()` in `mlbackend_php` all throw `coding_exception('This predictor does not support regression yet.')`.

OLS linear regression is the natural and mathematically appropriate algorithm for predicting a continuous target variable (final grade percentage) from a set of continuous indicator features. It is interpretable, computationally efficient for the dataset sizes typical in Moodle, and requires no hyperparameter tuning.

### Dependency on PHP-ML (Already Bundled with Moodle)

Rather than introducing a new external library, `mlbackend_linearregression` uses the `Phpml\Regression\LeastSquares` class already shipped inside `mlbackend_php` at `lib/mlbackend/php/phpml/`. This class implements the OLS normal equations:

```
β = (XᵀX)⁻¹Xᵀy
```

using `Phpml\Math\Matrix` and `Phpml\Math\LinearAlgebra\LUDecomposition` for numerically stable matrix inversion. No Composer step or external dependency is required for installation.

The `require_once` calls in `processor.php` load these classes directly from the `mlbackend_php` directory. This creates a soft coupling to `mlbackend_php` being present — a reasonable assumption since it ships with Moodle core.

### Why OLS Cannot Be Trained Incrementally

Unlike Logistic Regression, which supports `partialTrain()` for processing data in batches, OLS must have access to the complete dataset simultaneously to solve the normal equations. This is a fundamental mathematical constraint, not an implementation limitation. The `LeastSquares::train()` method accumulates all samples and targets in memory before computing coefficients.

This is documented explicitly in the class docblock so future maintainers understand that batching is not a missing feature that can be added later.

### Memory Safety: Training Sample Cap

Because OLS loads the full dataset into memory, large Moodle sites with many enrolments could exhaust available PHP memory during training. A cap of `MAX_TRAINING_SAMPLES = 20000` records is applied by default.

**Why 20,000?** At approximately 50 indicator features per sample and ~128 bytes per float value (PHP array overhead included), 20,000 samples represents roughly 128MB of raw data — leaving reasonable headroom for the matrix operations on top.

**Why the most recent records?** When capping is applied, the most recent completed enrolment records are retained (the dataset file is read chronologically, so `array_slice($samples, -$maxsamples)` keeps the tail). Recent enrolments are more likely to reflect current course structure, grading behaviour, and student population than older historical records.

**Configurability.** Site administrators can tune the cap via `$CFG->mlbackend_linearregression_max_training_samples` in `config.php`. Setting it to `0` disables the limit entirely (not recommended on large sites). When capping occurs, a message is added to the training result's `info` array, which is displayed in the Analytics model log.

This approach is modelled on `mlbackend_php`'s own memory handling during evaluation, which uses a similar configurable override (`$CFG->mlbackend_php_no_evaluation_limits`).

### Evaluation Metric: R²

The PHP backend uses F1 score to evaluate classification models. For regression, F1 is not applicable. This backend uses **R² (coefficient of determination)** instead, which measures the proportion of variance in the target variable explained by the model:

- R² = 1.0: perfect prediction
- R² = 0.0: model performs no better than predicting the mean
- R² < 0: model performs worse than a mean predictor

R² is computed across multiple random 80/20 train/test splits (consistent with `mlbackend_php`'s multi-iteration evaluation approach) to check for result stability. The standard deviation of scores across iterations is compared against `$maxdeviation` to flag models that may need more data.

### Classification Methods

`mlbackend_linearregression` intentionally does not support classification. `train_classification()`, `classify()`, and `evaluate_classification()` all throw `coding_exception` with a message directing developers to `mlbackend_php`. This makes the plugin's scope explicit and prevents accidental misconfiguration.

### Privacy

The plugin stores no personal data. The serialised model file contains only mathematical coefficients (floating point numbers) derived from aggregated training data. The privacy provider implements `null_provider`.

---

## Target Plugin: `local_coursefinalgrade`

### Plugin Type Choice

The target is implemented as a `local` plugin rather than as part of a core component (e.g. `core_course`). This is appropriate because:

- It is a third-party extension, not a core Moodle capability.
- The `local` plugin type is the standard home for site-specific or distributed extensions that don't fit an existing plugin type.
- The target class follows Moodle's namespace and file path conventions for analytics targets discovered by the analytics subsystem: `local_coursefinalgrade\analytics\target\course_final_grade` resolves to `local/coursefinalgrade/classes/analytics/target/course_final_grade.php`.

### Inheritance: Extending `course_enrolments`, Not `linear` Directly

The target extends `\core_course\analytics\target\course_enrolments` (which itself extends `\core_analytics\local\target\binary`) rather than extending `\core_analytics\local\target\linear` directly.

This is a deliberate choice. `course_enrolments` provides substantial shared behaviour that is equally applicable to grade prediction:

- Enrolment validity checking (course start/end, duration limits, student presence)
- Sample validity checking (enrolment active during analysis interval)
- Student messaging and bulk actions for insights
- The `get_analyser_class()` returning `\core\analytics\analyser\student_enrolments`

Re-implementing all of this from `linear` directly would be unnecessary duplication. Instead, `course_final_grade` overrides `is_linear()` to return `true` (rather than throwing the `coding_exception` that both `binary` and the base `linear` class would throw), which signals to the analytics engine to route this target through regression rather than classification methods on the backend.

### `is_linear()` as the Key Override

Returning `true` from `is_linear()` is the single most important override in this plugin. It is what activates the entire regression pathway — without it, the analytics engine would attempt to use classification methods on the backend, which would fail immediately with a `coding_exception` from `mlbackend_linearregression`.

### `calculate_sample()`: Normalisation to Percentage

The `calculate_sample()` method reads `grade_grades.finalgrade` for the student's course total grade item and normalises it to a 0–100 percentage using the grade item's configured `grademax` and `grademin`:

```
percentage = ((finalgrade - grademin) / (grademax - grademin)) * 100
```

This normalisation ensures that the model produces consistent output regardless of how individual courses configure their grade scales (e.g. a course graded out of 150 and one graded out of 100 produce comparable training targets). The result is clamped to [0, 100] to guard against any out-of-range grade values in the database.

If the student has no final grade recorded (`finalgrade IS NULL`), `null` is returned, which causes the analytics engine to exclude that sample from training. This is appropriate — ungraded students should not contribute to training data.

### The Callback Boundary: A Display Indicator, Not a Filter

This is one of the most significant philosophical departures from existing Moodle analytics targets, and the decision warrants careful documentation.

**In existing binary targets** (e.g. `course_dropout`, `course_completion`), the callback boundary acts as a suppression threshold. Only predictions that cross the boundary trigger an insight notification. The intent is to surface a manageable list of at-risk students for teacher intervention.

**In `course_final_grade`**, the intent is different. The goal is to give teachers visibility into the full grade trajectory of their cohort — not just to flag students at risk, but to enable positive engagement with students who are performing well alongside support for those who are struggling. This reflects a broader pedagogical philosophy: prediction should inform teaching holistically, not only reactively.

Consequently:

- **`triggers_callback()` is overridden to always return `true`**, ensuring that all student predictions generate insights regardless of their predicted grade.
- **The callback boundary is retained** as a visual indicator only. It is used by `get_calculation_outcome()` to determine whether a prediction is presented positively (`OUTCOME_VERY_POSITIVE`) or as needing attention (`OUTCOME_VERY_NEGATIVE`) in the insights UI.
- **The boundary value** is read from `grade_item::gradepass` on the course total grade item, normalised to a percentage. This is the most contextually meaningful threshold — it reflects the institution's or teacher's own definition of a passing grade. It falls back to 50.0% when no passing grade is configured.

**Future UI consideration.** Because all students generate insights, the standard insights list UI (designed for a shorter at-risk list) may not be the ideal presentation for this target. A future version might benefit from a custom renderer presenting predictions as a ranked cohort table, with visual differentiation above and below the boundary. This is a known limitation of the current implementation that follows directly from the "full trajectory" philosophical decision.

### Analysable Validation

`is_valid_analysable()` extends the parent's validation with two additional checks:

1. **Grade items must exist.** A course with no grade items has nothing to train or predict against.
2. **Minimum activity threshold.** For training, a minimum average of 10 log events per student is required, consistent with `course_dropout`. Without sufficient activity data, the indicators that feed the model will be sparse and predictions unreliable.

### Default Model Registration

`db/analytics.php` registers a default model using the core indicator set from the dropout model. These indicators were chosen as a reasonable starting point because engagement patterns (cognitive depth, social breadth, write actions) are known to correlate with academic outcomes. However, they were selected for dropout prediction rather than grade prediction specifically.

**The model is registered with `'enabled' => false`** — site administrators should review the indicator selection and evaluate the model on their own data before enabling it in production. The indicators can be adjusted through the Analytics UI after installation without modifying plugin code.

---

## Installation

Because `local_coursefinalgrade` declares `mlbackend_linearregression` as a dependency, Moodle's installer will enforce correct order. Copy the plugins into your Moodle directory tree:

```bash
# 1. Copy backend plugin
cp -r lib/mlbackend/linearregression /path/to/moodle/lib/mlbackend/

# 2. Copy target plugin
cp -r local/coursefinalgrade /path/to/moodle/local/
```

Then follow these steps in order:

**Step 1: Trigger installation**
Visit Site Administration in your browser. Moodle will detect the new plugins and prompt you to install them. `mlbackend_linearregression` will be installed first as it is a declared dependency of `local_coursefinalgrade`.

**Step 2: Set the ML backend**
Go to **Site Administration → Analytics → Analytics settings** and set the machine learning backend to *Linear regression machine learning backend*.

**Step 3: Restore default models**
Go to **Site Administration → Analytics → Analytics models** and click **Restore default models**. This is a required step — the "Course final grade prediction" model defined in `db/analytics.php` is not automatically visible after installation. It will appear in the list after restoring defaults.

**Step 4: Review and enable the model**
The model is installed in a disabled state (`'enabled' => false`) by design. Before enabling it, review the indicator set and evaluate the model against your site's data. The model can be enabled through the Analytics models interface once you are satisfied it is appropriate for your site.

---

## Known Limitations and Future Work

- **Custom insights UI.** The full-cohort insight approach would benefit from a dedicated renderer rather than the standard at-risk list view.
- **Callback boundary per-course configuration.** Currently the boundary is read from the grade item at runtime. A future version could allow per-model boundary configuration through the Analytics UI.
- **Indicator selection.** The default indicators are borrowed from the dropout model. A dedicated research effort to identify indicators most predictive of final grade (rather than dropout) would improve model accuracy.
- **Incremental training.** OLS cannot be trained incrementally. If this becomes a limitation at scale, a gradient descent approach (e.g. stochastic gradient descent regression) could be introduced as an alternative backend that supports partial updates, without changing the target plugin.
- **Training sample cap.** The default cap of 20,000 samples is a conservative estimate. Sites with reliable memory configurations and smaller feature sets may safely increase this.
