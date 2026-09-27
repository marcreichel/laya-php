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

Under the hood this is an SDK for [Laya](https://github.com/NandhaKishorM/laya), a multilingual decision engine that answers typed questions (`choice`, `score`, yes/no) about any text in a single forward pass. Laya runs in Python, so the SDK talks to [`laya-serve`](https://github.com/NandhaKishorM/laya/blob/main/docs/docker.md), Laya's HTTP server, over any PSR-18 client.

**Looking for Jev?** `laya-serve` speaks the same `POST /v1/systemone` protocol as TypeSafe's hosted [Jev](https://github.com/NandhaKishorM/laya#self-hosting-http-server-jev-compatible) API, with the same `choice`/`score`/`noul` answers, so this package is also a self-hosted Jev AI alternative for PHP. It targets `laya-serve` and hasn't been tested against the hosted Jev API.

## Installation

```bash
composer require marcreichel/laya-php
```

PHP 8.4+. You also need a PSR-18 HTTP client (Guzzle, Symfony HttpClient, …). The SDK finds the installed one automatically.

To run `laya-serve` locally, use the `compose.yaml` in this repository (it pins upstream Laya to commit [`4066d5d`](https://github.com/NandhaKishorM/laya/commit/4066d5d5fbf08b66c6757ddeedbd797bd7655bc0)) or follow [Laya's Docker guide](https://github.com/NandhaKishorM/laya/blob/main/docs/docker.md):

```bash
docker compose up -d --wait   # http://localhost:8000
```

Runnable scripts are in [`examples/`](examples). Set `LAYA_URL` (and `LAYA_API_KEY`) if your server isn't on `localhost:8000`. `05-testing-with-fake.php` runs without a server.

### Laravel

For Laravel 13+, the service provider is auto-discovered. It registers `Laya` as a singleton, configured from your `.env`:

```dotenv
LAYA_URL=http://localhost:8000
LAYA_API_KEY=
```

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

### State

`state` can be a string, an array (a JSON document, or a list of conversation turns), or any `JsonSerializable`, such as your own models:

```php
$laya->predict(['subject' => $mail->subject, 'body' => $mail->body], $questions);
```

### Picking a checkpoint

By default, laya's router picks a checkpoint by language. To pin one:

```php
use MarcReichel\Laya\Model;

$laya->predict($text, $questions, model: Model::Multilingual);
```

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

Every parameter needs `#[Ask]`. Any other type throws an `InvalidQuestionException` that names the parameter. When you need confidences, use `predict()`.

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

If code asks a question you didn't register, or gives an answer that isn't one of the question's options, the fake throws.

## Limitations

- **One request per prediction.** `laya-serve` has no batch endpoint and runs one inference at a time, so loop over your inputs.
- **No `max_len`.** `laya-serve` doesn't expose it. Long documents are cut off at the checkpoint's default length (512 or 1,024 tokens).
- **Server limits.** `laya-serve` caps requests at 64 questions, 50,000 characters of state, 100 choice options and 32 score levels.

## Development

```bash
composer test           # Pest
composer test:coverage  # Pest with coverage (Xdebug or pcov), fails below 100%
composer test:mutate    # Pest mutation testing, fails below a 100% score
composer analyse        # PHPStan (max)
composer lint           # Pint

docker compose up -d --wait
LAYA_URL=http://localhost:8000 composer test:integration
```

## License

Apache-2.0
