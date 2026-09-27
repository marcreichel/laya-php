<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;

/**
 * Turns a decision class's constructor into questions, and a Result back into an instance.
 *
 * Mapping: backed enum => choice, bool => yes/no, int + #[Levels] => score.
 *
 * @internal
 */
final class DecisionMapper
{
    /**
     * @param  class-string  $class
     * @return array<string, Question>
     */
    public static function questions(string $class): array
    {
        $questions = [];
        foreach (self::parameters($class) as $parameter) {
            $questions[$parameter->getName()] = self::question($class, $parameter);
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
        $arguments = [];
        foreach (self::parameters($class) as $parameter) {
            $name = $parameter->getName();
            $type = self::typeName($class, $parameter);
            $arguments[$name] = match ($type) {
                'bool' => $result->yesNo($name)->yes(),
                'int' => $result->score($name)->level(),
                default => self::enumCase($type, $result->choice($name)->choice),
            };
        }

        return new $class(...$arguments);
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
        $ask = self::attribute($parameter, Ask::class)
            ?? throw new InvalidQuestionException(sprintf('%s::$%s needs an #[Ask(...)] attribute with the question for the model.', $class, $parameter->getName()));
        $type = self::typeName($class, $parameter);

        if ($type === 'bool') {
            return Question::yesNo($ask->instructions);
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
                $describe = $case->getAttributes(Describe::class)[0] ?? null;
                $options[$case->getBackingValue()] = $describe?->newInstance()->description;
            }

            // The constructor, not Question::choice(): an int-backed enum 0..n would read as a list of labels.
            return new Question(QuestionType::Choice, $ask->instructions, $options);
        }

        throw new InvalidQuestionException(sprintf(
            '%s::$%s has type %s; laya answers from a fixed option set, so use a backed enum (choice), bool (yes/no) or int with #[Levels] (score).',
            $class, $parameter->getName(), $type,
        ));
    }

    private static function typeName(string $class, \ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType) {
            throw new InvalidQuestionException(sprintf('%s::$%s needs a single declared type.', $class, $parameter->getName()));
        }

        return $type->getName();
    }

    private static function enumCase(string $enum, string|int $choice): \BackedEnum
    {
        /** @var class-string<\BackedEnum> $enum */
        $int = (string) new \ReflectionEnum($enum)->getBackingType() === 'int';

        return $enum::from($int ? (int) $choice : (string) $choice);
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
