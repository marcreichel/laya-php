<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use MarcReichel\Laya\Answers\Answer;
use MarcReichel\Laya\Answers\ChoiceAnswer;
use MarcReichel\Laya\Answers\ScoreAnswer;
use MarcReichel\Laya\Answers\YesNoAnswer;
use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Attributes\Of;
use MarcReichel\Laya\Attributes\Scale;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerException;

/**
 * Turns a decision class's constructor into questions, and a Result back into an instance.
 *
 * Mapping: backed enum => choice, #[Scale] backed enum => score, bool => yes/no, int + #[Levels] => score,
 * array + #[Of(Enum::class)] => one yes/no per case, with ids such as "topics.billing",
 * float => P(yes) of a yes/no, float + #[Levels] => the expected level of a score.
 * A nullable parameter with #[Ask(minConfidence: ...)] is null when laya is unsure.
 *
 * @internal
 *
 * @phpstan-type Entry array{questions: array<string, Question>, parameters: list<array{name: string, type: string, ask: Ask, scale: bool, of: class-string<\BackedEnum>|null, nullable: bool}>}
 */
final class DecisionMapper
{
    /** laya-serve refuses requests with more questions. Pest can't cover a constant; the 64/65-question tests pin it. */
    private const int MAX_QUESTIONS = 64; // @pest-mutate-ignore

    /**
     * Per class: its questions, and what values() needs per constructor parameter. Built once per process by entry(); failures aren't stored.
     *
     * @var array<class-string, Entry>
     */
    private static array $entries = [];

