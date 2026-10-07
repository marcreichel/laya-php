<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Console\Command;
use MarcReichel\Laya\Answers\Answer;
use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\ScoreAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;
use MarcReichel\Laya\DecisionMapper;
use MarcReichel\Laya\Exceptions\LayaException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Model;
use MarcReichel\Laya\Question;
use MarcReichel\Laya\Result;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php artisan laya:try`: runs a decision class on a piece of text and shows every answer's probabilities,
 * for iterating on #[Ask] and #[Describe] wording.
 */
final class TryCommand extends Command
{
    /** Pest can't cover a constant; the output tests pin it. */
    private const int BAR_WIDTH = 20; // @pest-mutate-ignore

    protected $signature = 'laya:try
        {class : The decision class, e.g. "App\Decisions\Triage" or "Decisions\Triage"}
        {state? : The text to classify; read from --file or STDIN when omitted}
        {--file= : Read the text from this file}
        {--model= : Pin a checkpoint (english, multilingual, typed-decisions)}
        {--max-len= : Token budget for the text}
        {--head-max-len= : Token budget for each question and its options}
        {--json : Print the result as JSON}';

    protected $description = 'Try a decision class on a piece of text and show the probabilities behind each answer';

    public function handle(Laya $laya): int
    {
        try {
            $class = $this->decisionClass();
            $state = $this->state();
            $model = $this->model();
            $maxLen = $this->budget('max-len');
            $headMaxLen = $this->budget('head-max-len');
            $questions = DecisionMapper::questions($class);
            $result = $laya->predict($state, $questions, $model, $maxLen, $headMaxLen);
            $values = DecisionMapper::values($class, $result);
        } catch (LayaException|\UnexpectedValueException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(self::json($class, $questions, $result, $values), OutputInterface::OUTPUT_RAW);
        } else {
            $this->render($questions, $result, $values);
        }

        return self::SUCCESS;
    }

    /** @return class-string */
    private function decisionClass(): string
    {
        $name = ltrim($this->stringArgument('class'), '\\');
        foreach ([$name, 'App\\'.$name] as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        throw new \UnexpectedValueException(sprintf('Class "%s" not found, neither as given nor under App\\.', $name));
    }

    private function state(): string
    {
        $state = $this->argument('state');
        $file = $this->option('file');

        if (is_string($state)) {
            $text = $state;
        } elseif (is_string($file)) {
            $text = is_file($file) ? @file_get_contents($file) : false;
            if ($text === false) {
                throw new \UnexpectedValueException(sprintf('Cannot read "%s".', $file));
            }
        } else {
            $text = $this->stdin();
        }

        return trim($text) !== '' ? $text : throw new \UnexpectedValueException('No text to classify. Pass it as an argument, with --file, or on STDIN.');
    }

    private function stdin(): string
    {
        // Artisan's input is always streamable, and tests hand it a stream; only a real run falls back to the process's STDIN.
        $stream = ($this->input instanceof StreamableInputInterface ? $this->input->getStream() : null) ?? STDIN; // @pest-mutate-ignore

        // A terminal means nothing was piped in: don't wait for typing. Tests can't hand the command a terminal.
        return stream_isatty($stream) ? '' : (string) stream_get_contents($stream); // @pest-mutate-ignore
    }

    private function model(): ?Model
    {
        $model = $this->option('model');
        if (! is_string($model)) {
            return null;
        }

        return Model::tryFrom($model) ?? throw new \UnexpectedValueException(sprintf(
            'Unknown model "%s". Use one of: %s.', $model, implode(', ', array_column(Model::cases(), 'value')),
        ));
    }

    private function budget(string $option): ?int
    {
        $value = $this->option($option);
        if ($value === null) {
            return null;
        }

        $budget = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($budget) ? $budget : throw new \UnexpectedValueException(sprintf('--%s must be a positive integer.', $option));
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        // Required arguments are always strings; the check is for static analysis.
        return is_string($value) ? $value : ''; // @pest-mutate-ignore
    }

    /**
     * @param  array<string, Question>  $questions
     * @param  array<string, bool|int|\BackedEnum|null>  $values
     */
    private function render(array $questions, Result $result, array $values): void
    {
        foreach ($questions as $name => $question) {
            $answer = $result->get($name);
            $details = sprintf('answer confidence %.2f', $answer->answerConfidence);
            if ($answer instanceof ScoreAnswer) {
                $details .= sprintf(', score %.2f', $answer->score);
            }

            $this->line(sprintf('<info>%s</info>: %s', $name, OutputFormatter::escape($question->instructions)));
            $this->line(sprintf('  → <comment>%s</comment> (%s)', OutputFormatter::escape(self::display($values[$name])), $details));

            $this->bars(self::probabilities($answer));
            $this->newLine();
        }

        $this->line(sprintf('Routed to %s, %d input tokens', $result->routedModel ?? 'an unknown checkpoint', $result->inputTokens));

        if ($result->truncated) {
            $this->warn('laya cut the text off at its token budget; raise --max-len to read all of it.');
        }
    }

    /** @param array<array-key, float> $probabilities */
    private function bars(array $probabilities): void
    {
        // A server that sends no probabilities gets no bars.
        if ($probabilities === []) {
            return;
        }

        $width = max(array_map(fn (int|string $label) => mb_strwidth((string) $label), array_keys($probabilities)));
        foreach ($probabilities as $label => $p) {
            $label = (string) $label;
            $filled = (int) round($p * self::BAR_WIDTH);
            $this->line(sprintf(
                '    %s  %s%s  %.2f',
                OutputFormatter::escape($label.str_repeat(' ', $width - mb_strwidth($label))),
                str_repeat('█', $filled),
                str_repeat('░', self::BAR_WIDTH - $filled),
                $p,
            ));
        }
    }

    /**
     * Option label => probability, most likely first. Score levels read "index: description", yes/no as "yes" and "no".
     * PHP turns numeric labels into int keys.
     *
     * @return array<array-key, float>
     */
    private static function probabilities(Answer $answer): array
    {
        if ($answer instanceof ChoiceAnswer) {
            $probabilities = $answer->probabilities;
        } elseif ($answer instanceof ScoreAnswer) {
            $probabilities = [];
            foreach ($answer->probabilities as $level => $p) {
                $probabilities[isset($answer->legend[$level]) ? $level.': '.$answer->legend[$level] : $level] = $p;
            }
        } else {
            /** @var YesNoAnswer $answer */
            // At 15 digits, so 1 - 0.83 reads 0.17 in JSON rather than 0.17000000000000004.
            $probabilities = ['yes' => $answer->probability, 'no' => (float) sprintf('%.15g', 1 - $answer->probability)];
        }

        arsort($probabilities);

        return $probabilities;
    }

    private static function display(bool|int|\BackedEnum|null $value): string
    {
        if ($value instanceof \BackedEnum) {
            return new \ReflectionClass($value)->getShortName().'::'.$value->name;
        }

        return match ($value) {
            true => 'true',
            false => 'false',
            null => 'null',
            default => (string) $value,
        };
    }

    /**
     * @param  class-string  $class
     * @param  array<string, Question>  $questions
     * @param  array<string, bool|int|\BackedEnum|null>  $values
     */
    private static function json(string $class, array $questions, Result $result, array $values): string
    {
        $parameters = [];
        foreach ($questions as $name => $question) {
            $answer = $result->get($name);
            $parameters[$name] = [
                'question' => $question->instructions,
                'type' => $question->type->value,
                'value' => $values[$name], // json_encode() writes an enum as its value
                'answer_confidence' => $answer->answerConfidence,
                // An object even for labels "0", "1", ..., which would otherwise encode as a list.
                'probabilities' => (object) self::probabilities($answer),
            ] + ($answer instanceof ScoreAnswer ? ['score' => $answer->score] : []);
        }

        return json_encode([
            'class' => $class,
            'routed_model' => $result->routedModel,
            'input_tokens' => $result->inputTokens,
            'truncated' => $result->truncated,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
