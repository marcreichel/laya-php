<?php

declare(strict_types=1);

use MarcReichel\Laya\Question;

// Checks this client against TypeSafe's OpenAPI spec, so a change on either side shows up in CI:
//
//   curl -fsSL https://api.typesafe.ai/openapi.json -o build/openapi.json
//   LAYA_OPENAPI=build/openapi.json composer test:contract
//
// The requests are the ones this client actually sends, and the responses are TYPESAFE_RESPONSE,
// the fixture the rest of the suite proves the client reads correctly.
beforeEach(function () {
    $path = getenv('LAYA_OPENAPI');
    if (! $path) {
        $this->markTestSkipped('Set LAYA_OPENAPI to the path of TypeSafe\'s openapi.json to check the contract.');
    }
    $spec = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $this->spec = $spec;
    $this->operation = $spec['paths']['/v1/systemone']['post'] ?? null;
    expect($this->operation)->toBeArray('The spec has no POST /v1/systemone.');
});

/**
 * Follows $ref and splits allOf/anyOf/oneOf into the object schemas a value may match. allOf parts are merged.
 *
 * @return list<array<mixed>>
 */
function openApiBranches(array $spec, mixed $schema): array
{
    if (! is_array($schema)) {
        return [];
    }
    while (isset($schema['$ref'])) {
        $node = $spec;
        foreach (explode('/', substr($schema['$ref'], 2)) as $key) {
            $node = $node[str_replace(['~1', '~0'], ['/', '~'], $key)] ?? throw new LogicException("Unresolvable \$ref {$schema['$ref']}");
        }
        $schema = $node + array_diff_key($schema, ['$ref' => true]);
    }
    foreach (['oneOf', 'anyOf'] as $union) {
        if (isset($schema[$union])) {
            return array_merge(...array_map(fn ($part) => openApiBranches($spec, $part), $schema[$union]));
        }
    }
    if (isset($schema['allOf'])) {
        $merged = array_diff_key($schema, ['allOf' => true]);
        foreach ($schema['allOf'] as $part) {
            foreach (openApiBranches($spec, $part) as $branch) {
                $merged['properties'] = ($merged['properties'] ?? []) + ($branch['properties'] ?? []);
                $merged['required'] = array_merge($merged['required'] ?? [], $branch['required'] ?? []);
                $merged += $branch;
            }
        }

        return openApiBranches($spec, $merged);
    }
    // A nullable value (anyOf [X, null]) is X for the purposes of this check.
    if (($schema['type'] ?? null) === 'null') {
        return [];
    }

    return [$schema];
}

/**
 * The value schemas of a map, e.g. the answer schemas of "answers", keyed by the "type" each one declares.
 *
 * @return array<string, array<mixed>>
 */
function openApiByType(array $spec, array $map): array
{
    $byType = [];
    foreach (openApiBranches($spec, $map['additionalProperties'] ?? null) as $branch) {
        $type = openApiBranches($spec, $branch['properties']['type'] ?? null)[0] ?? [];
        $values = $type['enum'] ?? (array_key_exists('const', $type) ? [$type['const']] : []);
        expect($values)->not->toBeEmpty('A schema in the spec doesn\'t say which "type" it is: '.json_encode($branch));
        foreach ($values as $value) {
            $byType[$value] = $branch;
        }
    }

    return $byType;
}

/** Whether $schema accepts null: an untyped schema, type null (3.1) or nullable (3.0), directly or in a union. */
function openApiAllowsNull(array $spec, mixed $schema): bool
{
    if (! is_array($schema)) {
        return false;
    }
    if (isset($schema['$ref'])) {
        return openApiAllowsNull($spec, openApiBranches($spec, ['$ref' => $schema['$ref']])[0] ?? ['type' => 'null']);
    }
    foreach (['oneOf', 'anyOf'] as $union) {
        if (isset($schema[$union])) {
            return array_any($schema[$union], fn ($part) => openApiAllowsNull($spec, $part));
        }
    }
    if (isset($schema['allOf'])) {
        return array_all($schema['allOf'], fn ($part) => openApiAllowsNull($spec, $part));
    }

    if (($schema['nullable'] ?? false) === true) {
        return true;
    }
    if (array_key_exists('const', $schema)) {
        return $schema['const'] === null;
    }
    if (isset($schema['enum'])) {
        return in_array(null, $schema['enum'], true);
    }

    return in_array('null', (array) ($schema['type'] ?? 'null'), true);
}