    /**
     * @param  class-string  $class
     * @return array<string, Question>
     */
    public static function questions(string $class): array
    {
        return self::entry($class)['questions'];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function hydrate(string $class, Result $result): object
    {
        return new $class(...self::values($class, $result));
    }

    /**
     * The constructor arguments hydrate() passes: parameter name => value, null where laya is unsure.
     *
     * @param  class-string  $class
     * @return array<string, bool|int|float|\BackedEnum|list<\BackedEnum>|null>
     */
    public static function values(string $class, Result $result): array
    {
        $arguments = [];
        $entry = self::entry($class);
        foreach ($entry['parameters'] as ['name' => $name, 'type' => $type, 'ask' => $ask, 'scale' => $scale, 'of' => $of]) {
            if ($of !== null) {
                $arguments[$name] = self::cases($class, $name, $of, $ask, $result);

                continue;
            }
            $subject = $class.'::$'.$name;
            $answer = match ($entry['questions'][$name]->type) {
                QuestionType::YesNo => self::answer($subject, $name, $result, $result->yesNo(...), 'yes/no'),
                QuestionType::Score => self::answer($subject, $name, $result, $result->score(...), 'score'),
                QuestionType::Choice => self::answer($subject, $name, $result, $result->choice(...), 'choice'),
            };
            if ($ask->minConfidence !== null && $answer->answerConfidence < $ask->minConfidence) {
                $arguments[$name] = null;

                continue;
            }
            if ($answer instanceof YesNoAnswer) {
                $arguments[$name] = $type === 'float' ? $answer->probability : $answer->yes($ask->threshold);
            } elseif ($answer instanceof ScoreAnswer) {
                $arguments[$name] = $type === 'float' ? $answer->score : ($scale ? self::scaleCase($subject, $type, $answer->level()) : $answer->level());
            } else {
                $arguments[$name] = self::enumCase($subject, $type, $answer->choice);
            }
        }

        return $arguments;
    }

    /**
     * The decision's constructor arguments by parameter name; json_encode() turns enums, and lists of them, into backing values.
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    public static function toArray(string $class, object $decision): array
    {
        $data = [];
        foreach (self::entry($class)['parameters'] as ['name' => $name]) {
            $data[$name] = new \ReflectionProperty($class, $name)->getValue($decision); // also reads private promoted properties
        }

        return $data;
    }

    /**
     * The inverse of toArray(), through the constructor.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<array-key, mixed>  $data
     * @return T
     *
     * @throws \UnexpectedValueException when $data has no value, or one of the wrong type, for a parameter
     */
    public static function fromArray(string $class, array $data): object
    {
        $arguments = [];
        foreach (self::entry($class)['parameters'] as ['name' => $name, 'type' => $type, 'of' => $of, 'nullable' => $nullable]) {
            if (! array_key_exists($name, $data)) {
                throw new \UnexpectedValueException(sprintf('has no value for $%s', $name));
            }
            $value = $data[$name];
            if ($value === null && $nullable) {
                $arguments[$name] = null;
            } elseif ($of !== null) {
                is_array($value) || throw new \UnexpectedValueException(sprintf('has no list for $%s', $name));
                $arguments[$name] = array_map(static fn (mixed $case): \BackedEnum => self::stored($name, $of, $case), $value);
            } elseif (is_subclass_of($type, \BackedEnum::class)) {
                $arguments[$name] = self::stored($name, $type, $value);
            } elseif (get_debug_type($value) === $type || ($type === 'float' && is_int($value))) { // int widens to float in the constructor call
                $arguments[$name] = $value;
            } else {
                throw new \UnexpectedValueException(sprintf('has %s for $%s, not %s', get_debug_type($value), $name, $type));
            }
        }

        return new $class(...$arguments);
    }

    /** @param  class-string<\BackedEnum>  $enum */
    private static function stored(string $name, string $enum, mixed $value): \BackedEnum
    {
        try {
            $case = is_int($value) || is_string($value) ? $enum::tryFrom($value) : null;
        } catch (\TypeError) { // strict_types: an int for a string enum, or a string for an int enum
            $case = null;
        }

        return $case
            ?? throw new \UnexpectedValueException(sprintf('has %s for $%s, which isn\'t a %s case', json_encode($value), $name, new \ReflectionClass($enum)->getShortName()));
    }

    /**
     * @param  class-string  $class
     * @return Entry
     */
    private static function entry(string $class): array
    {
        if (isset(self::$entries[$class])) {
            return self::$entries[$class];
        }

        $questions = [];
        $parameters = [];
        $expanded = [];
        foreach (self::parameters($class) as $parameter) {
            $name = $parameter->getName();
            $type = self::typeName($class, $parameter);
            $ask = self::ask($class, $parameter);
            if ($type === 'array') {
                $questions += self::caseQuestions($class, $parameter, $ask);
                $expanded[] = '$'.$name;
            } else {
                $questions[$name] = self::question($class, $parameter, $ask, $type);
            }
            $parameters[] = [
                'name' => $name,
                'type' => $type,
                'ask' => $ask,
                'scale' => self::isScale($type),
                'of' => $type === 'array' ? self::of($class, $parameter) : null,
                'nullable' => $parameter->allowsNull(),
            ];
        }

        if ($expanded !== [] && count($questions) > self::MAX_QUESTIONS) {
            throw new InvalidQuestionException(sprintf(
                '%s asks %d questions, but laya-serve answers at most %d per request. %s ask%s one question per enum case.',
                $class, count($questions), self::MAX_QUESTIONS, implode(', ', $expanded), count($expanded) === 1 ? 's' : '',
            ));
        }

        return self::$entries[$class] = ['questions' => $questions, 'parameters' => $parameters];
    }

    /**
     * @param  class-string  $class
     * @return list<\ReflectionParameter>
     */
    private static function parameters(string $class): array
    {
        $parameters = new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
        if ($parameters === []) {
            throw new InvalidQuestionException(sprintf('%s needs a constructor with at least one #[Ask] parameter.', $class));
        }

        return $parameters;
    }

    private static function question(string $class, \ReflectionParameter $parameter, Ask $ask, string $type): Question
    {
        self::assertNullable($class, $parameter, $ask, $type);
        $levels = self::attribute($parameter, Levels::class);

        if ($type !== 'bool' && $ask->threshold !== 0.5) {
            throw new InvalidQuestionException(sprintf('%s::$%s is neither a bool nor an array of enum cases, so #[Ask] can\'t take threshold.', $class, $parameter->getName()));
        }

        if ($type === 'bool' || ($type === 'float' && $levels === null)) {
            return Question::yesNo($ask->instructions, $ask->yes, $ask->no);
        }

        self::assertNoYesNo($class, $parameter, $ask);

        if (self::isScale($type)) {
            if ($levels !== null) {
                throw new InvalidQuestionException(sprintf('%s::$%s is a #[Scale] enum, so its cases are the levels; drop #[Levels].', $class, $parameter->getName()));
            }
            /** @var class-string<\BackedEnum> $type */
            $levels = [];
            foreach (new \ReflectionEnum($type)->getCases() as $case) {
                /** @var \ReflectionEnumBackedCase $case */
                $levels[] = self::description($case) ?? (string) $case->getBackingValue();
            }
            if ($levels === []) {
                throw new InvalidQuestionException(sprintf('%s::$%s is a #[Scale] enum without cases, so it has no levels.', $class, $parameter->getName()));
            }

            return Question::score($ask->instructions, $levels);
        }

        if ($type === 'int' || $type === 'float') {
            $levels ??= throw new InvalidQuestionException(sprintf('%s::$%s is an int, so it needs #[Levels(...)] to become a score question.', $class, $parameter->getName()));

            return Question::score($ask->instructions, $levels->levels);
        }

        if (is_subclass_of($type, \BackedEnum::class)) {
            $options = [];
            foreach (new \ReflectionEnum($type)->getCases() as $case) {
                /** @var \ReflectionEnumBackedCase $case */
                $options[$case->getBackingValue()] = self::description($case);
            }

            // The constructor, not Question::choice(): an int-backed enum 0..n would read as a list of labels.
            return new Question(QuestionType::Choice, $ask->instructions, $options);
        }

        throw new InvalidQuestionException(sprintf(
            '%s::$%s has type %s; laya answers from a fixed option set, so use a backed enum (choice, or score with #[Scale]), bool (yes/no), int with #[Levels] (score), float (P(yes), or the expected level with #[Levels]) or array with #[Of] (yes/no per enum case).',
            $class, $parameter->getName(), $type,
        ));
    }

    /**
     * One yes/no question per case of the #[Of] enum, with "{case}" filled in.
     *
     * @return array<string, Question>
     */
    private static function caseQuestions(string $class, \ReflectionParameter $parameter, Ask $ask): array
    {
        self::assertNullable($class, $parameter, $ask, 'array');
        self::assertNoYesNo($class, $parameter, $ask);
        $enum = self::of($class, $parameter);
        if (! str_contains($ask->instructions, '{case}')) {
            throw new InvalidQuestionException(sprintf('%s::$%s asks about each enum case, so its #[Ask] instructions need a {case} placeholder.', $class, $parameter->getName()));
        }

        $questions = [];
        foreach (new \ReflectionEnum($enum)->getCases() as $case) {
            /** @var \ReflectionEnumBackedCase $case */
            $value = $case->getBackingValue();
            $instructions = str_replace('{case}', self::description($case) ?? (string) $value, $ask->instructions);
            $questions[$parameter->getName().'.'.$value] = Question::yesNo($instructions);
        }

        return $questions;
    }

    /**
     * The cases whose P(yes) reaches the threshold, in declaration order; null when any answer is below minConfidence.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return list<\BackedEnum>|null
     */
    private static function cases(string $class, string $name, string $enum, Ask $ask, Result $result): ?array
    {
        $cases = [];
        foreach ($enum::cases() as $case) {
            $id = $name.'.'.$case->value;
            $answer = self::answer(sprintf('%s::$%s (question "%s")', $class, $name, $id), $id, $result, $result->yesNo(...), 'yes/no');
            if ($ask->minConfidence !== null && $answer->answerConfidence < $ask->minConfidence) {
                return null;
            }
            if ($answer->yes($ask->threshold)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /** @return class-string<\BackedEnum> */
    private static function of(string $class, \ReflectionParameter $parameter): string
    {
        $of = self::attribute($parameter, Of::class)
            ?? throw new InvalidQuestionException(sprintf('%s::$%s is an array, so it needs #[Of(SomeEnum::class)] to become a yes/no question per enum case.', $class, $parameter->getName()));

        if (! is_subclass_of($of->enum, \BackedEnum::class)) {
            throw new InvalidQuestionException(sprintf('%s::$%s has #[Of(%s)], but #[Of] needs a backed enum.', $class, $parameter->getName(), $of->enum));
        }

        return $of->enum;
    }

    private static function assertNullable(string $class, \ReflectionParameter $parameter, Ask $ask, string $type): void
    {
        if ($ask->minConfidence !== null && ! $parameter->allowsNull()) {
            throw new InvalidQuestionException(sprintf('%s::$%s sets minConfidence, so it must be nullable (?%s) to hold "unsure".', $class, $parameter->getName(), $type));
        }
    }

    private static function assertNoYesNo(string $class, \ReflectionParameter $parameter, Ask $ask): void
    {
        if ($ask->yes !== null || $ask->no !== null) {
            throw new InvalidQuestionException(sprintf('%s::$%s is not a bool, so #[Ask] can\'t take yes or no.', $class, $parameter->getName()));
        }
    }

    private static function description(\ReflectionEnumBackedCase $case): ?string
    {
        return ($case->getAttributes(Describe::class)[0] ?? null)?->newInstance()->description;
    }

    private static function ask(string $class, \ReflectionParameter $parameter): Ask
    {
        return self::attribute($parameter, Ask::class)
            ?? throw new InvalidQuestionException(sprintf('%s::$%s needs an #[Ask(...)] attribute with the question for the model.', $class, $parameter->getName()));
    }

    private static function typeName(string $class, \ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType) {
            throw new InvalidQuestionException(sprintf('%s::$%s needs a single declared type.', $class, $parameter->getName()));
        }

        return $type->getName();
    }

    /**
     * The answer to question $id, typed by $typed. A response that doesn't fit the decision class is the server's fault here,
     * so a missing answer or one of another type becomes a ServerException naming $subject, the parameter it was for.
     *
     * @template T of Answer
     *
     * @param  \Closure(string): T  $typed  $result->choice(...), ->score(...) or ->yesNo(...)
     * @return T
     */
    private static function answer(string $subject, string $id, Result $result, \Closure $typed, string $kind): Answer
    {
        try {
            return $typed($id);
        } catch (\OutOfBoundsException $e) {
            throw new ServerException(sprintf('The laya-serve response has no answer for %s.', $subject), 200, $e);
        } catch (\UnexpectedValueException $e) {
            throw new ServerException(sprintf('The laya-serve response answers %s with a %s, not a %s.', $subject, self::kind($result->get($id)), $kind), 200, $e);
        }
    }

    private static function kind(Answer $answer): string
    {
        if ($answer instanceof ChoiceAnswer) {
            return 'choice';
        }

        return $answer instanceof ScoreAnswer ? 'score' : 'yes/no';
    }

    private static function enumCase(string $subject, string $enum, string|int $choice): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $enum */
        $int = is_int($enum::cases()[0]->value); // a choice enum has cases, or entry() would have thrown

        try {
            return $enum::from($int ? (int) $choice : (string) $choice);
        } catch (\ValueError $e) {
            throw new ServerException(sprintf('The laya-serve response answers %s with "%s", which isn\'t a %s case.', $subject, $choice, new \ReflectionClass($enum)->getShortName()), 200, $e);
        }
    }

    /** Whether $type is a backed enum marked #[Scale]. Public for the fake, which answers its cases with their level. */
    public static function isScale(string $type): bool
    {
        return is_subclass_of($type, \BackedEnum::class) && new \ReflectionClass($type)->getAttributes(Scale::class) !== [];
    }

    private static function scaleCase(string $subject, string $enum, int $level): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $enum */
        return $enum::cases()[$level]
            ?? throw new ServerException(sprintf('The laya-serve response answers %s with level %d, which isn\'t a %s case.', $subject, $level, new \ReflectionClass($enum)->getShortName()), 200);
    }

    /**
     * @template A of object
     *
     * @param  class-string<A>  $attribute
     * @return A|null
     */
    private static function attribute(\ReflectionParameter $parameter, string $attribute): ?object
    {
        return ($parameter->getAttributes($attribute)[0] ?? null)?->newInstance();
    }
}
