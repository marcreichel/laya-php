<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use MarcReichel\Laya\DecisionMapper;
use MarcReichel\Laya\Exceptions\InvalidDecisionCastException;

/**
 * Stores a decision object in a JSON column: `'triage' => AsDecision::of(Triage::class)`.
 * Enums are stored by backing value; reading goes through the constructor, so readonly classes work.
 */
final class AsDecision implements Castable
{
    /** @param  class-string  $class */
    public static function of(string $class): string
    {
        return self::class.':'.$class;
    }

    /**
     * @param  array{0?: class-string}  $arguments
     * @return CastsAttributes<object|null, object|null>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        $class = $arguments[0] ?? throw new \InvalidArgumentException('AsDecision needs a decision class: AsDecision::of(Triage::class).');

        return new readonly class($class) implements CastsAttributes
        {
            /** @param  class-string  $class */
            public function __construct(private string $class) {}

            /** @param  string|null  $value */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?object
            {
                if ($value === null) {
                    return null;
                }

                try {
                    $data = json_decode($value, true);
                    if (! is_array($data)) {
                        throw new \UnexpectedValueException('is not a JSON object');
                    }

                    return DecisionMapper::fromArray($this->class, $data);
                } catch (\UnexpectedValueException $e) {
                    throw new InvalidDecisionCastException(sprintf('%s::$%s holds a value that doesn\'t fit %s: it %s.', $model::class, $key, $this->class, $e->getMessage()), previous: $e);
                }
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                if ($value === null) {
                    return null;
                }
                if (! $value instanceof $this->class) {
                    throw new InvalidDecisionCastException(sprintf('%s::$%s takes a %s, got %s.', $model::class, $key, $this->class, get_debug_type($value)));
                }

                return json_encode(DecisionMapper::toArray($this->class, $value), JSON_THROW_ON_ERROR);
            }
        };
    }
}
