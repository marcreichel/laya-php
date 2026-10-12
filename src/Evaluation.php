<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use MarcReichel\Laya\Exceptions\InvalidOptionException;
use MarcReichel\Laya\Exceptions\ServerException;

/**
 * Measures a decision class against labelled examples: per question, how often laya's answer matches the label,
 * how well its answerConfidence is calibrated, and which minConfidence (and, for yes/no, threshold) to pick.
 *
 *     $report = new Evaluation($laya)->run(Triage::class, LabelledRows::read('triage.csv'), precision: 0.95);
 *     echo $report->render();
 *
 * Labels are compared with the ungated answer: the most likely option, level, or P(yes) >= 0.5. The class's own
 * #[Ask] minConfidence and threshold are what the report helps you pick, so they're ignored.
 *
 * @phpstan-import-type Metrics from EvaluationReport
 *
 * @phpstan-type Sample array{gold: string, answer: string, hit: int, confidence: float}
 */
final readonly class Evaluation
{
    public function __construct(private Laya $laya) {}

    /**
     * @param  class-string  $class
     * @param  iterable<array-key, array<mixed>>  $rows  "state" => the text, question id => its label: the enum value, level index, or true/false.
     *                                                   A missing or empty label isn't scored. Errors name a row by its key
     * @param  float  $precision  the precision the suggested minConfidence has to reach on the answers it keeps
     */
    public function run(string $class, iterable $rows, float $precision = 0.95): EvaluationReport
    {
        $questions = DecisionMapper::questions($class);
        if (! ($precision >= 0 && $precision <= 1)) {
            throw new InvalidOptionException(sprintf('precision must be a number from 0 to 1, got %s.', $precision));
        }

        // Yes/no answers are compared as "true"/"false", choices by label, scores by level index.
        $options = array_map(fn (Question $q) => $q->type === QuestionType::YesNo ? ['true', 'false'] : array_map(strval(...), array_keys($q->criteria)), $questions);

        $states = [];
        $labels = []; // question id => row => label
        foreach ($rows as $row => $values) {
            $state = $values['state'] ?? null;
            $states[$row] = is_string($state) && trim($state) !== '' ? $state : throw new \UnexpectedValueException(sprintf('Row %s has no state.', $row));
            foreach ($values as $column => $label) {
                if ($column === 'state') {
                    continue;
                }
                if (! isset($options[$column])) {
                    throw new \UnexpectedValueException(sprintf('Row %s has a column "%s", but %s asks no such question. Its questions: %s.', $row, $column, $class, implode(', ', array_keys($questions))));
                }
                $label = self::label($label);
                if ($label === '') {
                    continue;
                }
                if (! in_array($label, $options[$column], true)) {
                    throw new \UnexpectedValueException(sprintf('Row %s labels %s "%s", which isn\'t one of its labels: %s.', $row, $column, $label, implode(', ', $options[$column])));
                }
                $labels[$column][$row] = $label;
            }
        }
        if ($states === []) {
            throw new \UnexpectedValueException('There are no rows to evaluate.');
        }

        try {
            $results = $this->laya->predictMany($states, $questions);
        } catch (ServerException $e) {
            // laya-serve before 0.3.22 has no batch endpoint: ask one state at a time.
            $results = $e->status === 404 ? array_map(fn (string $state) => $this->laya->predict($state, $questions), $states) : throw $e;
        }

        $parameters = [];
        foreach ($questions as $id => $question) {
            if (isset($labels[$id])) {
                $parameters[$id] = self::metrics($id, $question, $options[$id], $labels[$id], $results, $precision);
            }
        }

        return new EvaluationReport($class, count($states), $precision, $parameters);
    }

    /** A label as a string, "" for none. Anything else becomes its JSON: "true"/"false" for a bool, which yes/no options match. */
    private static function label(mixed $label): string
    {
        if (is_string($label) || is_int($label)) {
            return trim((string) $label);
        }

        return $label === null ? '' : json_encode($label, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $options
     * @param  array<array-key, string>  $labels  row => label
     * @param  array<array-key, Result>  $results  row => result
     * @return Metrics
     */
    private static function metrics(string $id, Question $question, array $options, array $labels, array $results, float $precision): array
    {
        $samples = [];
        $probabilities = []; // P(yes) per sample, for yes/no questions
        foreach ($labels as $row => $gold) {
            $result = $results[$row];
            if ($question->type === QuestionType::YesNo) {
                $probabilities[] = $result->yesNo($id)->probability;
                $answer = $result->yesNo($id)->yes() ? 'true' : 'false';
            } elseif ($question->type === QuestionType::Score) {
                $answer = (string) $result->score($id)->level();
            } else {
                $answer = (string) $result->choice($id)->choice;
            }
            $samples[] = ['gold' => $gold, 'answer' => $answer, 'hit' => $gold === $answer ? 1 : 0, 'confidence' => $result->get($id)->answerConfidence];
        }

        $metrics = ['type' => $question->type->value, 'labelled' => count($samples), 'accuracy' => self::accuracy($samples)];
        if ($question->type === QuestionType::YesNo) {
            [$tp, $fp, $fn] = self::counts($samples, $probabilities, 0.5);
            $metrics['precision'] = $tp + $fp > 0 ? $tp / ($tp + $fp) : null;
            $metrics['recall'] = $tp + $fn > 0 ? $tp / ($tp + $fn) : null;
        } else {
            // Only the labels that occur, in the question's order: a choice can have a hundred options.
            $used = array_intersect($options, [...array_column($samples, 'gold'), ...array_column($samples, 'answer')]);
            $confusion = array_fill_keys($used, array_fill_keys($used, 0));
            foreach ($samples as ['gold' => $gold, 'answer' => $answer]) {
                $confusion[$gold][$answer]++;
            }
            $metrics['confusion'] = $confusion;
        }

        $metrics['calibration'] = self::calibration($samples);
        $metrics['min_confidence'] = self::minConfidence($samples, $precision);
        if ($question->type === QuestionType::YesNo) {
            $metrics['threshold'] = self::threshold($samples, $probabilities);
        }

        return $metrics;
    }

    /** @param array<Sample> $samples never empty */
    private static function accuracy(array $samples): float
    {
        return array_sum(array_column($samples, 'hit')) / count($samples);
    }

    /**
     * Accuracy per 0.1-wide answerConfidence bucket, lowest first; empty buckets are left out.
     *
     * @param  list<Sample>  $samples
     * @return array<string, array{answers: int, accuracy: float}>
     */
    private static function calibration(array $samples): array
    {
        $buckets = [];
        foreach ($samples as $sample) {
            $bucket = min(9, (int) ($sample['confidence'] * 10));
            $buckets[sprintf('%.1f-%.1f', $bucket / 10, ($bucket + 1) / 10)][] = $sample;
        }
        ksort($buckets);

        return array_map(fn (array $in) => ['answers' => count($in), 'accuracy' => self::accuracy($in)], $buckets);
    }

    /**
     * The lowest observed answerConfidence whose kept answers (those at or above it) reach $precision, or null.
     *
     * @param  list<Sample>  $samples
     * @return array{value: float, precision: float, to_human: float}|null
     */
    private static function minConfidence(array $samples, float $precision): ?array
    {
        $thresholds = array_column($samples, 'confidence');
        sort($thresholds);
        foreach ($thresholds as $threshold) {
            $kept = array_filter($samples, fn (array $s) => $s['confidence'] >= $threshold);
            $reached = self::accuracy($kept);
            if ($reached >= $precision) {
                return ['value' => $threshold, 'precision' => $reached, 'to_human' => 1.0 - count($kept) / count($samples)];
            }
        }

        return null;
    }

    /**
     * The observed P(yes) that, as the threshold, gives the best F1; the lowest one on a tie.
     *
     * @param  list<Sample>  $samples
     * @param  list<float>  $probabilities
     * @return array{value: float, f1: float}
     */
    private static function threshold(array $samples, array $probabilities): array
    {
        $thresholds = $probabilities;
        sort($thresholds);
        $candidates = array_map(function (float $threshold) use ($samples, $probabilities) {
            // At an observed P(yes) at least one answer is yes, so the denominator is never 0.
            [$tp, $fp, $fn] = self::counts($samples, $probabilities, $threshold);

            return ['value' => $threshold, 'f1' => 2 * $tp / (2 * $tp + $fp + $fn)];
        }, $thresholds);
        // usort() is stable, so on a tie the lowest threshold stays first.
        usort($candidates, fn (array $a, array $b) => $b['f1'] <=> $a['f1']);

        return $candidates[0];
    }

    /**
     * True positives, false positives and false negatives when P(yes) >= $threshold means yes.
     *
     * @param  list<Sample>  $samples
     * @param  list<float>  $probabilities  P(yes) per sample
     * @return array{int, int, int}
     */
    private static function counts(array $samples, array $probabilities, float $threshold): array
    {
        [$tp, $fp, $fn] = [0, 0, 0];
        foreach ($samples as $i => ['gold' => $gold]) {
            $yes = $probabilities[$i] >= $threshold;
            if ($yes && $gold === 'true') {
                $tp++;
            } elseif ($yes) {
                $fp++;
            } elseif ($gold === 'true') {
                $fn++;
            }
        }

        return [$tp, $fp, $fn];
    }
}
