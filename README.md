![laya-php: typed text decisions for PHP](https://raw.githubusercontent.com/marcreichel/laya-php/main/.github/header.svg)

<p align="center">
  <a href="https://github.com/marcreichel/laya-php/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/marcreichel/laya-php/ci.yml?branch=main&label=CI&style=for-the-badge" alt="CI"></a>
  <a href="https://packagist.org/packages/marcreichel/laya-php"><img src="https://img.shields.io/packagist/v/marcreichel/laya-php?style=for-the-badge" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/marcreichel/laya-php"><img src="https://img.shields.io/packagist/dependency-v/marcreichel/laya-php/php?style=for-the-badge" alt="PHP Version"></a>
  <a href="#laravel"><img src="https://img.shields.io/badge/Laravel-13%2B-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 13+"></a>
  <a href="phpstan.neon"><img src="https://img.shields.io/badge/PHPStan-level%20max-brightgreen?style=for-the-badge" alt="PHPStan"></a>
  <a href="https://github.com/marcreichel/laya-php/actions/workflows/ci.yml"><img src="https://img.shields.io/badge/coverage-100%25-brightgreen?style=for-the-badge" alt="Coverage 100%"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/marcreichel/laya-php?style=for-the-badge" alt="License"></a>
</p>

**Classify text in PHP without paying for an LLM API.** Route support tickets, spot churn risk and score urgency, in 100+ languages, on your own server, and get the answers back as typed enums, ints and bools.

```php
$triage = $laya->decide('Hi, we were billed twice for March. Refund it today or we cancel.', Triage::class);

$triage->department; // Department::Billing
$triage->churn;      // true
```

- **Self-hosted:** the text never leaves your infrastructure, and you pay no per-token fees.
- **Typed:** describe a decision as a readonly class with enums and get an instance back.
- **Calibrated:** every answer has a confidence you can threshold on to send unsure cases to a human.
- **Testable:** a built-in fake lets you unit-test without a running server.

![Classifying English, German and Spanish support tickets into a typed Triage object](https://raw.githubusercontent.com/marcreichel/laya-php/main/.github/demo.gif)

Under the hood this is an SDK for [Laya](https://github.com/NandhaKishorM/laya), a multilingual decision engine that answers typed questions (`choice`, `score`, yes/no) about any text in a single forward pass. Laya runs in Python, so the SDK talks to [`laya-serve`](https://github.com/NandhaKishorM/laya/blob/main/docs/docker.md), Laya's HTTP server, over any PSR-18 client.

**Looking for Jev?** `laya-serve` speaks the same `POST /v1/systemone` protocol as TypeSafe's hosted [Jev](https://github.com/NandhaKishorM/laya#self-hosting-http-server-jev-compatible) API, with the same `choice`/`score`/`noul` answers, so this package is also a self-hosted Jev AI alternative for PHP. It targets `laya-serve`, and CI checks its requests and responses against TypeSafe's [OpenAPI spec](https://api.typesafe.ai/openapi.json) on every change and weekly, so other `/v1/systemone` servers such as [sys1](https://github.com/alvarobartt/sys1) work too. It hasn't been tested against the hosted Jev API itself.

## Installation

```bash
composer require marcreichel/laya-php
```

PHP 8.4+. You also need a PSR-18 HTTP client (Guzzle, Symfony HttpClient, …). The SDK finds the installed one automatically.

To run `laya-serve` locally, use the `compose.yaml` in this repository (it pins upstream Laya to v0.3.29, commit [`e08843b`](https://github.com/NandhaKishorM/laya/commit/e08843b6255e7ef97aa408a67ca5e6b26920415e)) or follow [Laya's Docker guide](https://github.com/NandhaKishorM/laya/blob/main/docs/docker.md):

```bash
docker compose up -d --wait   # http://localhost:8000
```

Runnable scripts are in [`examples/`](examples). Set `LAYA_URL` (and `LAYA_API_KEY`) if your server isn't on `localhost:8000`. `05-testing-with-fake.php` runs without a server.

### Laravel

For Laravel 13+, the service provider is auto-discovered. It registers `Laya` as a singleton, configured from your `.env`:

```dotenv
LAYA_URL=http://localhost:8000
LAYA_API_KEY=
LAYA_CACHE_STORE=   # e.g. redis, to cache predictions (see Caching)
LAYA_CACHE_TTL=     # seconds
LAYA_EVENTS=true                  # dispatch PredictionMade/PredictionFailed (see Events)
LAYA_EVENTS_INCLUDE_STATE=false
```

`php artisan laya:health` prints the server's status and loaded checkpoints (plus the idle-unload window and idle time when laya-serve runs with `LAYA_IDLE_UNLOAD_SECONDS`, since an idle unload leaves "Loaded: none" on a healthy server), and exits with 1 when it is unreachable or unhealthy, so you can use it in deploy checks.

`php artisan laya:try` runs a [decision class](#decisions-into-objects) on a piece of text and shows each parameter's question, hydrated value (`null` below `minConfidence`), answer confidence and the probability of every option, plus the routed checkpoint and a warning when the text was cut off. Use it to iterate on `#[Ask]` and `#[Describe]` wording:

```bash
php artisan laya:try "App\Decisions\Triage" "Hi, we were billed twice for March. Refund it today or we cancel."
php artisan laya:try "Decisions\Triage" --file=ticket.txt   # short names resolve under App\
echo "..." | php artisan laya:try "Decisions\Triage" --model=multilingual --json
```

It takes `--model=`, `--max-len=`, `--head-max-len=` and `--json`, and exits with 1 for an unknown or invalid class or an unreachable server.

Inject it wherever you need it:

```php
use MarcReichel\Laya\Laya;

final class ClassifyTicket implements ShouldQueue
{
    use Queueable;

    public function __construct(public Ticket $ticket) {}

    public function handle(Laya $laya): void
    {
        $triage = $laya->decide($this->ticket->body, Triage::class);

        $this->ticket->update(['department' => $triage->department]);
    }
}
```

To change the config file, publish it with `php artisan vendor:publish --tag=laya-config`. In tests, `Laya::fake([...])` also replaces the container's instance, so injected code gets the fake (see [Testing your code](#testing-your-code)):

```php
$laya = Laya::fake(['department' => Department::Billing, 'urgency' => 2, 'churn' => true]);

ClassifyTicket::dispatchSync($ticket);

$laya->assertPredictedCount(1);
```

## Asking questions

```php
use MarcReichel\Laya\Laya;
use MarcReichel\Laya\Question;

$laya = new Laya('http://localhost:8000', apiKey: getenv('LAYA_API_KEY') ?: null);

$result = $laya->predict($ticketText, [
    'department' => Question::choice('Which department should handle this?', [
        'billing'   => 'invoices, payments, refunds',
        'technical' => 'bugs, outages, system errors',
        'other'     => 'everything else',
    ]),
    'urgency'    => Question::score('How urgent is this?', ['not urgent', 'soon', 'blocking']),
    'churn_risk' => Question::yesNo('Does the user threaten to cancel or leave?'),
]);

$result->choice('department')->choice;        // 'billing'
$result->choice('department')->probabilities; // ['billing' => 0.91, 'technical' => 0.06, 'other' => 0.03]
$result->score('urgency')->level();           // 2: the most likely level
$result->score('urgency')->label();           // 'blocking'
$result->score('urgency')->score;             // 1.74: the expected level
$result->yesNo('churn_risk')->yes();          // true
$result->yesNo('churn_risk')->probability;    // 0.83

$result['department'];  // ArrayAccess works too (returns the base Answer type)
$result->routedModel;   // 'english': the checkpoint laya picked
```

The three question types:

| Factory | Options | Answer |
|---|---|---|
| `Question::choice($instructions, $options)` | a list of labels, or `label => description` | `ChoiceAnswer`: `choice`, `probabilities`, `is($label)` |
| `Question::score($instructions, $levels)` | level descriptions, lowest first | `ScoreAnswer`: `score`, `level()`, `label()`, `probabilities`, `legend` |
| `Question::yesNo($instructions, yes: …, no: …)` | optional descriptions of yes and no | `YesNoAnswer`: `probability`, `yes($threshold = 0.5)`, `no()` |

Every answer also has `confidence` and `answerConfidence`. `answerConfidence` is the calibrated one and is comparable across question types, so use it to decide when to trust an answer:

```php
if ($result->choice('department')->answerConfidence < 0.7) {
    $ticket->sendToHumanTriage();
}
```

Servers that only follow TypeSafe's [OpenAPI spec](https://api.typesafe.ai/openapi.json), such as [sys1](https://github.com/alvarobartt/sys1), don't send an `answer_confidence`. Then `answerConfidence` falls back to `confidence`, and a yes/no answer without a `confidence` gets laya's own `max(P(yes), P(no))`. Thresholds and `minConfidence` keep working, though `confidence` is stricter than the calibrated value for choice and score answers. The same applies to laya-serve 0.3.24 or later started with `LAYA_JEV_STRICT=1`, which strips `answer_confidence`, `routing` and the truncation report from its responses, so `routedModel` is `null` and `truncated` is always `false` there.

### State

`state` can be a string, an array (a JSON document, or a list of conversation turns), or any `JsonSerializable`, such as your own models:

```php
$laya->predict(['subject' => $mail->subject, 'body' => $mail->body], $questions);
```

### Picking a checkpoint

By default, laya's router picks a checkpoint by language. The request then carries `"model": "jev-latest"`, since TypeSafe's spec requires a model name: laya-serve routes for names it doesn't know, and sys1 reads it as the model it serves. To pin one:

```php
use MarcReichel\Laya\Model;

$laya->predict($text, $questions, model: Model::Multilingual);
```

### Long documents

Laya cuts the state off at the checkpoint's default length (512 or 1,024 tokens). With laya-serve 0.3.21 or later you can raise it per request with `maxLen`, and give questions with many or long options more room with `headMaxLen`:

```php
$laya->predict($contract, $questions, maxLen: 4096);
$laya->decide($ticket, Triage::class, headMaxLen: 384);
```

laya-serve caps both at `LAYA_MAX_TOKEN_BUDGET` (8,192 by default) and answers anything above it with a `ValidationException`.

From laya-serve 0.3.22, `$result->truncated` tells you whether the state was cut off, so you know when to raise `maxLen`:

```php
$result = $laya->predict($contract, $questions);

if ($result->truncated) {
    $result = $laya->predict($contract, $questions, maxLen: 4096);
}
```

### Batches (experimental)

`predictMany()` and `decideMany()` ask the same questions about many states, which laya-serve 0.3.22 and later answers in shared forward passes. Results keep the keys you pass in:

```php
$results = $laya->predictMany($tickets->pluck('body', 'id')->all(), $questions);
$results[42]->choice('department');

$triages = $laya->decideMany($tickets->pluck('body', 'id')->all(), Triage::class, maxLen: 4096);
```

Cached states aren't sent again, and the rest go out in requests of at most 64 states. One state that laya-serve rejects fails the whole request, the same way `predict()` throws. `maxLen` and `headMaxLen` apply to every state in the batch and need laya-serve 0.3.23; older servers ignore them. A laya-serve older than 0.3.22 answers with a `ServerException` that names the version it needs.

Both methods are experimental and may change in a minor release.

## Decisions into objects

Describe the decision as a class, and `decide()` asks its questions and gives you an instance back:

```php
use MarcReichel\Laya\Attributes\Ask;
use MarcReichel\Laya\Attributes\Describe;
use MarcReichel\Laya\Attributes\Levels;

enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';

    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';

    case Other = 'other';
}

final readonly class Triage
{
    public function __construct(
        #[Ask('Which department should handle this?')]
        public Department $department,

        #[Ask('How urgent is this?'), Levels('not urgent', 'soon', 'blocking')]
        public int $urgency,

        #[Ask('Does the user threaten to cancel or leave?')]
        public bool $churn,
    ) {}
}

$triage = $laya->decide($ticketText, Triage::class); // Triage
```

| Constructor parameter | Question | Value |
|---|---|---|
| backed enum | choice (case values are the options; `#[Describe]` adds descriptions) | the most likely case |
| `bool` | yes/no | `true` when P(yes) ≥ 0.5 |
| `int` with `#[Levels(...)]` | score | the most likely level index |

Every parameter needs `#[Ask]`. Any other type throws an `InvalidQuestionException` that names the parameter.

`#[Ask]` takes a few options on top of the question:

```php
final readonly class Triage
{
    public function __construct(
        // null when the calibrated confidence is below 0.7, so you can hand the ticket to a human
        #[Ask('Which department should handle this?', minConfidence: 0.7)]
        public ?Department $department,

        // bools can describe yes and no, and pick the P(yes) from which they are true
        #[Ask('Does the user threaten to cancel or leave?', yes: 'says they will cancel or switch', threshold: 0.3)]
        public bool $churn,
    ) {}
}

$triage = $laya->decide($ticketText, Triage::class);

if ($triage->department === null) {
    $ticket->sendToHumanTriage();
}
```

| Option | Applies to | Effect |
|---|---|---|
| `minConfidence` | any nullable parameter | `null` when `answerConfidence` is below it |
| `yes`, `no` | `bool` | describe what yes and no mean |
| `threshold` | `bool` | `true` when P(yes) ≥ threshold (default 0.5) |

For the full probabilities, use `predict()`.

## Recipes

### Routing incoming email

Pass the email as a document and give unsure answers to a human. German, Spanish or Hindi emails work the same way, with no extra setup.

```php
$result = $laya->predict([
    'from'    => $mail->from,
    'subject' => $mail->subject,
    'body'    => $mail->body,
], [
    'team' => Question::choice('Which team should answer this email?', [
        'sales'   => 'pricing, quotes, new contracts',
        'support' => 'problems using the product',
        'billing' => 'invoices, payments, refunds',
        'spam'    => 'newsletters, cold outreach, phishing',
    ]),
]);

$team = $result->choice('team');

$inbox->assign($mail, $team->answerConfidence >= 0.7 ? $team->choice : 'triage');
```

### Moderating reviews

Hold abusive or spam reviews back before they are published, and record the sentiment while you're at it.

```php
enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}

final readonly class Moderation
{
    public function __construct(
        #[Ask('Does the review contain insults, hate speech or threats?')]
        public bool $abusive,

        #[Ask('Is this spam or an advertisement rather than a real review?')]
        public bool $spam,

        #[Ask('What is the overall sentiment of the review?')]
        public Sentiment $sentiment,
    ) {}
}

$moderation = $laya->decide($review->body, Moderation::class);

if ($moderation->abusive || $moderation->spam) {
    $review->holdForModeration();
}
```

### Scoring leads

A score question's `score` is the expected level, a float, so leads with the same most likely level still sort cleanly.

```php
$result = $laya->predict($lead->message, [
    'intent' => Question::score('How ready is this person to buy?', [
        'just browsing',
        'researching options',
        'comparing vendors',
        'ready to buy',
    ]),
    'budget' => Question::yesNo('Does the message mention a budget, a timeline or a team size?'),
]);

$lead->score = $result->score('intent')->score; // 0.0 to 3.0
$lead->hot   = $result->score('intent')->level() === 3 && $result->yesNo('budget')->yes();
```

### Finding relevant contract fields

Before you look anything up, find out which of a contract's fields a question needs. A yes/no question per field catches questions that touch several fields. One choice question over all fields is sharper when a single field is meant. Both go in the same request:

```php
$fields = [
    'notice_period'  => 'how far in advance either side must give notice to end the contract',
    'auto_renewal'   => 'whether and for how long the contract renews automatically',
    'governing_law'  => 'which country\'s law applies',
    'jurisdiction'   => 'which court handles disputes',
    // ...
];

$questions = array_map(
    fn (string $description) => Question::yesNo("Do you need to know the contract's clause on {$description} to answer this question?"),
    $fields,
) + ['main_clause' => Question::choice('Which contract clause do you need to answer this question?', $fields)];

$result = $laya->predict('Who do we sue in if things go wrong, and under which law?', $questions);

$relevant = array_filter(array_keys($fields), fn (string $field) => $result->yesNo($field)->yes(threshold: 0.2));

if ($result->choice('main_clause')->answerConfidence >= 0.7) {
    $relevant[] = $result->choice('main_clause')->choice;
}

$relevant = array_unique($relevant); // ['governing_law', 'jurisdiction', ...]
```

Yes/no probabilities for this kind of question run low, so the threshold is 0.2 instead of 0.5. The full version with 20 fields is in [`examples/06-contract-fields.php`](examples/06-contract-fields.php).

## Caching

Laya gives the same answer to the same input, so you can skip repeat requests with any PSR-16 cache. The key covers the state, the questions, the pinned model and the token budgets:

```php
$laya = new Laya('http://laya:8000', cache: $psr16Cache, cacheTtl: 86400);
```

The key doesn't include the checkpoint revision, so clear the cache (or set a TTL) when you upgrade `laya-serve`'s checkpoints.

## Events

To monitor predictions (timings, cache hits, routing, failures), pass any PSR-14 event dispatcher:

```php
$laya = new Laya('http://laya:8000', events: $psr14Dispatcher);
```

| Event | When | Payload |
|---|---|---|
| `PredictionMade` | after each `predict()`, and after each state of a `predictMany()` (so also `decide()`/`decideMany()`) | `questionIds`, `model` (pinned, or `null`), `routedModel`, `truncated`, `cached`, `durationMs`, `inputTokens`, `result` |
| `PredictionFailed` | when a request to laya-serve fails, right before the exception is thrown; once per failed batch request | `questionIds`, `model`, `exception`, `durationMs` |

Both are readonly classes in `MarcReichel\Laya\Events`. Cache hits have a `durationMs` of `0`, and the states of a batch share the duration of the request they were sent in. A question or decision class that is malformed throws before any request, without an event. If a `PredictionFailed` listener throws, its exception is dropped, so you still get the laya error.

The events leave the state out, since it may be sensitive. Pass `includeState: true` to get it as `$event->state` (for a failed batch, the states of that request, keyed as you passed them). Without a dispatcher, no events are built.

In Laravel, the service provider passes the app's event dispatcher, so listeners and `Event::fake()` work as usual:

```php
use MarcReichel\Laya\Events\PredictionMade;

Event::listen(function (PredictionMade $event) {
    Log::info('laya', ['model' => $event->routedModel, 'cached' => $event->cached, 'ms' => $event->durationMs]);
});
```

Set `LAYA_EVENTS=false` to turn them off, or `LAYA_EVENTS_INCLUDE_STATE=true` to include the state.

`Laya::fake()` dispatches `PredictionMade` too. In Laravel it uses the app's dispatcher and settings, so you can assert on the events in your tests:

```php
Event::fake();
Laya::fake(['churn' => true]);

ClassifyTicket::dispatchSync($ticket);

Event::assertDispatched(PredictionMade::class);
```

Outside Laravel, pass a dispatcher: `Laya::fake([...], events: $psr14Dispatcher, includeState: true)`.

## Errors

Everything the SDK throws implements `MarcReichel\Laya\Exceptions\LayaException`.

| Exception | When |
|---|---|
| `InvalidQuestionException` | a question or decision class is malformed; thrown before any request is sent |
| `ValidationException` | laya rejected the request (400/413/422); the message names the problem |
| `AuthenticationException` | wrong or missing API key (401) |
| `ServerBusyException` | laya-serve is at its concurrency limit (503); safe to retry |
| `ServerException` | any other error status, or a response that isn't laya-shaped |
| `TransportException` | laya-serve couldn't be reached |

The SDK doesn't retry. For retries, pass an HTTP client that has them, such as Symfony's `RetryableHttpClient` or Guzzle with retry middleware:

```php
use Symfony\Component\HttpClient\{HttpClient, Psr18Client, RetryableHttpClient};

$laya = new Laya('http://laya:8000', httpClient: new Psr18Client(new RetryableHttpClient(HttpClient::create())));
```

## Testing your code

`Laya::fake()` returns a client that answers from values you register, with no server involved:

```php
$laya = Laya::fake([
    'department' => Department::Billing, // or 'billing'
    'urgency'    => 2,                   // level index
    'churn'      => true,                // or a probability, e.g. 0.3
]);

// ... run the code under test with $laya ...

$laya->assertPredictedCount(1);
$laya->assertPredicted(fn ($state, array $questions, ?string $model) => str_contains($state, 'refund'));
$laya->assertNothingPredicted();
```

For decision classes, fake with an instance and assert on the class. A `null` answer (here or in the array form) is an unsure one: even probabilities and zero confidence, so `minConfidence` turns it into `null` again:

```php
$laya = Laya::fake(new Triage(department: null, churn: true));

// ... run the code under test with $laya ...

$laya->assertDecided(Triage::class);
$laya->assertDecided(Triage::class, fn ($state, ?string $model) => str_contains($state, 'refund'));
```

If code asks a question you didn't register, or gives an answer that isn't one of the question's options, the fake throws.

## Limitations

- **One inference at a time.** `laya-serve` handles one request at a time, so parallel requests just wait in line. For many states, use [`predictMany()`](#batches-experimental), which shares forward passes.
- **Server limits.** `laya-serve` caps requests at 64 states per batch, 64 questions, 50,000 characters of state, 100 choice options, 32 score levels and 512 answer options in total.

## Development

```bash
composer test           # Pest
composer test:coverage  # Pest with coverage (Xdebug or pcov), fails below 100%
composer test:mutate    # Pest mutation testing, fails below a 100% score
composer analyse        # PHPStan (max)
composer lint           # Pint

docker compose up -d --wait
LAYA_URL=http://localhost:8000 composer test:integration

curl -fsSL https://api.typesafe.ai/openapi.json -o openapi.json
LAYA_OPENAPI=openapi.json composer test:contract   # requests and responses against TypeSafe's spec
```

## License

Apache-2.0
