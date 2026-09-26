<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use MarcReichel\Laya\Answers\Answer;
use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\ScoreAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;
use MarcReichel\Laya\Exceptions\ServerException;

/**
 * The answers to one {@see Laya::predict()} call.
 *
 *     $result['department']->choice;          // Answer, via ArrayAccess
 *     $result->choice('department')->choice;  // ChoiceAnswer, for static analysis
 *
 * @implements \ArrayAccess<string, Answer>
 * @implements \IteratorAggregate<string, Answer>
 */
final readonly class Result implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param  array<string, Answer>  $answers
     * @param  string|null  $routedModel  the checkpoint laya's router picked, e.g. "english"
     * @param  array<mixed>  $routing  laya's full routing decision (model, reason, detected language, ...)
     * @param  array<mixed>  $raw  the whole response body
     */
    public function __construct(
        public array $answers,
        public ?string $routedModel,
        public array $routing,
        public int $inputTokens,
        public array $raw,
    ) {}

    /** @param array<mixed> $raw */
    public static function fromArray(array $raw): self
    {
        if (! is_array($raw['answers'] ?? null)) {
            throw new ServerException('The laya-serve response has no answers.', 200);
        }

        $answers = [];
        foreach ($raw['answers'] as $id => $answer) {
            $answers[(string) $id] = Answer::fromArray((string) $id, (array) $answer);
        }
        $routing = (array) ($raw['routing'] ?? []);
        $tokens = ((array) ($raw['usage'] ?? []))['input_tokens'] ?? 0;

        return new self(
            $answers,
            is_string($routing['model'] ?? null) ? $routing['model'] : null,
            $routing,
            is_int($tokens) ? $tokens : 0,
            $raw,
        );
    }

    public function get(string $id): Answer
    {
        return $this->answers[$id] ?? throw new \OutOfBoundsException(sprintf('No answer for question "%s". Asked: %s.', $id, implode(', ', array_keys($this->answers))));
    }

    public function has(string $id): bool
    {
        return isset($this->answers[$id]);
    }

    public function choice(string $id): ChoiceAnswer
    {
        return $this->typed($id, ChoiceAnswer::class);
    }

    public function score(string $id): ScoreAnswer
    {
        return $this->typed($id, ScoreAnswer::class);
    }

    public function yesNo(string $id): YesNoAnswer
    {
        return $this->typed($id, YesNoAnswer::class);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): Answer
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('A Result is immutable.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('A Result is immutable.');
    }

    public function count(): int
    {
        return count($this->answers);
    }

    /** @return \ArrayIterator<string, Answer> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->answers);
    }

    /**
     * @template T of Answer
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function typed(string $id, string $class): Answer
    {
        $answer = $this->get($id);
        if (! $answer instanceof $class) {
            throw new \UnexpectedValueException(sprintf('Question "%s" is a %s, not a %s.', $id, $answer::class, $class));
        }

        return $answer;
    }
}
