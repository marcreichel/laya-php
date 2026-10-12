<?php

declare(strict_types=1);

namespace MarcReichel\Laya;

use MarcReichel\Laya\Exceptions\InvalidOptionException;
use MarcReichel\Laya\Exceptions\LayaException;
use Psr\Http\Client\ClientInterface;

/**
 * `vendor/bin/laya-eval`: laya:eval without Laravel. The server comes from --url/--api-key, or LAYA_URL/LAYA_API_KEY.
 *
 *     vendor/bin/laya-eval "App\Decisions\Triage" triage.csv --precision=0.9 --json
 */
final class EvaluationCli
{
    /**
     * @param  list<string>  $argv  as PHP passes it, the script name first
     * @param  array<string, string>  $env  as getenv() returns it
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @param  ClientInterface|null  $http  for tests; discovered when omitted
     */
    public static function run(array $argv, array $env, $stdout, $stderr, ?ClientInterface $http = null): int
    {
        $arguments = [];
        $json = false;
        $options = ['precision' => '0.95', 'url' => $env['LAYA_URL'] ?? 'http://localhost:8000', 'api-key' => $env['LAYA_API_KEY'] ?? ''];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
            } elseif (preg_match('/^--(precision|url|api-key)=(.*)$/', $arg, $match) === 1) {
                $options[$match[1]] = $match[2];
            } else {
                // An unknown option counts as an argument, so it fails the count below.
                $arguments[] = $arg;
            }
        }
        if (count($arguments) !== 2) {
            fwrite($stderr, "Usage: laya-eval <class> <file> [--precision=0.95] [--json] [--url=http://localhost:8000] [--api-key=...]\n");

            return 1;
        }

        [$class, $file] = $arguments;
        try {
            if (! class_exists($class)) {
                throw new \UnexpectedValueException(sprintf('Class "%s" not found.', $class));
            }
            $laya = new Laya($options['url'], apiKey: $options['api-key'] !== '' ? $options['api-key'] : null, httpClient: $http);
            fwrite($stdout, self::evaluate($laya, $class, $file, $options['precision'], $json)."\n");
        } catch (LayaException|\UnexpectedValueException $e) {
            fwrite($stderr, $e->getMessage()."\n");

            return 1;
        }

        return 0;
    }

    /**
     * The report on $class against the rows in $file, as text or JSON. Shared with laya:eval.
     *
     * @internal
     *
     * @param  class-string  $class
     */
    public static function evaluate(Laya $laya, string $class, string $file, mixed $precision, bool $json): string
    {
        if (! is_numeric($precision)) {
            throw new InvalidOptionException('--precision must be a number from 0 to 1.');
        }
        $report = new Evaluation($laya)->run($class, LabelledRows::read($file), (float) $precision);

        return $json ? $report->toJson() : $report->render();
    }
}
