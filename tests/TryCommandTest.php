<?php

declare(strict_types=1);

use App\Decisions\Area;
use App\Decisions\Areas;
use App\Decisions\Broken;
use App\Decisions\Code;
use App\Decisions\Coded;
use App\Decisions\Codes;
use App\Decisions\Department;
use App\Decisions\Tagged;
use App\Decisions\Triage;
use App\Decisions\Urgency;
use App\Decisions\Urgent;
use Illuminate\Support\Facades\Artisan;
use MarcReichel\Laya\Laravel\LayaServiceProvider;
use MarcReichel\Laya\Laravel\TryCommand;
use MarcReichel\Laya\Laya;
use Orchestra\Testbench\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__.'/Fixtures/Decisions.php';

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(LayaServiceProvider::class);
});

/** Runs laya:try with $stdin as its STDIN, as `echo ... | php artisan laya:try` would. */
function tryWithStdin(string $stdin, array $parameters = []): array
{
    $command = app(TryCommand::class);
    $command->setLaravel(app());
    $input = new ArrayInput(['class' => 'Decisions\Triage'] + $parameters);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);
    $input->setStream($stream);
    $output = new BufferedOutput;

    return [$command->run($input, $output), $output->fetch()];
}

it('shows each answer with its probabilities, most likely first', function () {
    $sent = [];
    app()->instance(Laya::class, layaRespondingWith(200, LAYA_RESPONSE, $sent));

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'Billed twice. Refund it or we cancel.']))->toBe(0)
        ->and(Artisan::output())->toBe(<<<'TXT'
            department: Which department should handle this?
              → Department::Billing (answer confidence 0.90)
                billing    ██████████████████░░  0.91
                technical  █░░░░░░░░░░░░░░░░░░░  0.06
                other      █░░░░░░░░░░░░░░░░░░░  0.03

            urgency: How urgent is this?
              → 2 (answer confidence 0.75, score 1.74)
                2: blocking    ████████████████░░░░  0.79
                1: soon        ███░░░░░░░░░░░░░░░░░  0.16
                0: not urgent  █░░░░░░░░░░░░░░░░░░░  0.05

            churn: Does the user threaten to cancel?
              → null (answer confidence 0.80)
                yes  █████████████████░░░  0.83
                no   ███░░░░░░░░░░░░░░░░░  0.17

            Routed to english, 42 input tokens

            TXT);

    $body = json_decode((string) $sent[0]->getBody(), true);
    expect($body['state'])->toBe('Billed twice. Refund it or we cancel.')
        ->and(array_keys($body['questions']))->toBe(['department', 'urgency', 'churn'])
        ->and($body['model'])->toBe(Laya::AUTO_MODEL)
        ->and($body)->not->toHaveKeys(['max_len', 'head_max_len']);
});

it('runs against Laya::fake(), taking the class with or without App\\', function (string $class) {
    $fake = Laya::fake(['department' => Department::Technical, 'urgency' => 0, 'churn' => true]);

    expect(Artisan::call('laya:try', ['class' => $class, 'state' => 'The app crashes on start.']))->toBe(0)
        ->and(Artisan::output())->toContain('→ Department::Technical (answer confidence 1.00)')
        ->toContain('→ 0 (answer confidence 1.00, score 0.00)')
        ->toContain('→ true (answer confidence 1.00)')
        ->toContain('technical  ████████████████████  1.00');
    $fake->assertDecided(Triage::class, fn ($state, $model) => $state === 'The app crashes on start.' && $model === null);
})->with(['App\Decisions\Triage', '\App\Decisions\Triage', '\Decisions\Triage']);

it('shows false and a checkpoint it could not tell', function () {
    $response = ['answers' => [
        'department' => ['type' => 'choice', 'choice' => 'other'],
        'urgency' => ['type' => 'score', 'score' => 1.0, 'probabilities' => [0.3, 0.7]],
        'churn' => ['type' => 'noul', 'noul' => 0.1, 'answer_confidence' => 0.9],
    ]];
    app()->instance(Laya::class, layaRespondingWith(200, $response));

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'x']))->toBe(0)
        ->and(Artisan::output())->toBe(<<<'TXT'
            department: Which department should handle this?
              → Department::Other (answer confidence 0.00)

            urgency: How urgent is this?
              → 1 (answer confidence 0.00, score 1.00)
                1  ██████████████░░░░░░  0.70
                0  ██████░░░░░░░░░░░░░░  0.30

            churn: Does the user threaten to cancel?
              → false (answer confidence 0.90)
                no   ██████████████████░░  0.90
                yes  ██░░░░░░░░░░░░░░░░░░  0.10

            Routed to an unknown checkpoint, 0 input tokens

            TXT);
});

it('escapes console tags in questions and labels', function () {
    Laya::fake(['tag' => '<info>']);

    Artisan::call('laya:try', ['class' => Tagged::class, 'state' => 'x']);

    expect(Artisan::output())->toContain('tag: Which <comment>tag</comment>?')->toContain('    <info>  ████████████████████  1.00');
});

