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

    /** @param array<string, string|int|float|bool|\BackedEnum|null> $answers */
    public function __construct(private readonly array $answers) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            return $this->json(['status' => 'ok', 'device' => 'fake']);
        }

        $body = self::array(json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $questions = self::array($body['questions'] ?? null);
        $model = is_string($body['model'] ?? null) ? $body['model'] : null;
        $this->requests[] = ['state' => $body['state'] ?? null, 'questions' => $questions, 'model' => $model];

        $answers = [];
        foreach ($questions as $id => $question) {
            // predict() only sends string ids; the cast is for static analysis.
            $id = (string) $id; // @pest-mutate-ignore: RemoveStringCast
            if (! array_key_exists($id, $this->answers)) {
                throw new \LogicException(sprintf('Laya::fake() has no answer for question "%s". Register one: Laya::fake([\'%s\' => ...]).', $id, $id));
            }
            $answers[$id] = $this->answer($id, self::array($question), $this->answers[$id]);
        }

        return $this->json([
            'model' => 'laya-fake',
            'answers' => $answers,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'routing' => ['model' => $model ?? 'english', 'reason' => 'fake'],
        ]);
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
