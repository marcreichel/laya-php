<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use MarcReichel\Laya\Exceptions\InvalidQuestionException;

/**
 * One typed question for laya. Build it with {@see choice()}, {@see score()} or {@see yesNo()}.
 *
 * The constructor applies the same rules laya does, so a malformed question fails
 * here instead of coming back as a 422 from the server.
 */
final readonly class Question
{
    /**
     * @param  array<mixed>  $criteria  validated below. choice: label => description; score: level descriptions, index 0 first; yes/no: 'true'/'false' => description
     */
    public function __construct(
        public QuestionType $type,
        public string $instructions,
        public array $criteria = [],
    ) {
        if (trim($instructions) === '') {
            throw new InvalidQuestionException('A question needs instructions: the text the model should answer.');
        }

        match ($type) {
            QuestionType::Choice => self::assertChoice($criteria),
            QuestionType::Score => self::assertScore($criteria),
            QuestionType::YesNo => self::assertYesNo($criteria),
        };
    }

    /**
     * Pick one option. Pass a list of labels, or a map of label => description.
     *
     *     Question::choice('Which department?', ['billing', 'technical']);
     *     Question::choice('Which department?', ['billing' => 'invoices, refunds', 'technical' => 'bugs, outages']);
     *
     * @param  list<string|int>|array<string|int, ?string>  $options
     */
    public static function choice(string $instructions, array $options): self
    {
        if (array_is_list($options)) {
            $labels = [];
            foreach ($options as $i => $label) {
                if (! is_string($label) && ! is_int($label)) {
                    throw new InvalidQuestionException(sprintf('Choice label %d must be a string or int, got %s.', $i, get_debug_type($label)));
                }
                $labels[$label] = null;
            }
            $options = $labels;
        }

        return new self(QuestionType::Choice, $instructions, $options);
    }

    /**
     * Rate on an ordered scale. Pass the level descriptions, lowest (index 0) first.
     *
     *     Question::score('How urgent is this?', ['not urgent', 'soon', 'blocking']);
     *
     * @param  list<string>  $levels
     */
    public static function score(string $instructions, array $levels): self
    {
        return new self(QuestionType::Score, $instructions, $levels);
    }

    /**
     * A yes/no question. The answer is the probability of "yes".
     *
     *     Question::yesNo('Does the user threaten to cancel?', yes: 'explicitly says they will leave');
     */
    public static function yesNo(string $instructions, ?string $yes = null, ?string $no = null): self
    {
        return new self(QuestionType::YesNo, $instructions, array_filter(['true' => $yes, 'false' => $no], fn ($d) => $d !== null));
    }

    /**
     * The wire format laya-serve expects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $question = ['type' => $this->type->value, 'instructions' => $this->instructions];

        // Choice criteria are a map; cast so an int-keyed map never encodes as a JSON list.
        return match ($this->type) {
            QuestionType::Choice => $question + ['criteria' => (object) $this->criteria],
            QuestionType::Score => $question + ['criteria' => $this->criteria],
            QuestionType::YesNo => $this->criteria === [] ? $question : $question + ['criteria' => $this->criteria],
        };
    }

    /** @param array<mixed> $criteria */
    private static function assertChoice(array $criteria): void
    {
        if ($criteria === []) {
            throw new InvalidQuestionException('A choice question needs at least one option.');
        }
        foreach ($criteria as $label => $description) {
            if ($description !== null && ! is_string($description)) {
                throw new InvalidQuestionException(sprintf('The description of choice option "%s" must be a string or null, got %s.', $label, get_debug_type($description)));
            }
        }
    }

    /** @param array<mixed> $criteria */
    private static function assertScore(array $criteria): void
    {
        if ($criteria === [] || ! array_is_list($criteria)) {
            throw new InvalidQuestionException('A score question needs a non-empty list of level descriptions, index 0 first.');
        }
        foreach ($criteria as $i => $level) {
            if (! is_string($level)) {
                throw new InvalidQuestionException(sprintf('Score level %d must be a string description, got %s.', $i, get_debug_type($level)));
            }
        }
    }

    /** @param array<mixed> $criteria */
    private static function assertYesNo(array $criteria): void
    {
        foreach ($criteria as $key => $description) {
            if (! in_array($key, ['true', 'false'], true) || ! is_string($description)) {
                throw new InvalidQuestionException("Yes/no criteria must be keyed 'true'/'false' with string descriptions.");
            }
        }
    }
}
