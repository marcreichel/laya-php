<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel\Rules;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\Translator;
use MarcReichel\Laya\Answers\YesNoAnswer;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\TransportException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

/**
 * Validation rule backed by laya. Resolves Laya from the container at validation time, so Laya::fake() works.
 *
 *     'body' => ['required', 'string', Decides::that(Moderation::class, fn (Moderation $m) => ! $m->abusive)],
 *     'body' => [Decides::not('Does the text contain insults, hate speech or threats?')],
 */
final class Decides implements ValidationRule
{
    private ?string $message = null;

    /** @param Closure(Laya, string): bool $passes */
    private function __construct(private readonly Closure $passes, private bool $passWhenUnavailable = false) {}

    /**
     * Passes when $passes returns true for the decision laya makes about the value.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  Closure(T): bool  $passes
     */
    public static function that(string $class, Closure $passes): self
    {
        return new self(fn (Laya $laya, string $value): bool => $passes($laya->decide($value, $class)));
    }

    /** Passes when laya answers "yes" to the question. */
    public static function yes(string $question): self
    {
        return new self(fn (Laya $laya, string $value): bool => self::answer($laya, $value, $question)->yes());
    }

    /** Passes when laya answers "no" to the question. */
    public static function not(string $question): self
    {
        return new self(fn (Laya $laya, string $value): bool => self::answer($laya, $value, $question)->no());
    }

    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /** Let the value pass when laya-serve is unreachable or busy, instead of failing the validation. */
    public function passWhenUnavailable(): self
    {
        $this->passWhenUnavailable = true;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->passes($value)) {
            return;
        }

        /** @var Translator $translator */
        $translator = Container::getInstance()->make('translator');

        $fail($this->message ?? ($translator->has('validation.laya') ? 'validation.laya' : 'laya::validation.laya'))->translate();
    }

    private function passes(string $value): bool
    {
        try {
            return ($this->passes)(Container::getInstance()->make(Laya::class), $value);
        } catch (TransportException|ServerBusyException) {
            return $this->passWhenUnavailable;
        }
    }

    private static function answer(Laya $laya, string $value, string $question): YesNoAnswer
    {
        return $laya->predict($value, ['answer' => Question::yesNo($question)])->yesNo('answer');
    }
}