/** Fails when $value has keys $schema doesn't declare, lacks keys it requires, or sends null where it isn't allowed. */
function expectMatchesSchema(array $spec, mixed $schema, array $value, string $what): void
{
    $branches = openApiBranches($spec, $schema);
    expect($branches)->not->toBeEmpty("The spec has no schema for {$what}.");
    $properties = array_merge(...array_map(fn ($b) => $b['properties'] ?? [], $branches));
    $required = array_merge(...array_map(fn ($b) => $b['required'] ?? [], $branches));

    expect(array_diff(array_keys($value), array_keys($properties)))->toBe([], "{$what} has fields the spec doesn't declare.")
        ->and(array_values(array_diff($required, array_keys($value))))->toBe([], "{$what} lacks fields the spec requires.");
    foreach ($value as $key => $field) {
        if ($field === null) {
            expect(openApiAllowsNull($spec, $properties[$key]))->toBeTrue("{$what} sends null for \"{$key}\", which the spec doesn't allow: ".json_encode($properties[$key]));
        }
    }
}

it('sends requests that match the spec', function () {
    $schema = $this->operation['requestBody']['content']['application/json']['schema'] ?? null;
    $request = openApiBranches($this->spec, $schema)[0] ?? [];
    $questions = openApiByType($this->spec, openApiBranches($this->spec, $request['properties']['questions'] ?? null)[0] ?? []);

    $sent = [];
    $laya = layaRespondingWith(200, TYPESAFE_RESPONSE, $sent);
    $laya->predict('Billed twice, refund or I cancel.', questions());
    $laya->predict(['subject' => 'Refund', 'body' => 'Billed twice.'], [
        'churn' => Question::yesNo('Threatens to cancel?', yes: 'says they will leave', no: 'happy customer'),
        'department' => Question::choice('Which department?', ['billing', 'technical']),
    ]);

    expect(array_keys($questions))->toContain('choice', 'score', 'noul');
    foreach ($sent as $request) {
        $body = json_decode((string) $request->getBody(), true);
        expectMatchesSchema($this->spec, $schema, $body, 'The request');
        foreach ($body['questions'] as $id => $question) {
            expectMatchesSchema($this->spec, $questions[$question['type']], $question, "Question \"{$id}\"");
        }
    }
})->group('contract');

it('reads every answer type the spec can send, from responses that match it', function () {
    $schema = $this->operation['responses']['200']['content']['application/json']['schema'] ?? null;
    $response = openApiBranches($this->spec, $schema)[0] ?? [];
    $answers = openApiByType($this->spec, openApiBranches($this->spec, $response['properties']['answers'] ?? null)[0] ?? []);

    // Answer::fromArray() throws on any other type, so a new one in the spec needs support here first.
    expect(array_keys($answers))->toEqualCanonicalizing(['choice', 'score', 'noul']);

    expectMatchesSchema($this->spec, $schema, TYPESAFE_RESPONSE, 'TYPESAFE_RESPONSE');
    foreach (TYPESAFE_RESPONSE['answers'] as $id => $answer) {
        expectMatchesSchema($this->spec, $answers[$answer['type']], $answer, "Answer \"{$id}\"");
    }
})->group('contract');

it('sends the fields the client reads, or ones it knows how to do without', function () {
    $schema = $this->operation['responses']['200']['content']['application/json']['schema'] ?? null;
    $response = openApiBranches($this->spec, $schema)[0] ?? [];
    $answers = openApiByType($this->spec, openApiBranches($this->spec, $response['properties']['answers'] ?? null)[0] ?? []);

    // What each answer class reads. answer_confidence falls back to confidence, and a yes/no confidence to max(P(yes), P(no)).
    $reads = ['choice' => ['choice', 'probabilities', 'confidence'], 'score' => ['score', 'probabilities', 'legend', 'confidence'], 'noul' => ['noul']];
    foreach ($reads as $type => $fields) {
        expect(array_keys($answers[$type]['properties'] ?? []))->toContain(...$fields);
    }
})->group('contract');
