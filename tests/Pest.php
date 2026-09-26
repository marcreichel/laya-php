<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use MarcReichel\Laya\Laya;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Laya that answers every request with $status/$body and records what it sent.
 *
 * @param  array<string, mixed>|string  $body
 * @param  list<RequestInterface>  $sent
 */
function layaRespondingWith(int $status, array|string $body, array &$sent = [], ?string $apiKey = null): Laya
{
    $client = new class($status, is_string($body) ? $body : json_encode($body), $sent) implements ClientInterface
    {
        /** @param list<RequestInterface> $sent */
        public function __construct(private int $status, private string $body, private array &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;

            return new Response($this->status, ['Content-Type' => 'application/json'], $this->body);
        }
    };

    return new Laya('http://laya.local/', apiKey: $apiKey, httpClient: $client);
}
