<?php

declare(strict_types=1);

use App\Decisions\Triage;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use MarcReichel\Laya\Evaluation;
use MarcReichel\Laya\EvaluationCli;
use MarcReichel\Laya\Laravel\LayaServiceProvider;
use MarcReichel\Laya\Laya;
use Orchestra\Testbench\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__.'/Fixtures/Decisions.php';

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(LayaServiceProvider::class);
});

/**
 * Runs vendor/bin/laya-eval with $arguments.
 *
 * @return array{int, string, string} exit code, STDOUT, STDERR
 */
function laya_eval(array $arguments, array $env = [], ?ClientInterface $http = null): array
{
    [$stdout, $stderr] = [fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
    $code = EvaluationCli::run(['laya-eval', ...$arguments], $env, $stdout, $stderr, $http ?? triageClient());

    return [$code, (string) stream_get_contents($stdout, offset: 0), (string) stream_get_contents($stderr, offset: 0)];
}

/** A client that answers every (batch) request with LAYA_RESPONSE and keeps what was sent. */
function recordingClient(array &$sent): ClientInterface
{
    return new class($sent) implements ClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;

            return new Response(200, [], json_encode(['results' => [LAYA_RESPONSE]]));
        }
    };
}

it('prints the report', function () {
    app()->instance(Laya::class, new Laya('http://laya.test', httpClient: triageClient()));

    expect(Artisan::call('laya:eval', ['class' => 'Decisions\Triage', 'file' => triageCsv(), '--precision' => '0.8']))->toBe(0)
        ->and(Artisan::output())->toBe(TRIAGE_REPORT."\n");
});

it('prints the report as JSON, at a precision of 0.95 by default', function () {
    $expected = new Evaluation(new Laya('http://laya.test', httpClient: triageClient()))->run(Triage::class, triageRows())->toJson();
    app()->instance(Laya::class, new Laya('http://laya.test', httpClient: triageClient()));

    expect(Artisan::call('laya:eval', ['class' => Triage::class, 'file' => triageCsv(), '--json' => true]))->toBe(0)
        ->and(Artisan::output())->toBe($expected."\n");
});

it('fails with a message', function (array $parameters, string $message) {
    Laya::fake(['department' => 'billing', 'urgency' => 0, 'churn' => true]);

    expect(Artisan::call('laya:eval', $parameters + ['class' => 'Decisions\Triage', 'file' => triageCsv()]))->toBe(1)
        ->and(Artisan::output())->toContain($message);
})->with([
    'an unknown class' => [['class' => 'Decisions\Nope'], 'Class "Decisions\Nope" not found, neither as given nor under App\.'],
    'not a decision class' => [['class' => 'Decisions\Broken'], 'App\Decisions\Broken::$name has type string'],
    'an unreadable file' => [['file' => 'missing.csv'], 'Cannot read "missing.csv".'],
    'a precision that is not a number' => [['--precision' => 'high'], '--precision must be a number from 0 to 1.'],
    'a precision above 1' => [['--precision' => '2'], 'precision must be a number from 0 to 1, got 2.'],
]);

it('runs without Laravel as vendor/bin/laya-eval', function () {
    expect(laya_eval(['App\Decisions\Triage', triageCsv(), '--precision=0.8']))->toBe([0, TRIAGE_REPORT."\n", '']);
});

it('prints JSON without Laravel', function () {
    $expected = new Evaluation(new Laya('http://laya.test', httpClient: triageClient()))->run(Triage::class, triageRows())->toJson();

    expect(laya_eval(['--json', 'App\Decisions\Triage', triageCsv()]))->toBe([0, $expected."\n", '']);
});

it('takes the server from --url and --api-key, then LAYA_URL and LAYA_API_KEY', function (array $options, array $env, string $url, string $authorization) {
    $sent = [];

    expect(laya_eval(['App\Decisions\Triage', labelledFile('csv', "state\nx\n"), ...$options], $env, recordingClient($sent))[0])->toBe(0)
        ->and((string) $sent[0]->getUri())->toBe($url)
        ->and($sent[0]->getHeaderLine('Authorization'))->toBe($authorization);
})->with([
    'options' => [['--url=http://cli.test', '--api-key=secret'], ['LAYA_URL' => 'http://env.test', 'LAYA_API_KEY' => 'env'], 'http://cli.test/v1/systemone/batch', 'Bearer secret'],
    'environment' => [[], ['LAYA_URL' => 'http://env.test', 'LAYA_API_KEY' => 'env'], 'http://env.test/v1/systemone/batch', 'Bearer env'],
    'defaults' => [[], [], 'http://localhost:8000/v1/systemone/batch', ''],
    'an empty key' => [['--api-key='], ['LAYA_API_KEY' => 'env'], 'http://localhost:8000/v1/systemone/batch', ''],
]);

it('shows the usage for anything but a class and a file', function (array $arguments) {
    expect(laya_eval($arguments))->toBe([1, '', "Usage: laya-eval <class> <file> [--precision=0.95] [--json] [--url=http://localhost:8000] [--api-key=...]\n"]);
})->with([
    'no file' => [['App\Decisions\Triage']],
    'an unknown option' => [['App\Decisions\Triage', 'triage.csv', '--verbose']],
]);

it('fails with a message without Laravel', function (array $arguments, string $message) {
    expect(laya_eval($arguments))->toBe([1, '', $message."\n"]);
})->with([
    'an unknown class' => [['Decisions\Triage', 'triage.csv'], 'Class "Decisions\Triage" not found.'],
    'an unreadable file' => [['App\Decisions\Triage', 'missing.csv'], 'Cannot read "missing.csv".'],
    'a precision that is not a number' => [['App\Decisions\Triage', 'missing.csv', '--precision=high'], '--precision must be a number from 0 to 1.'],
]);