it('warns when laya cut the text off', function () {
    app()->instance(Laya::class, layaRespondingWith(200, ['usage' => ['input_tokens' => 512, 'truncated' => true]] + LAYA_RESPONSE));

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'x']))->toBe(0)
        ->and(Artisan::output())->toEndWith("Routed to english, 512 input tokens\nlaya cut the text off at its token budget; raise --max-len to read all of it.\n");
});

it('forwards the model and token budgets', function () {
    $sent = [];
    app()->instance(Laya::class, layaRespondingWith(200, LAYA_RESPONSE, $sent));

    Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'x', '--model' => 'multilingual', '--max-len' => '2048', '--head-max-len' => '1']);

    expect(json_decode((string) $sent[0]->getBody(), true))->toMatchArray(['model' => 'multilingual', 'max_len' => 2048, 'head_max_len' => 1]);
});

it('prints JSON', function () {
    app()->instance(Laya::class, layaRespondingWith(200, ['usage' => ['input_tokens' => 512, 'truncated' => true]] + LAYA_RESPONSE));

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'x', '--json' => true]))->toBe(0);

    $output = Artisan::output();
    expect(json_decode($output, true))->toBe([
        'class' => Triage::class,
        'routed_model' => 'english',
        'input_tokens' => 512,
        'truncated' => true,
        'parameters' => [
            'department' => [
                'question' => 'Which department should handle this?',
                'type' => 'choice',
                'value' => 'billing',
                'answer_confidence' => 0.9,
                'probabilities' => ['billing' => 0.91, 'technical' => 0.06, 'other' => 0.03],
            ],
            'urgency' => [
                'question' => 'How urgent is this?',
                'type' => 'score',
                'value' => 2,
                'answer_confidence' => 0.75,
                'probabilities' => ['2: blocking' => 0.79, '1: soon' => 0.16, '0: not urgent' => 0.05],
                'score' => 1.74,
            ],
            'churn' => [
                'question' => 'Does the user threaten to cancel?',
                'type' => 'noul',
                'value' => null,
                'answer_confidence' => 0.8,
                'probabilities' => ['yes' => 0.83, 'no' => 0.17],
            ],
        ],
    ])->and($output)->toStartWith("{\n    \"class\": \"App\\\\Decisions\\\\Triage\",\n")
        ->not->toContain('laya cut');
});

it('shows numeric labels', function () {
    Laya::fake(['code' => Code::One]);

    expect(Artisan::call('laya:try', ['class' => Coded::class, 'state' => 'x']))->toBe(0)
        ->and(Artisan::output())->toContain("  → Code::One (answer confidence 1.00)\n    1  ████████████████████  1.00\n    0  ░░░░░░░░░░░░░░░░░░░░  0.00\n");
});

it('shows a #[Scale] enum as a score with its case', function () {
    Laya::fake(['urgency' => Urgency::High]);

    expect(Artisan::call('laya:try', ['class' => Urgent::class, 'state' => 'x']))->toBe(0)
        ->and(Artisan::output())->toContain("urgency: How urgent is this?\n  → Urgency::High (answer confidence 1.00, score 1.00)\n    1: high      ████████████████████  1.00\n    0: can wait  ░░░░░░░░░░░░░░░░░░░░  0.00\n");
});

it('keeps numeric labels an object, and whole numbers floats, in JSON', function () {
    Laya::fake(['code' => Code::Zero]);

    Artisan::call('laya:try', ['class' => Coded::class, 'state' => 'x', '--json' => true]);

    expect(Artisan::output())->toContain(<<<'JSON'
                    "value": 0,
                    "answer_confidence": 1.0,
                    "probabilities": {
                        "0": 1.0,
                        "1": 0.0
                    }
        JSON);
});

it('shows whether each #[Of] case is listed', function () {
    Laya::fake(['areas' => [Area::Docs], 'churn' => false]);

    expect(Artisan::call('laya:try', ['class' => Areas::class, 'state' => 'x']))->toBe(0)
        ->and(Artisan::output())->toBe(<<<'TXT'
            areas.billing: Is this about invoices, refunds?
              → false (answer confidence 1.00)
                no   ████████████████████  1.00
                yes  ░░░░░░░░░░░░░░░░░░░░  0.00

            areas.docs.api: Is this about docs.api?
              → true (answer confidence 1.00)
                yes  ████████████████████  1.00
                no   ░░░░░░░░░░░░░░░░░░░░  0.00

            churn: Does the user threaten to cancel?
              → false (answer confidence 1.00)
                no   ████████████████████  1.00
                yes  ░░░░░░░░░░░░░░░░░░░░  0.00

            Routed to multilingual, 0 input tokens

            TXT);
});

it('shows int-backed #[Of] cases', function () {
    Laya::fake(['codes' => [Code::One]]);

    Artisan::call('laya:try', ['class' => Codes::class, 'state' => 'x']);

    expect(Artisan::output())->toContain("codes.0: Is the code 0?\n  → false")->toContain("codes.1: Is the code 1?\n  → true");
});

