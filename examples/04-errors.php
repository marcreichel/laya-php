<?php

declare(strict_types=1);

// Gate on confidence and handle every failure mode.
//
//   php examples/04-errors.php

require __DIR__.'/../vendor/autoload.php';

use MarcReichel\Laya\Exceptions\AuthenticationException;
use MarcReichel\Laya\Exceptions\InvalidQuestionException;
use MarcReichel\Laya\Exceptions\LayaException;
use MarcReichel\Laya\Exceptions\ServerBusyException;
use MarcReichel\Laya\Exceptions\TransportException;
use MarcReichel\Laya\Exceptions\ValidationException;
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

$laya = new Laya(getenv('LAYA_URL') ?: 'http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

// Malformed questions fail before anything is sent.
try {
    Question::score('How urgent is this?', []);
} catch (InvalidQuestionException $e) {
    echo "Invalid question: {$e->getMessage()}\n";
}

try {
    $result = $laya->predict('Something is off with my account.', [
        'department' => Question::choice('Which department should handle this?', ['billing', 'technical', 'other']),
    ]);

    $department = $result->choice('department');
    if ($department->answerConfidence < 0.7) {
        printf("Unsure (%.2f), send to a human. Best guess: %s\n", $department->answerConfidence, $department->choice);
    } else {
        printf("Route to %s\n", $department->choice);
    }
} catch (TransportException $e) {
    echo "laya-serve is not reachable. Start it with `docker compose up -d --wait`.\n";
} catch (AuthenticationException $e) {
    echo "Set LAYA_API_KEY to the key laya-serve was started with.\n";
} catch (ServerBusyException $e) {
    echo "laya-serve is busy, retry shortly.\n";
} catch (ValidationException $e) {
    echo "laya rejected the request: {$e->getMessage()}\n";
} catch (LayaException $e) {
    echo "Something else went wrong: {$e->getMessage()}\n";
}
