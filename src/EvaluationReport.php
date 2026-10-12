<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

/**
 * What {@see Evaluation::run()} measured, per labelled question. Read the arrays, print {@see render()}, or store {@see toJson()}.
 *
 * Per question: its type ("choice", "score" or "noul"), how many rows label it, the accuracy, a confusion matrix
 * (label => answer => count; choice and score) or precision and recall of "yes" (yes/no), accuracy per answerConfidence
 * bucket, the lowest minConfidence that reaches the target precision on the answers it keeps (null when none does)
 * with the share it sends to a human, and for yes/no the threshold with the best F1.
 *
 * @phpstan-type Metrics array{
 *     type: string,
 *     labelled: int,
 *     accuracy: float,
 *     precision?: float|null,
 *     recall?: float|null,
 *     confusion?: array<array-key, array<array-key, int>>,
 *     calibration: array<string, array{answers: int, accuracy: float}>,
 *     min_confidence: array{value: float, precision: float, to_human: float}|null,
 *     threshold?: array{value: float, f1: float},
 * }
 */
final readonly class EvaluationReport
{
    /**
     * @param  class-string  $class
     * @param  int  $rows  the rows evaluated, labelled or not
     * @param  float  $precision  the target precision for min_confidence
     * @param  array<string, Metrics>  $parameters  question id => metrics; questions no row labels are left out
     */
    public function __construct(
        public string $class,
        public int $rows,
        public float $precision,
        public array $parameters,
    ) {}

    /** @return array{class: class-string, rows: int, precision: float, parameters: array<string, Metrics>} */
    public function toArray(): array
    {
        return ['class' => $this->class, 'rows' => $this->rows, 'precision' => $this->precision, 'parameters' => $this->parameters];
    }

    public function toJson(): string
    {
        // Every array here is a map; forcing objects keeps labels "0", "1", ... from encoding as a list.
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_FORCE_OBJECT);
    }

    /** The report as plain text, as laya:eval and vendor/bin/laya-eval print it. */
    public function render(): string
    {
        $lines = [sprintf('%s: %d rows, target precision %.2f', $this->class, $this->rows, $this->precision)];
        foreach ($this->parameters as $id => $m) {
            $lines[] = '';
            $lines[] = sprintf('%s: %d labelled', $id, $m['labelled']);
            $lines[] = sprintf('  accuracy %.2f', $m['accuracy']).(array_key_exists('precision', $m) ? sprintf(', precision %s, recall %s', self::ratio($m['precision']), self::ratio($m['recall'] ?? null)) : '');

            if (isset($m['confusion'])) {
                $lines[] = '  confusion (label down, answer across):';
                $header = ['', ...array_keys($m['confusion'])];
                $width = max(array_map(fn (int|string $cell) => mb_strwidth((string) $cell), [...$header, ...array_merge(...array_values($m['confusion']))]));
                $lines[] = self::row($header, $width);
                foreach ($m['confusion'] as $label => $answers) {
                    $lines[] = self::row([$label, ...$answers], $width);
                }
            }

            $lines[] = '  calibration (answer confidence: accuracy):';
            foreach ($m['calibration'] as $bucket => ['answers' => $answers, 'accuracy' => $accuracy]) {
                $lines[] = sprintf('    %s  %.2f of %d', $bucket, $accuracy, $answers);
            }

            $min = $m['min_confidence'];
            $lines[] = $min === null
                ? sprintf('  no minConfidence reaches precision %.2f', $this->precision)
                : sprintf('  minConfidence %s reaches precision %.2f and sends %d%% to a human', $min['value'], $min['precision'], round($min['to_human'] * 100));

            if (isset($m['threshold'])) {
                $lines[] = sprintf('  threshold %s has the best F1 (%.2f)', $m['threshold']['value'], $m['threshold']['f1']);
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<int|string> $cells */
    private static function row(array $cells, int $width): string
    {
        return '    '.implode('  ', array_map(fn (int|string $cell) => mb_str_pad((string) $cell, $width, ' ', STR_PAD_LEFT), $cells));
    }

    private static function ratio(?float $ratio): string
    {
        return $ratio === null ? 'n/a' : sprintf('%.2f', $ratio);
    }
}
