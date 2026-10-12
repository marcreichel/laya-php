<?php

declare(strict_types=1);

namespace MarcReichel\Laya\Laravel;

use Illuminate\Console\Command;
use MarcReichel\Laya\EvaluationCli;
use MarcReichel\Laya\Exceptions\LayaException;
use MarcReichel\Laya\Laya;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php artisan laya:eval`: measures a decision class against labelled examples, to pick minConfidence and threshold.
 */
final class EvalCommand extends Command
{
    protected $signature = 'laya:eval
        {class : The decision class, e.g. "App\Decisions\Triage" or "Decisions\Triage"}
        {file : A CSV (.csv) or JSON Lines (.jsonl) file with a state column and one column per labelled parameter}
        {--precision=0.95 : The precision the suggested minConfidence has to reach}
        {--json : Print the report as JSON}';

    protected $description = 'Measure a decision class against labelled examples';

    public function handle(Laya $laya): int
    {
        /** @var string $name required, so always a string */
        $name = $this->argument('class');
        /** @var string $file required, so always a string */
        $file = $this->argument('file');

        try {
            $report = EvaluationCli::evaluate($laya, TryCommand::decisionClass($name), $file, $this->option('precision'), $this->option('json') === true);
        } catch (LayaException|\UnexpectedValueException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln($report, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
