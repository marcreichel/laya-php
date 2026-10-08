<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Testing;

use Http\Discovery\Psr17FactoryDiscovery;
use MarcReichel\Laya\Laya;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for laya-serve behind {@see Laya::fake()}: builds laya-shaped
 * responses from registered answers, so the real request/response path still runs.
 *
 * @internal
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{state: mixed, questions: array<array-key, mixed>, model: ?string}> */
    public private(set) array $requests = [];

    /** @param array<string, string|int|float|bool|\BackedEnum|list<string|int|\BackedEnum>|null> $answers */
    public function __construct(private readonly array $answers) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            return $this->json(['status' => 'ok', 'device' => 'fake']);
        }

        $body = self::array(json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $questions = self::array($body['questions'] ?? null);
        // Assertions get null for "let laya route", as callers wrote it.
        $model = is_string($body['model'] ?? null) && $body['model'] !== Laya::AUTO_MODEL ? $body['model'] : null;

        $minConfidence = $body['min_confidence'] ?? null;

        // A batch records each state as its own prediction, so assertions don't care whether code batched.
        if (str_ends_with($request->getUri()->getPath(), '/batch')) {
            return $this->json(['results' => array_map(fn (mixed $state) => $this->predict($state, $questions, $model, $minConfidence), self::array($body['states'] ?? null))]);
        }

        return $this->json($this->predict($body['state'] ?? null, $questions, $model, $minConfidence));
    }

    /**
     * @param  array<mixed>  $questions
     * @return array<string, mixed>
     */
    private function predict(mixed $state, array $questions, ?string $model, mixed $minConfidence): array
    {
        $this->requests[] = ['state' => $state, 'questions' => $questions, 'model' => $model];

        $answers = [];
        foreach ($questions as $id => $question) {
            // predict() only sends string ids; the cast is for static analysis.
            $id = (string) $id; // @pest-mutate-ignore: RemoveStringCast
            $answer = $this->answer($id, self::array($question), $this->registered($id));
            $answers[$id] = $minConfidence === null ? $answer : self::gate($answer, $minConfidence);
        }

        return [
            'model' => 'laya-fake',
            'answers' => $answers,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'routing' => ['model' => $model ?? 'multilingual', 'reason' => 'fake'],
        ];
    }

    /**
     * The answer registered for $id. A decision class's "topics.billing" also takes a list of cases
     * registered as "topics": yes when it holds billing, unsure when it is null.
     */
    private function registered(string $id): string|int|float|bool|\BackedEnum|null
    {
        if (array_key_exists($id, $this->answers) && ! is_array($this->answers[$id])) {
            return $this->answers[$id];
        }

        /** @var array{string, ?string} $parts */
        $parts = explode('.', $id, 2) + [1 => null];
        [$parameter, $case] = $parts;
        if ($case !== null && array_key_exists($parameter, $this->answers)) {
            $cases = $this->answers[$parameter];
            if ($cases === null) {
                return null;
            }
            if (is_array($cases)) {
                return in_array($case, array_map(fn (string|int|\BackedEnum $c) => (string) ($c instanceof \BackedEnum ? $c->value : $c), $cases), true);
            }
        }

        throw new \LogicException(sprintf('Laya::fake() has no answer for question "%s". Register one: Laya::fake([\'%s\' => ...]).', $id, $id));
    }

    /**
     * @param  array<mixed>  $question
     * @return array<string, mixed>
     */
    private function answer(string $id, array $question, string|int|float|bool|\BackedEnum|null $value): array
    {
        $value = $value instanceof \BackedEnum ? $value->value : $value;
        $criteria = self::array($question['criteria'] ?? []);

        if ($value === null) {
            return $this->unsure($question, $criteria);
        }

        switch ($question['type'] ?? null) {
            case 'choice':
                $labels = array_map(strval(...), array_keys($criteria));
                if (! in_array((string) $value, $labels, true)) {
                    throw new \LogicException(sprintf('Fake answer "%s" for "%s" is not one of its options: %s.', $value, $id, implode(', ', $labels)));
                }

                return ['type' => 'choice', 'choice' => (string) $value, 'confidence' => 1.0, 'answer_confidence' => 1.0,
                    'probabilities' => array_combine($labels, array_map(fn ($l) => $l === (string) $value ? 1.0 : 0.0, $labels))];

            case 'score':
                if (! is_int($value) || ! isset($criteria[$value])) {
                    throw new \LogicException(sprintf('Fake answer for score question "%s" must be a level index from 0 to %d.', $id, count($criteria) - 1));
                }

                return ['type' => 'score', 'score' => (float) $value, 'confidence' => 1.0, 'answer_confidence' => 1.0,
                    'legend' => $criteria,
                    'probabilities' => array_map(fn ($i) => $i === $value ? 1.0 : 0.0, array_keys($criteria))];

            default:
                if (is_string($value)) {
                    throw new \LogicException(sprintf('Fake answer for yes/no question "%s" must be a bool or a probability.', $id));
                }
                $p = (float) $value;

                return ['type' => 'noul', 'noul' => $p, 'confidence' => max($p, 1 - $p), 'answer_confidence' => max($p, 1 - $p)];
        }
    }

    /**
     * laya-serve's abstention report. The fake's answers always carry an answer_confidence, so none is unevaluated.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private static function gate(array $answer, mixed $minConfidence): array
    {
        /** @var float|int $threshold Laya validated it before sending: a threshold, or a map of them with a fallback of 0.0 */
        $threshold = is_array($minConfidence) ? ($minConfidence[self::bucket($answer)] ?? $minConfidence['default'] ?? 0.0) : $minConfidence;
        $threshold = (float) $threshold;
        /** @var float $confidence answer() always sets it */
        $confidence = $answer['answer_confidence'];
        $low = $confidence < $threshold;

        return $answer + ($low ? ['low_confidence' => true] : []) + ['abstention' => $low ? 'abstained' : 'passed', 'abstention_threshold' => $threshold];
    }

    /**
     * The answer's bucket in a minConfidence map, by type and option count: "choice:3-5", "noul:2", ...
     *
     * @param  array<string, mixed>  $answer
     */
    private static function bucket(array $answer): string
    {
        // Yes/no answers carry no probabilities; they have two options.
        if (! is_array($answer['probabilities'] ?? null)) {
            return 'noul:2';
        }
        /** @var string $type */
        $type = $answer['type'];
        $options = count($answer['probabilities']);
        foreach ([2 => '2', 5 => '3-5', 10 => '6-10'] as $max => $size) {
            if ($options <= $max) {
                return $type.':'.$size;
            }
        }

        return $type.':11+';
    }

    /**
     * null means "laya isn't sure": even probabilities and zero confidence.
     *
     * @param  array<mixed>  $question
     * @param  array<mixed>  $criteria
     * @return array<string, mixed>
     */
    private function unsure(array $question, array $criteria): array
    {
        $none = ['confidence' => 0.0, 'answer_confidence' => 0.0];

        return match ($question['type'] ?? null) {
            'choice' => ['type' => 'choice', 'choice' => (string) array_key_first($criteria)] + $none
                + ['probabilities' => array_fill_keys(array_keys($criteria), 1 / count($criteria))],
            'score' => ['type' => 'score', 'score' => (count($criteria) - 1) / 2.0] + $none
                + ['legend' => $criteria, 'probabilities' => array_fill(0, count($criteria), 1 / count($criteria))],
            default => ['type' => 'noul', 'noul' => 0.5] + $none,
        };
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : throw new \LogicException('Laya::fake() received a malformed request.');
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): ResponseInterface
    {
        return Psr17FactoryDiscovery::findResponseFactory()->createResponse()
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }
}