it('shows unsure #[Of] cases as null, in JSON too', function () {
    Laya::fake(['areas' => null, 'churn' => true]);

    Artisan::call('laya:try', ['class' => Areas::class, 'state' => 'x', '--json' => true]);

    expect(array_map(fn (array $p) => $p['value'], json_decode(Artisan::output(), true)['parameters']))
        ->toBe(['areas.billing' => null, 'areas.docs.api' => null, 'churn' => true]);
});

it('reads the text from a file', function () {
    $fake = Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => false]);
    $file = tempnam(sys_get_temp_dir(), 'laya');
    file_put_contents($file, "Refund me.\n");

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', '--file' => $file]))->toBe(0);
    $fake->assertPredicted(fn ($state) => $state === "Refund me.\n");
    unlink($file);
});

it('prefers the argument over --file', function () {
    $fake = Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => false]);

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'Argument.', '--file' => '/does/not/exist']))->toBe(0);
    $fake->assertPredicted(fn ($state) => $state === 'Argument.');
});

it('reads the text from STDIN', function () {
    $fake = Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => false]);

    [$code, $output] = tryWithStdin("Piped in.\n");

    expect($code)->toBe(0)->and($output)->toContain('department: Which department');
    $fake->assertPredicted(fn ($state) => $state === "Piped in.\n");
});

it('fails clearly on bad input', function (array $parameters, string $message) {
    $fake = Laya::fake(['department' => 'billing', 'urgency' => 1, 'churn' => false]);

    expect(Artisan::call('laya:try', $parameters + ['state' => 'x']))->toBe(1)
        ->and(Artisan::output())->toBe($message."\n");
    $fake->assertNothingPredicted();
})->with([
    'unknown class' => [['class' => 'Decisions\Missing'], 'Class "Decisions\Missing" not found, neither as given nor under App\.'],
    'invalid decision class' => [['class' => Broken::class], 'App\Decisions\Broken::$name has type string; laya answers from a fixed option set, so use a backed enum (choice, or score with #[Scale]), bool (yes/no), int with #[Levels] (score) or array with #[Of] (yes/no per enum case).'],
    'unreadable file' => [['class' => 'Decisions\Triage', 'state' => null, '--file' => '/does/not/exist'], 'Cannot read "/does/not/exist".'],
    'a directory' => [['class' => 'Decisions\Triage', 'state' => null, '--file' => __DIR__], sprintf('Cannot read "%s".', __DIR__)],
    'blank text' => [['class' => 'Decisions\Triage', 'state' => " \n"], 'No text to classify. Pass it as an argument, with --file, or on STDIN.'],
    'unknown model' => [['class' => 'Decisions\Triage', '--model' => 'klingon'], 'Unknown model "klingon". Use one of: english, multilingual, typed-decisions.'],
    'zero max-len' => [['class' => 'Decisions\Triage', '--max-len' => '0'], '--max-len must be a positive integer.'],
    'non-numeric head-max-len' => [['class' => 'Decisions\Triage', '--head-max-len' => '1k'], '--head-max-len must be a positive integer.'],
]);

it('fails when STDIN is empty', function () {
    Laya::fake()->assertNothingPredicted();

    [$code, $output] = tryWithStdin('');

    expect($code)->toBe(1)->and($output)->toBe("No text to classify. Pass it as an argument, with --file, or on STDIN.\n");
});

it('fails when laya-serve is unreachable', function () {
    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};
        }
    };
    app()->instance(Laya::class, new Laya('http://laya.local', httpClient: $client));

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'x']))->toBe(1)
        ->and(Artisan::output())->toBe("Could not reach laya-serve at http://laya.local: Connection refused\n");
});

it('fails clearly when the response does not fit the decision class', function (array $answers, string $message) {
    app()->instance(Laya::class, layaRespondingWith(200, ['answers' => array_filter($answers + LAYA_RESPONSE['answers'])] + LAYA_RESPONSE));

    expect(Artisan::call('laya:try', ['class' => 'Decisions\Triage', 'state' => 'x']))->toBe(1)
        ->and(Artisan::output())->toBe($message."\n");
})->with([
    'missing answer' => [['department' => null], 'The laya-serve response has no answer for App\Decisions\Triage::$department.'],
    'another answer type' => [['urgency' => ['type' => 'choice', 'choice' => 'billing']], 'The laya-serve response answers App\Decisions\Triage::$urgency with a choice, not a score.'],
    'unknown label' => [['department' => ['type' => 'choice', 'choice' => 'refunds']], 'The laya-serve response answers App\Decisions\Triage::$department with "refunds", which isn\'t a Department case.'],
]);

it('fails clearly when an #[Of] case answer is missing', function () {
    app()->instance(Laya::class, layaRespondingWith(200, ['answers' => ['areas.billing' => ['type' => 'noul', 'noul' => 0.9], 'churn' => ['type' => 'noul', 'noul' => 0.1]]]));

    expect(Artisan::call('laya:try', ['class' => Areas::class, 'state' => 'x']))->toBe(1)
        ->and(Artisan::output())->toBe("The laya-serve response has no answer for App\\Decisions\\Areas::\$areas (question \"areas.docs.api\").\n");
});
