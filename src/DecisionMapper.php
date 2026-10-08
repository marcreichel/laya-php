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
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\ServerException;

/**
 * Turns a decision class's constructor into questions, and a Result back into an instance.
 *
 * Mapping: backed enum => choice, bool => yes/no, int + #[Levels] => score,
 * array + #[Of(Enum::class)] => one yes/no per case, with ids such as "topics.billing".
 * A nullable parameter with #[Ask(minConfidence: ...)] is null when laya is unsure.
 *
 * @internal
 */
final class DecisionMapper
{
    /** laya-serve refuses requests with more questions. Pest can't cover a constant; the 64/65-question tests pin it. */
    private const int MAX_QUESTIONS = 64; // @pest-mutate-ignore

    /**
     * @param  class-string  $class
     * @return array<string, Question>
     */
    public static function questions(string $class): array
    {
        $questions = [];
        $expanded = [];
        foreach (self::parameters($class) as $parameter) {
            if (self::typeName($class, $parameter) === 'array') {
                $questions += self::caseQuestions($class, $parameter);
                $expanded[] = '$'.$parameter->getName();
            } else {
                $questions[$parameter->getName()] = self::question($class, $parameter);
            }
        }

        if ($expanded !== [] && count($questions) > self::MAX_QUESTIONS) {
            throw new InvalidQuestionException(sprintf(
                '%s asks %d questions, but laya-serve answers at most %d per request. %s ask%s one question per enum case.',
                $class, count($questions), self::MAX_QUESTIONS, implode(', ', $expanded), count($expanded) === 1 ? 's' : '',
            ));
        }

        return $questions;
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
     * @return array<string, bool|int|\BackedEnum|list<\BackedEnum>|null>
     */
    public static function values(string $class, Result $result): array
    {
        $arguments = [];
        foreach (self::parameters($class) as $parameter) {
            $name = $parameter->getName();
            $type = self::typeName($class, $parameter);
            $ask = self::ask($class, $parameter);
            if ($type === 'array') {
                $arguments[$name] = self::cases($class, $parameter, $ask, $result);

                continue;
            }
            $subject = $class.'::$'.$name;
            $answer = match ($type) {
                'bool' => self::answer($subject, $name, $result, $result->yesNo(...), 'yes/no'),
                'int' => self::answer($subject, $name, $result, $result->score(...), 'score'),
                default => self::answer($subject, $name, $result, $result->choice(...), 'choice'),
            };
            if ($ask->minConfidence !== null && $answer->answerConfidence < $ask->minConfidence) {
                $arguments[$name] = null;

                continue;
            }
            $arguments[$name] = match (true) {
                $answer instanceof YesNoAnswer => $answer->yes($ask->threshold),
                $answer instanceof ScoreAnswer => $answer->level(),
                default => self::enumCase($subject, $type, $answer->choice),
            };
        }

        return $arguments;
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

    private static function question(string $class, \ReflectionParameter $parameter): Question
    {
        $ask = self::ask($class, $parameter);
        $type = self::typeName($class, $parameter);

        self::assertNullable($class, $parameter, $ask, $type);

        if ($type === 'bool') {
            return Question::yesNo($ask->instructions, $ask->yes, $ask->no);
        }

        self::assertNoYesNo($class, $parameter, $ask);

        if ($ask->threshold !== 0.5) {
            throw new InvalidQuestionException(sprintf('%s::$%s is neither a bool nor an array of enum cases, so #[Ask] can\'t take threshold.', $class, $parameter->getName()));
        }

        if ($type === 'int') {
            $levels = self::attribute($parameter, Levels::class)
                ?? throw new InvalidQuestionException(sprintf('%s::$%s is an int, so it needs #[Levels(...)] to become a score question.', $class, $parameter->getName()));

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
            '%s::$%s has type %s; laya answers from a fixed option set, so use a backed enum (choice), bool (yes/no), int with #[Levels] (score) or array with #[Of] (yes/no per enum case).',
            $class, $parameter->getName(), $type,
        ));
    }

    /**
     * One yes/no question per case of the #[Of] enum, with "{case}" filled in.
     *
     * @return array<string, Question>
     */
    private static function caseQuestions(string $class, \ReflectionParameter $parameter): array
    {
        $ask = self::ask($class, $parameter);
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
     * @return list<\BackedEnum>|null
     */
    private static function cases(string $class, \ReflectionParameter $parameter, Ask $ask, Result $result): ?array
    {
        $cases = [];
        foreach (self::of($class, $parameter)::cases() as $case) {
            $id = $parameter->getName().'.'.$case->value;
            $answer = self::answer(sprintf('%s::$%s (question "%s")', $class, $parameter->getName(), $id), $id, $result, $result->yesNo(...), 'yes/no');
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
        return match (true) {
            $answer instanceof ChoiceAnswer => 'choice',
            $answer instanceof ScoreAnswer => 'score',
            default => 'yes/no',
        };
    }

    private static function enumCase(string $subject, string $enum, string|int $choice): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $enum */
        $int = (string) new \ReflectionEnum($enum)->getBackingType() === 'int';

        try {
            return $enum::from($int ? (int) $choice : (string) $choice);
        } catch (\ValueError $e) {
            throw new ServerException(sprintf('The laya-serve response answers %s with "%s", which isn\'t a %s case.', $subject, $choice, new \ReflectionClass($enum)->getShortName()), 200, $e);
        }
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
