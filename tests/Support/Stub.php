<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests\Support;

use Pactman\NonprofitCheckPlus\Http\TransportException;

/** A canned HTTP response, or a transport failure the client should see raised. */
final class Stub
{
    private const UNSET = '__stub_unset__';

    /**
     * @param int                   $status
     * @param mixed                 $body     Encoded as JSON. Omit for an empty body.
     * @param string|null           $bodyText Sent verbatim, for a body that is not valid JSON.
     * @param array<string, string> $headers
     * @param TransportException|null $throws Raised instead of responding.
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly mixed $body = self::UNSET,
        public readonly ?string $bodyText = null,
        public readonly array $headers = ['content-type' => 'application/json'],
        public readonly ?TransportException $throws = null,
    ) {
    }

    /** A transport that never reached a server. */
    public static function networkFailure(string $message = 'Could not resolve host'): self
    {
        return new self(throws: new TransportException($message));
    }

    /** A transport whose deadline expired. */
    public static function timeout(string $message = 'Operation timed out'): self
    {
        return new self(throws: new TransportException($message, isTimeout: true));
    }

    public function bodyText(): string
    {
        if ($this->bodyText !== null) {
            return $this->bodyText;
        }

        if ($this->body === self::UNSET) {
            return '';
        }

        return json_encode($this->body, JSON_THROW_ON_ERROR);
    }
}
