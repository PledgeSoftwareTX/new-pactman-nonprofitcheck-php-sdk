# pactmandev/nonprofit-check-plus

Official PHP SDK for the **Pactman Nonprofit Check Plus API**. Look up US nonprofits by EIN and read the IRS and OFAC findings behind the result.

- Documented models for every response field, with the raw payload always available
- Local EIN normalization and validation, so malformed input never costs a request
- A structured exception taxonomy you branch on by type, never by parsing message strings
- Finite default timeout, bounded retries with jittered backoff, and `Retry-After` support
- No required dependencies beyond ext-curl — or send through your own PSR-18 client

> **Server-side only.** Your API key is a private credential. Do not construct this client in anything that ships to an end user.

---

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuring your API key](#configuring-your-api-key)
- [Quick start](#quick-start)
- [Environment and base URL](#environment-and-base-url)
- [Single check](#single-check)
- [Bulk check](#bulk-check)
- [Usage and billing cycle](#usage-and-billing-cycle)
- [Inspecting source-specific findings](#inspecting-source-specific-findings)
- [Response models and raw data](#response-models-and-raw-data)
- [EIN validation and normalization](#ein-validation-and-normalization)
- [Error handling](#error-handling)
- [Timeouts](#timeouts)
- [Retries](#retries)
- [Rate limits](#rate-limits)
- [Bringing your own HTTP client](#bringing-your-own-http-client)
- [PHP-specific notes](#php-specific-notes)
- [Security](#security)
- [What this SDK does not tell you](#what-this-sdk-does-not-tell-you)
- [API reference](#api-reference)
- [Examples](#examples)
- [Development](#development)
- [Support](#support)
- [License](#license)

---

## Requirements

- PHP **8.2 or newer**
- `ext-curl` and `ext-json`
- A Pactman API key with Nonprofit Check access

There are no required Composer dependencies. The bundled cURL transport is the default; to send through a stack you already own, see [Bringing your own HTTP client](#bringing-your-own-http-client).

## Installation

```bash
composer require pactmandev/nonprofit-check-plus
```

## Configuring your API key

Load the key from the environment or a secret manager. Never commit it, never inline it in source, and never expose it to an end user.

```bash
# .env — excluded from version control
PACTMAN_API_KEY=your_api_key_here
```

```php
use Pactman\NonprofitCheckPlus\PactmanClient;

$client = new PactmanClient(apiKey: getenv('PACTMAN_API_KEY'));
```

The key is validated locally. A missing, empty, or whitespace-only key throws `ConfigurationException` at construction, before any network call:

```php
new PactmanClient(apiKey: '');
// ConfigurationException: The Pactman API key is empty. Check that the
// environment variable holding it is set.
```

Every request carries the key as `Authorization: Bearer <key>`. It never appears in logs, exception messages, `$client->toArray()`, `json_encode($client)`, `print_r($client)`, or `var_dump($client)` — the key is not a property of any object this SDK builds, only a closure the transport calls at send time.

## Quick start

```php
use Pactman\NonprofitCheckPlus\PactmanClient;

$client = new PactmanClient(apiKey: getenv('PACTMAN_API_KEY'));
$result = $client->nonprofits->check('41-1787097');

echo $result->nonprofit?->organization_name;  // "EXAMPLE NONPROFIT"
echo $result->nonprofit?->pub78_verified;     // true
echo $result->checkCount;                     // checks used so far this billing cycle
```

`$result->nonprofit` is `null` when the API returned no record, so check it before reading fields.

Build **one client per process** and share it. Each instance carries its own connection and throttle state, so constructing one per request throws both away.

## Environment and base URL

Production is the default and the only named environment. Pactman's QA and sandbox hosts are internal and are not selectable from this package.

```php
use Pactman\NonprofitCheckPlus\Environment;
use Pactman\NonprofitCheckPlus\PactmanClient;

// These are equivalent.
new PactmanClient(apiKey: $apiKey);
new PactmanClient(apiKey: $apiKey, environment: Environment::Production);
new PactmanClient(apiKey: $apiKey, environment: 'production');
```

For a local mock server, a proxy, or a host Pactman has given you directly, set `baseUrl`. It overrides `environment`, and is validated locally — a malformed URL throws `ConfigurationException` before a request is attempted.

```php
$client = new PactmanClient(apiKey: $apiKey, baseUrl: 'http://127.0.0.1:8787');

$client->baseUrl();      // "http://127.0.0.1:8787"
$client->environment();  // null — an explicit host, not a named environment
```

Only the target host changes. Request and response semantics are identical.

## Single check

```php
$result = $client->nonprofits->check('41-1787097');

$result->nonprofit;    // Nonprofit|null
$result->checkCount;   // nonprofit_check_count — see "Usage and billing cycle" below
$result->timeTakenMs;  // server-side processing time
$result->status;       // HTTP status
$result->requestId;    // correlation ID, when the server sends one
$result->errors;       // list<ApiErrorDetail>
$result->raw;          // the unmodified response envelope
```

`'41-1787097'` and `'411787097'` are the same request — the EIN is normalized before the URL is built.

Per-request overrides use named arguments:

```php
$client->nonprofits->check('411787097', timeout: 5.0, retry: false, headers: ['X-Trace-Id' => $traceId]);
```

## Bulk check

```php
$result = $client->nonprofits->checkBulk(['41-1787097', '996589560', '999999999']);

foreach ($result->organizations as $organization) {
    echo $organization->ein, ' ', $organization->organization_name, "\n";
}

// EINs with no record are not an error — they come back on a 200 response.
$result->notFoundEins;  // ["999999999"]
$result->checkCount;
```

Behaviour worth knowing:

| | |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| **Batch limit**    | 50 EINs per request, enforced locally before sending. Exported as `Endpoints::MAX_BULK_EINS`.                              |
| **Chunking**       | None. Larger inputs throw rather than silently splitting into several billable requests.                                    |
| **Request order**  | Your EINs are sent exactly as supplied. The SDK never reorders them.                                                       |
| **Response order** | Not guaranteed to match. The API matches by set membership — index by `ein` with `byEin()`, never pair positionally.        |
| **Duplicates**     | Sent as supplied, because each one is billable. A repeated EIN still returns one record. Pass `dedupe: true` to collapse.   |
| **Empty input**    | Throws `ValidationException` locally.                                                                                      |
| **One bad EIN**    | The whole batch is rejected locally, identifying the failing index. Nothing is sent.                                       |
| **No matches**     | A batch where nothing matched is an error; a batch where some matched is a 200 with the rest in `notFoundEins`.             |

```php
// Opt in to deduplication.
$client->nonprofits->checkBulk($eins, dedupe: true);

// Index by EIN — the pairing that always holds.
$byEin = $result->byEin();
$organization = $byEin['411787097'] ?? null;
```

## Usage and billing cycle

`nonprofit_check_count`, surfaced as `$result->checkCount`, is the number of checks your account has consumed **so far in the current billing cycle**, including the request that returned it. It resets when a new cycle starts.

It is not the size of the request you just made. A bulk call for five EINs does not return `5`.

```php
$before = $client->nonprofits->check($ein);
$after = $client->nonprofits->checkBulk($eins);

$after->checkCount;                        // cycle total, e.g. 1284
$after->checkCount - $before->checkCount;  // what these requests actually consumed
```

EINs with no matching record are not billed, so a delta can be smaller than the batch you sent. Read the number the API reports rather than reconstructing usage from your input.

## Inspecting source-specific findings

The API returns source fields flat on the organization (`pub78_*`, `bmf_*`, `ofac_*`, and the revocation fields). Read them directly, or use the grouped accessors — which copy fields 1:1 and derive nothing.

```php
use Pactman\NonprofitCheckPlus\Sources;

$nonprofit = $client->nonprofits->check('41-1787097')->nonprofit;

if ($nonprofit !== null) {
    // IRS Publication 78
    $pub78 = Sources::pub78($nonprofit);

    if ($pub78 === null) {
        echo "Publication 78 data was not returned for this organization.\n";
    } else {
        $pub78->verified;     // true | false | null
        $pub78->most_recent;  // date of the Pub 78 record
    }

    // IRS Business Master File
    $bmf = Sources::bmf($nonprofit);
    $bmf?->status;
    $bmf?->subsection_description;

    // IRS Automatic Revocation of Exemption
    $aroe = Sources::aroe($nonprofit);
    $aroe?->revocation_date;
    $aroe?->reinstatement_date;

    // OFAC Specially Designated Nationals
    $ofac = Sources::ofac($nonprofit);
    $ofac?->status;  // a sentence describing the finding
}
```

Each accessor returns `null` only when the API returned **no data at all** for that source. That keeps *"the source was not returned"* distinct from an explicit negative such as `pub78_verified: false`.

**On OFAC:** the API returns `ofac_status` as prose, not a boolean. This SDK deliberately does not expose a `hasOfacMatch()` flag, because deriving one would mean pattern-matching English that could be reworded at any time. Read the status, or route it to a reviewer.

## Response models and raw data

Field names mirror the wire format exactly, so the API reference and your code use the same names — there is no rename table to keep in sync.

`Nonprofit` and the source views are immutable views over the decoded response. Read a field however suits the call site:

```php
$nonprofit->organization_name;         // property syntax, autocompleted from @property-read
$nonprofit['organization_name'];       // array syntax
$nonprofit->get('organization_name');  // explicit, with an optional default
```

Unknown fields never break deserialization. Anything the API adds in a future version is readable through the same object, and through `raw`:

```php
// Readable without an SDK upgrade.
$nonprofit->get('some_future_field');

$result->raw;  // the complete, unmodified envelope
```

**`has()` is the question you usually want.** The API omits a field it has no data for, and returns `null` for a field it looked at and found empty. Those are different facts:

```php
$nonprofit->has('ofac_status');  // did the API return this field at all?
$nonprofit->get('ofac_status');  // its value, which may legitimately be null
```

`isset($nonprofit['ofac_status'])` answers the same question as `has()` — it is true for a field returned as `null`, which is not PHP's usual array behaviour. That is deliberate: for this API, collapsing "absent" into "null" loses a finding.

`null` and `false` are preserved as distinct values wherever the API distinguishes them.

## EIN validation and normalization

```php
use Pactman\NonprofitCheckPlus\Ein;

Ein::normalize('41-1787097');  // "411787097"
Ein::normalize('411787097');   // "411787097"
Ein::isValid('4117870');       // false
```

Accepted: nine digits, with or without the conventional hyphen after the two-digit prefix, ignoring surrounding whitespace. Rejected: letters, other punctuation, wrong digit counts, empty and `null` values. No IRS prefix rules are applied.

Bulk validation reports every failure at once, by index:

```php
use Pactman\NonprofitCheckPlus\Exception\ValidationException;

try {
    $client->nonprofits->checkBulk(['411787097', 'nope', '1234']);
} catch (ValidationException $error) {
    foreach ($error->issues as $issue) {
        echo $issue->index, ' ', var_export($issue->value, true), ' ', $issue->message, "\n";
    }
}
```

> Formatting validation confirms only that a value is shaped like an EIN. It says nothing about tax-exempt status, identity, eligibility, or good standing.

## Error handling

Every failure is a `PactmanException` with a stable `category` and an `origin` of `local` or `api`. Branch on the class or the category — never on message text.

```php
use Pactman\NonprofitCheckPlus\Exception\ApiException;
use Pactman\NonprofitCheckPlus\Exception\AuthenticationException;
use Pactman\NonprofitCheckPlus\Exception\RateLimitException;
use Pactman\NonprofitCheckPlus\Exception\TimeoutException;
use Pactman\NonprofitCheckPlus\Exception\ValidationException;

try {
    $client->nonprofits->check($ein);
} catch (ValidationException $error) {
    // Bad input. Nothing was sent.
} catch (AuthenticationException $error) {
    // The key was rejected.
} catch (RateLimitException $error) {
    echo $error->retryAfterSeconds;
} catch (TimeoutException $error) {
    echo $error->timeout;
} catch (ApiException $error) {
    echo $error->status, $error->requestId, count($error->apiErrors);
}
```

Every class below lives in `Pactman\NonprofitCheckPlus\Exception` and extends `PactmanException`, so one `catch (PactmanException $e)` catches everything this SDK throws.

| Class                      | Category         | Origin | Thrown for                      |
| -------------------------- | ---------------- | ------ | ------------------------------- |
| `ConfigurationException`   | `configuration`  | local  | Unusable client options         |
| `ValidationException`      | `validation`     | local  | Input rejected before sending   |
| `BadRequestException`      | `bad_request`    | api    | HTTP 400                        |
| `AuthenticationException`  | `authentication` | api    | HTTP 401                        |
| `AuthorizationException`   | `authorization`  | api    | HTTP 403                        |
| `NotFoundException`        | `not_found`      | api    | HTTP 404                        |
| `RateLimitException`       | `rate_limit`     | api    | HTTP 429                        |
| `ServerException`          | `server`         | api    | HTTP 5xx                        |
| `ApiException`             | `api`            | api    | Any other unexpected response   |
| `TimeoutException`         | `timeout`        | local  | Exceeded the configured timeout |
| `NetworkException`         | `network`        | local  | No response at all              |

The `category` values match the other Pactman SDKs exactly, so a category logged by this client is the same string a Node or Python service would log for the same failure.

API errors carry `status`, `apiCode`, `apiMessage`, `apiErrors`, `requestId`, `retryAfterSeconds`, `attempts` and `raw`. When a body cannot be deserialized, the metadata is still preserved and `raw` holds what the server actually sent.

`$error->toArray()` and `json_encode($error)` produce a sanitized view that is safe to log — the API key is never in it. The underlying transport failure is chained as `getPrevious()`.

## Timeouts

The default timeout is **30 seconds** per attempt, exported as `ClientConfig::DEFAULT_TIMEOUT`. It is always finite — there is no way to disable it.

```php
$client = new PactmanClient(apiKey: $apiKey, timeout: 10.0);

// Or per request.
$client->nonprofits->check($ein, timeout: 5.0);
```

Timeouts are expressed in **seconds**, matching cURL, Guzzle and the rest of the PHP ecosystem. (The Node SDK uses milliseconds; the defaults are the same 30 seconds either way.)

The timeout bounds **one attempt**. With retries enabled, the wall clock a call can consume is every attempt plus every backoff delay — [EX-24](./examples/ex-24-timeouts-and-budgets.php) works that arithmetic through. PHP has no cancellation primitive, so a request in flight runs to its deadline; where a hard budget matters, disable retries and schedule the next attempt yourself.

## Retries

Enabled by default: up to **2 retries** (3 attempts total), exponential backoff from 0.5s with full jitter, capped at 8 seconds per delay.

```php
use Pactman\NonprofitCheckPlus\Config\RetryOptions;

$client = new PactmanClient(
    apiKey: $apiKey,
    retry: new RetryOptions(
        maxRetries: 3,
        initialDelay: 0.5,
        maxDelay: 8.0,
        backoffFactor: 2.0,
        jitter: true,
        retryableStatuses: [429, 500, 502, 503, 504],
        respectRetryAfter: true,
    ),
);

// Disable entirely.
new PactmanClient(apiKey: $apiKey, retry: false);

// Or override per request.
$client->nonprofits->check($ein, retry: ['maxRetries' => 0]);
```

A `RetryOptions` replaces the policy outright; an **array** merges onto the policy already in force. That is what lets `check($ein, retry: ['maxRetries' => 1])` keep the client's other settings. An unknown key throws `ConfigurationException` rather than being silently ignored.

Retried: 429, 500, 502, 503, 504, and transient network failures. **Never** retried: 400, 401, 403, 404, and local validation errors — regardless of `retryableStatuses`. A valid `Retry-After` always takes precedence over computed backoff.

## Rate limits

The API returns HTTP 429 when you exceed your limit. The SDK maps that to `RateLimitException` and exposes `retryAfterSeconds`.

```php
try {
    $client->nonprofits->check($ein);
} catch (RateLimitException $error) {
    printf("Retry in %s seconds\n", $error->retryAfterSeconds ?? 'unknown');
}
```

With retries enabled, a 429 is retried automatically after the server's `Retry-After`, falling back to backoff when none is sent.

An optional client-side ceiling is available and off by default:

```php
$client = new PactmanClient(apiKey: $apiKey, maxRequestsPerSecond: 3.0);
```

Server-provided limits are authoritative and may vary by account and endpoint; treat this as a courtesy throttle, not a guarantee. For bulk workloads, prefer the bulk endpoint over a loop of single checks.

## Bringing your own HTTP client

The default is `CurlHttpClient`, which keeps one cURL handle for the life of the client so connections are reused across calls.

To send through a stack you already own — a shared pool, a corporate proxy, pinned certificates, a client that records traffic in tests — wrap any PSR-18 client:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Pactman\NonprofitCheckPlus\Http\Psr18HttpClient;

$factory = new Psr17Factory();

$client = new PactmanClient(
    apiKey: $apiKey,
    httpClient: new Psr18HttpClient($guzzle, $factory, $factory),
);
```

Retries, backoff, throttling, redaction and the error taxonomy all still apply — the adapter only changes who moves the bytes. **One exception: your client owns the deadline.** PSR-18 has no per-request timeout, so the SDK's `timeout` option is not enforced through this adapter; configure it on the client you pass in. A timeout your client raises surfaces as a `NetworkException` rather than a `TimeoutException`, because the SDK will not guess at the cause by reading an exception message.

For anything else, implement `HttpClientInterface` directly — it has one method, and [EX-15](./examples/ex-15-malformed-ein.php) uses a nine-line implementation to count requests.

For simple cURL tuning, the bundled client takes options directly:

```php
use Pactman\NonprofitCheckPlus\Http\CurlHttpClient;

new PactmanClient(
    apiKey: $apiKey,
    httpClient: new CurlHttpClient(caBundle: '/etc/ssl/corp.pem'),
);
```

## PHP-specific notes

Two places where PHP's semantics matter to this API:

**Numeric-string array keys become integers.** PHP canonicalizes `'411787097'` to an `int` key, while `'042103594'` stays a `string` because of the leading zero. Lookup applies the same rule, so `$byEin[$yourEin]` always finds the right record — but when you *iterate*, read the EIN from `$organization->ein` rather than from the key. For the same reason, `Ein::normalize()` refuses an `int`: `042103594` as an integer is `42103594`, a different EIN. If your EINs are array keys, pass `array_column($rows, 'ein')`, not `array_keys($rows)`.

**`isset()` on a model reports whether the API returned the field**, including when it returned it as `null`. See [Response models and raw data](#response-models-and-raw-data).

## Security

- Load the key from an environment variable or secret manager. Never commit it.
- **Server-side only.** The key must not reach an end user's device.
- The key is redacted from every diagnostic surface: exception messages, `$error->toArray()`, `$client->toArray()`, `json_encode()`, `print_r()`, `var_dump()`, and `(string) $client`. It is held in a closure rather than a property, so nothing that walks the object graph can reach it.
- The `apiKey` parameter is marked `#[\SensitiveParameter]`, so it is redacted from stack traces too — the path by which a credential most often reaches an error tracker or a support ticket. This is why the package requires PHP 8.2: on 8.1 the attribute has no effect and the key appears in `getTraceAsString()`. [EX-01](./examples/ex-01-secure-client-init.php) asserts this, and fails if it ever stops being true.
- Rotate the key if it is ever printed, logged, or committed.
- Nonprofit records may be subject to your own retention and privacy obligations. Storing responses is your call, not the SDK's.

## What this SDK does not tell you

The SDK exposes what the API returns and nothing more. It deliberately provides **no** composite `approved`, `eligible`, or `safe` verdict, and no boolean summarizing a source that the API does not itself express as a boolean.

A successful check is data, not a decision. Whether an organization qualifies for a grant, a donation, a match, or a partnership is a determination for your own legal, compliance, grantmaking, and risk policy.

## API reference

**Client** — `new PactmanClient(...)`

| Option                 | Type                                        | Default        | |
| ---------------------- | ------------------------------------------- | -------------- | - |
| `apiKey`               | `string`                                    | —              | **Required.** |
| `environment`          | `Environment\|string`                       | `'production'` | Named environment. |
| `baseUrl`              | `string`                                    | —              | Explicit host; overrides `environment`. |
| `timeout`              | `float`                                     | `30.0`         | Per-attempt timeout, in seconds. |
| `retry`                | `RetryOptions\|array\|false`                | 2 retries      | Retry policy. |
| `maxRequestsPerSecond` | `float`                                     | off            | Optional client-side throttle. |
| `defaultHeaders`       | `array<string, string>`                     | `[]`           | Extra headers; cannot override `Authorization`. |
| `httpClient`           | `HttpClientInterface`                       | `CurlHttpClient` | Where to send through. |

Accessors: `$client->nonprofits`, `$client->baseUrl()`, `$client->environment()`, `$client->timeout()`, `$client->retry()`, `$client->toArray()`.

**Methods**

- `$client->nonprofits->check(string $ein, ?float $timeout = null, RetryOptions|array|bool|null $retry = null, array $headers = []): SingleCheckResult`
- `$client->nonprofits->checkBulk(array $eins, bool $dedupe = false, ?float $timeout = null, RetryOptions|array|bool|null $retry = null, array $headers = []): BulkCheckResult`

**Results** — `SingleCheckResult` and `BulkCheckResult` share `checkCount`, `timeTakenMs`, `errors`, `requestId`, `status` and `raw`. `SingleCheckResult` adds `nonprofit`; `BulkCheckResult` adds `organizations`, `notFoundEins` and `byEin()`.

**Models** — `Nonprofit`, `Pub78Source`, `BmfSource`, `AroeSource`, `OfacSource` all extend `DataObject`: `has()`, `get()`, `toArray()`, property and array syntax, iteration, and `json_encode()`.

**Helpers** — `Ein::normalize()`, `Ein::normalizeMany()`, `Ein::isValid()`, `Sources::pub78()`, `Sources::bmf()`, `Sources::aroe()`, `Sources::ofac()`, `Environment::supported()`, `Environment::baseUrl()`, `PactmanException::isPactmanError()`

**Constants** — `Endpoints::MAX_BULK_EINS`, `Endpoints::SINGLE_CHECK_PATH`, `Endpoints::BULK_CHECK_PATH`, `ClientConfig::DEFAULT_TIMEOUT`, `Ein::LENGTH`, `Environment::DEFAULT`, `Version::VERSION`

Every public member carries a docblock, so editor hover documentation works without leaving your code.

## Examples

Thirty numbered, runnable examples cover secure setup, every source on the response, each error and edge case, bulk semantics, and five end-to-end workflows. Each is reproduced below, condensed to the point it makes; every snippet assumes a `$client` from [Quick start](#quick-start) and omits the output formatting the runnable file uses.

The full sources live in [`examples/`](./examples) — they read `PACTMAN_API_KEY` from the environment and contain no credentials.

```bash
git clone https://github.com/PledgeSoftwareTX/new-pactman-nonprofitcheck-php-sdk.git
cd new-pactman-nonprofitcheck-php-sdk && composer install

PACTMAN_API_KEY=your_key php examples/ex-01-secure-client-init.php
PACTMAN_API_KEY=your_key php examples/ex-03-identity-lookup.php 41-1787097
```

Examples for scenarios a live API will not produce on request — a revoked exemption, an OFAC match, an HTTP 429, a response carrying a field newer than this SDK — run against a bundled fixture server they start themselves. CI runs all thirty on every push:

```bash
php scripts/run-examples-against-mock.php                      # pass/fail
EXAMPLES_VERBOSE=1 php scripts/run-examples-against-mock.php   # with output
php scripts/run-examples-against-mock.php ex-22 ex-23          # a subset
```

Four shorter files sit alongside the numbered set for a first read: [`quickstart.php`](./examples/quickstart.php), [`bulk.php`](./examples/bulk.php), [`error-handling.php`](./examples/error-handling.php) and [`psr18-client.php`](./examples/psr18-client.php).

### Getting started

#### EX-01 — Secure client initialization

Load the key from the environment, pick an environment, set a finite timeout, build one reusable client — and prove the key reaches no log, no exception, no debug output. [Full source](./examples/ex-01-secure-client-init.php)

```php
$apiKey = getenv('PACTMAN_API_KEY');

if (!is_string($apiKey) || trim($apiKey) === '') {
    throw new RuntimeException('Set PACTMAN_API_KEY. Load it from your secret manager or an ignored .env.');
}

// One client, built once, reused for the life of the process. Constructing a
// client per request throws away connection reuse and any throttle state.
$client = new PactmanClient(
    apiKey: $apiKey,
    environment: Environment::Production,  // the default; naming it is explicit at review time
    timeout: 10.0,                          // the 30s default is often too long for a caller-facing service
);

// Every diagnostic surface, checked against the real key. None of them hold it:
// the key is not a property of anything, only a closure the transport calls.
$surfaces = [
    (string) $client,
    print_r($client, true),
    print_r($client->toArray(), true),
    (string) json_encode($client),
];

array_filter($surfaces, static fn (string $text): bool => str_contains($text, $apiKey));  // []
```

#### EX-02 — EIN normalization

A hyphenated, whitespace-padded EIN normalized to nine digits before the request, with the original kept for diagnostics. [Full source](./examples/ex-02-ein-normalization.php)

```php
$submitted = '  41-1787097  ';  // what an onboarding form actually sends

Ein::isValid($submitted);   // true
Ein::normalize($submitted); // "411787097"

// Store the normalized form as your key — it is what the API echoes back — and
// keep the raw input beside it so support can see what the applicant typed.
$applicant = ['ein_as_submitted' => $submitted, 'ein' => Ein::normalize($submitted)];

// check() normalizes internally too, so either form is the same request.
$result = $client->nonprofits->check($applicant['ein_as_submitted']);

$result->nonprofit?->ein;  // "411787097"
```

#### EX-03 — Identity lookup

EIN, name, AKA and Pactman profile URL, plus the raw envelope alongside the typed model. [Full source](./examples/ex-03-identity-lookup.php)

```php
$result = $client->nonprofits->check('41-1787097');
$nonprofit = $result->nonprofit;

if ($nonprofit !== null) {
    $nonprofit->ein;
    $nonprofit->organization_name;
    $nonprofit->organization_name_aka;  // frequently null: "none on file", not "none exists"
    $nonprofit->pactman_org_url;

    // Response metadata.
    $result->status;
    $result->requestId;
    $result->timeTakenMs;
    $result->checkCount;

    // The typed model is a view over the envelope, not a replacement for it.
    $result->raw['code'];
    $result->raw['message'];
    $result->raw['data']['ein'];
}
```

### Comparing and validating against the record

#### EX-04 — Applicant name comparison

Compare a submitted name with `organization_name` and `organization_name_aka` without treating punctuation or abbreviation differences as fraud. [Full source](./examples/ex-04-name-comparison.php)

```php
// The SDK deliberately has no namesMatch(). What counts as a match is policy,
// so the comparison lives in customer code where you can tune and audit it.
$normalize = static function (mixed $name): string {
    $text = preg_replace('/\b(INC|INCORPORATED|CORP|CO|LLC|LTD|THE)\b\.?/', '', strtoupper((string) $name));

    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ', $text)));
};

$nonprofit = $client->nonprofits->check($applicant['ein'])->nonprofit;

$candidates = array_values(array_filter(
    [$nonprofit?->get('organization_name'), $nonprofit?->get('organization_name_aka')],
    'is_string',
));

$outcome = match (true) {
    $candidates === [] => 'not_returned',  // no name came back — nothing was compared
    array_filter($candidates, fn ($c) => $normalize($c) === $normalize($applicant['legal_name'])) !== [] => 'agreement',
    default => 'mismatch',
};

// A mismatch is a reason to look, not a finding: organizations rebrand, file
// under a parent, and appear in IRS data under a name no donor would recognize.
$routed = $outcome === 'agreement' ? 'continue' : 'manual_review';
```

#### EX-05 — Validating the returned address

Ask whether the address the API returned is well-formed and self-consistent, before acting on it. [Full source](./examples/ex-05-address-validation.php)

```php
$nonprofit = $client->nonprofits->check($ein)->nonprofit;

// `state` and `state_name` are two fields for one fact, and the ZIP encodes the
// state a third time. A record can be complete and still contradict itself.
$stateValue = $nonprofit->get('state');
$state = is_string($stateValue) ? strtoupper(trim($stateValue)) : null;
$zipDigits = preg_replace('/\D/', '', Output::text($nonprofit->get('zip')));

$missing = array_filter(
    ['address_line1', 'city', 'state', 'zip'],
    static fn (string $c): bool => !$nonprofit->has($c) || $nonprofit->get($c) === null,
);

$claimants = ZIP_PREFIXES[substr($zipDigits, 0, 3)] ?? [];

$failures = array_filter([
    array_key_exists($state, STATES) ? null : 'state is not a USPS code',
    (STATES[$state] ?? null) === $nonprofit->get('state_name') ? null : 'state_name disagrees with state',
    in_array(strlen($zipDigits), [5, 9], true) ? null : 'zip is not 5 or 9 digits',
    // A check that cannot run reports nothing, never a failure: an incomplete
    // lookup table must not manufacture a finding about somebody's address.
    $claimants !== [] && !in_array($state, $claimants, true) ? 'zip belongs to another state' : null,
]);

// Three verdicts, and the middle one is the point. Absence is not validity.
$verdict = $failures !== [] ? 'inconsistent' : ($missing !== [] ? 'incomplete' : 'usable');

// Well-formed is not deliverable. USPS, Lob, Smarty and Google Address
// Validation answer that one, over the network, with a second credential.
```

### Reading the sources

#### EX-06 — IRS Business Master File status

Every IRS Business Master File field on the response — status, identity, subsection, exemption, ruling, foundation. [Full source](./examples/ex-06-bmf-status.php)

```php
$bmf = Sources::bmf($nonprofit);

if ($bmf === null) {
    // Not "not in the BMF" — the API returned no BMF fields at all. That is an
    // absence of evidence, not a negative finding. Route it to review.
} else {
    $bmf->status;  // one source's answer to one question — there is no isExempt() here
    $bmf->exempt_status_code;
    $bmf->deductability_text;
    $bmf->most_recent;

    $bmf->organization_name; $bmf->ein; $bmf->street_address;
    $bmf->city; $bmf->state; $bmf->church_message;
    $bmf->subsection; $bmf->subsection_description;
    $bmf->ruling_month; $bmf->ruling_year; $bmf->group_exemption;
    $bmf->foundation_code; $bmf->foundation_code_description;
    $bmf->foundation_type_code; $bmf->foundation_type_description;
    $bmf->foundation_509a_status;
    $bmf->filing_req_code; $bmf->pf_filing_req_cd;
}

// Reading the BMF in isolation is how a revoked or sanctioned organization
// passes a check — see EX-08 and EX-10.
```

#### EX-07 — Publication 78 and deductibility

Publication 78 verification and deductibility entries, with a donation policy applied in customer code. [Full source](./examples/ex-07-pub78-deductibility.php)

```php
$pub78 = Sources::pub78($nonprofit);

$pub78?->verified;  // true | false | null
$pub78?->indicator;
$pub78?->church_message;
$pub78?->most_recent;
$pub78?->source_org_type_1;  // …_2, …_3

foreach ($nonprofit->organizationTypes() as $entry) {
    $entry['deductibility_status_description'];
    $entry['deductibility_limitation'];
    $entry['organization_type'];
}

// Your policy, expressed against the source data. Change the predicate, not the
// SDK — nothing here is a verdict the API handed down.
const ACCEPTED_LIMITATIONS = ['50%', '60%'];

$limitations = array_column($nonprofit->organizationTypes(), 'deductibility_limitation');

$eligibleUnderThisPolicy = $pub78?->verified === true
    && array_intersect($limitations, ACCEPTED_LIMITATIONS) !== [];
```

#### EX-08 — Automatic revocation detected

An organization in the IRS Automatic Revocation data, flagged and recorded with its source fields. [Full source](./examples/ex-08-automatic-revocation.php)

```php
$aroe = Sources::aroe($nonprofit);
$revoked = $aroe?->revocation_code !== null || $aroe?->revocation_date !== null;

// The application's policy, in one place, expressed against source fields.
$action = match (true) {
    !$revoked => 'continue',
    $aroe?->reinstatement_date !== null => 'manual_review',
    default => 'block',
};

// What you keep is what you can explain later. Store the source fields, the
// request identifier and the time you looked — not just the verdict.
const AUDITED = [
    'revocation_code', 'revocation_date', 'reinstatement_date', 'aroe_list_published_date',
    'bmf_status',      // revocation shows up in the other sources too
    'pub78_verified',
];

$findings = [];

foreach (AUDITED as $field) {
    // Absent keys stay absent, so the record cannot imply a null the API never sent.
    if ($nonprofit->has($field)) {
        $findings[$field] = $nonprofit->get($field);
    }
}

$auditRecord = [
    'ein' => $nonprofit->ein,
    'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'request_id' => $result->requestId,
    'action' => $action,
    'source_findings' => $findings,
];
```

#### EX-09 — Revocation with reinstatement

Revocation and reinstatement dates kept separate, and the questions reinstatement does not answer. [Full source](./examples/ex-09-revocation-reinstatement.php)

```php
$aroe = Sources::aroe($nonprofit);

// The API formats dates as `M/DD/YYYY h:mm:ss AM`. Parse; never reformat in place.
$revokedAt = ApiDate::parse($aroe?->revocation_date);
$reinstatedAt = ApiDate::parse($aroe?->reinstatement_date);

// Nothing collapses the two into a "currently revoked" boolean — that boolean
// would lose the interval, and donations dated inside it may need handling.
if ($revokedAt !== null && $reinstatedAt !== null) {
    $lapsedDays = $revokedAt->diff($reinstatedAt)->days;
}

// Reinstatement resolves one question, not every question: was it retroactive?
// Do gifts made during the lapse need re-characterizing? Does your grant
// agreement require continuous exemption? This record still goes to review.
```

#### EX-10 — OFAC screening result

Four distinct OFAC outcomes — no match, match, null, and not screened at all. [Full source](./examples/ex-10-ofac-screening.php)

```php
// The SDK exposes no hasOfacMatch boolean: deriving one means pattern-matching
// English the source can reword at any time. The one textual test below
// escalates and never clears — anything unrecognized falls through to review.
$classify = static function (Nonprofit $nonprofit): string {
    $ofac = Sources::ofac($nonprofit);

    if ($ofac === null) {
        return 'unavailable';  // no OFAC field at all; nothing was screened
    }

    if (!$ofac->has('status') || $ofac->get('status') === null) {
        return 'null';
    }

    $status = (string) $ofac->get('status');

    return match (true) {
        stripos($status, 'UID:') !== false => 'match',
        stripos($status, 'NOT included') !== false => 'no_match',
        default => 'needs_review',
    };
};

// Four states, four destinations. None of them is "approve automatically".
const ROUTING = [
    'no_match' => 'continue — screened against the SDN list with no match',
    'match' => 'block and escalate to compliance',
    'null' => 'hold — the field was returned empty; treat as unscreened, not as cleared',
    'unavailable' => 'hold — no OFAC data was returned',
    'needs_review' => 'hold — the status text was not recognized by this application',
];

ROUTING[$classify($nonprofit)];
```

#### EX-11 — Cross-source conflict

`irs_bmf_pub78_conflict` handled by recording both sources, not by picking one. [Full source](./examples/ex-11-source-conflict.php)

```php
$bmf = Sources::bmf($nonprofit);
$pub78 = Sources::pub78($nonprofit);
$findings = [];

// The flag the API sets is authoritative; the comparisons only explain it.
if ($nonprofit->irs_bmf_pub78_conflict === true) {
    $findings[] = 'The API flagged a BMF / Publication 78 disagreement.';
}

if ($bmf?->status === true && $pub78?->verified === false) {
    $findings[] = 'The BMF lists the organization as exempt; Publication 78 does not list it.';
}

if ($bmf?->status === false && $pub78?->verified === true) {
    $findings[] = 'Publication 78 lists the organization; the BMF does not show it as exempt.';
}

// Both sides are kept, side by side, for the reviewer. Silently preferring one
// source means being wrong for some organization with the evidence destroyed.
$reviewRecord = $findings === [] ? null : [
    'ein' => $nonprofit->ein,
    'request_id' => $result->requestId,
    'findings' => $findings,
    'sources' => ['bmf' => $bmf?->toArray(), 'pub78' => $pub78?->toArray()],
];
```

#### EX-12 — Organization type and foundation classification

Organization types, foundation and subsection classification for a grantmaker or DAF display. [Full source](./examples/ex-12-foundation-classification.php)

```php
$bmf = Sources::bmf($nonprofit);

// What a grant officer sees. Every value is copied, none is computed — and the
// descriptions come from the API's own *_description fields, which stay correct
// when the source changes. A lookup table in your repository does not.
$classificationPanel = [
    'subsection' => $bmf?->subsection_description,
    'foundation_code' => $bmf?->foundation_code_description,
    'foundation_type' => $bmf?->foundation_type_description,
    'status_509a' => $bmf?->foundation_509a_status,
    'deductibility' => $bmf?->deductability_text,
    'entries' => $nonprofit->organizationTypes(),
];

// A private foundation grantee is not disqualified — it is routed differently,
// because expenditure responsibility and the deductibility limit both change.
$isPrivateFoundation = $bmf?->foundation_type_code === 'pf' || $bmf?->pf_filing_req_cd === '1';
```

#### EX-13 — Filing and exemption metadata

Filing and exemption codes preserved exactly, or mapped through documented tables with an unknown-value fallback. [Full source](./examples/ex-13-filing-exemption-metadata.php)

```php
const FILING_REQUIREMENTS = [
    '00' => 'Not required to file (income below threshold)',
    '01' => '990 (all other) or 990-EZ return',
    '02' => '990 - Required to file Form 990-N',
];

/**
 * A documented table with an explicit unknown fallback. A value the IRS adds
 * reads as "unrecognized" — never as a blank, and never as the wrong label.
 */
function describe(array $table, SourceView $source, string $field): array
{
    if (!$source->has($field) || $source->get($field) === null) {
        return ['code' => null, 'known' => false, 'display' => '<not returned>'];
    }

    $code = (string) $source->get($field);
    $description = $table[$code] ?? null;

    return ['code' => $code, 'known' => $description !== null, 'display' => $description ?? "unrecognized code \"{$code}\""];
}

describe(FILING_REQUIREMENTS, $bmf, 'filing_req_code');

// Codes the API already describes for you: read its description, do not shadow
// it with a local table that will drift.
$bmf->subsection; $bmf->subsection_description;
$bmf->foundation_code; $bmf->foundation_code_description;
$bmf->ruling_month; $bmf->ruling_year;  // raw values, preserved exactly, null included

// Never coerce an unrecognized code to a default. "Unknown" is a real state,
// and it usually means review rather than approval.
```

#### EX-14 — Data freshness and report metadata

Source timestamps, report date and request timing, feeding an application-owned re-review rule. [Full source](./examples/ex-14-data-freshness.php)

```php
// Your rule. The SDK has no isStale() and no default threshold, because 90 days
// is prudent for one workflow and reckless for another.
const RE_REVIEW_AFTER_DAYS = 90;

const TIMESTAMP_FIELDS = [
    'organization_info_last_modified',
    'report_date',        // when this response was generated
    'most_recent_bmf',    // when each list was last refreshed
    'most_recent_pub78',
    'ofac_list_published_date',
    'aroe_list_published_date',
];

$ages = [];

foreach (TIMESTAMP_FIELDS as $field) {
    $ages[$field] = ApiDate::ageInDays($nonprofit->get($field));
}

$undated = array_keys(array_filter($ages, static fn (?int $age): bool => $age === null));
$oldest = max(array_filter($ages, static fn (?int $age): bool => $age !== null) ?: [0]);

// The oldest source governs, and an undated source is not a fresh one.
$needsReReview = $oldest > RE_REVIEW_AFTER_DAYS || $undated !== [];

// Store the timestamps with the verification record, not just the outcome. "We
// checked and it was fine" is not an answer six months later; "we checked on
// this date against BMF data published on that date" is.
```

### Errors and edge cases

#### EX-15 — Malformed EIN rejected locally

Every malformed shape rejected locally, with an instrumented HTTP client proving no request was sent. [Full source](./examples/ex-15-malformed-ein.php)

```php
/**
 * A counting wrapper around the real client, to prove the claim rather than
 * assert it. If any call below reaches the network, this number moves.
 */
final class CountingHttpClient implements HttpClientInterface
{
    public int $requestsSent = 0;

    public function __construct(private readonly HttpClientInterface $inner) {}

    public function send(HttpRequest $request): HttpResponse
    {
        ++$this->requestsSent;

        return $this->inner->send($request);
    }
}

$counting = new CountingHttpClient(new CurlHttpClient());
$client = new PactmanClient(apiKey: $apiKey, httpClient: $counting);

foreach (['41178709', '4117870977', '41-178709A', '', '   ', '41.1787097', '411-787097'] as $value) {
    try {
        $client->nonprofits->check($value);
    } catch (ValidationException $error) {
        $error->origin;           // ErrorOrigin::Local
        $error->issues[0];        // index, value, message — enough to highlight the form field
    }
}

// Bulk reports every failure at once, by index.
try {
    $client->nonprofits->checkBulk(['411787097', 'nope', '996589560']);
} catch (ValidationException $error) {
    $error->issues;
}

$counting->requestsSent;  // 0 — bad input costs no quota, no latency, no rate-limit budget
```

#### EX-16 — EIN not found

A well-formed EIN with no record: `NotFoundException`, sanitized diagnostics, and why bulk behaves differently. [Full source](./examples/ex-16-not-found.php)

```php
try {
    $client->nonprofits->check('999999999');
} catch (NotFoundException $error) {
    // Stable identity: class, category, origin. Never parse getMessage().
    $error->category;                              // ErrorCategory::NotFound
    $error->origin;                                // ErrorOrigin::Api
    PactmanException::isPactmanError($error);      // true

    // The envelope's own detail survives onto the exception.
    $error->status; $error->apiCode; $error->apiMessage; $error->requestId; $error->apiErrors;
    $error->attempts;  // 1 — not-found is not a transient failure, so it is never retried

    $error->toArray();  // sanitized: safe to log, safe to attach to a support ticket
}

// The bulk endpoint behaves differently: unmatched EINs come back on a 200.
$mixed = $client->nonprofits->checkBulk(['411787097', '999999999']);

$mixed->status;         // 200
$mixed->notFoundEins;   // ["999999999"]

// Only a request where nothing at all matched is a 404.
```

#### EX-22 — Rate limits and `Retry-After`

HTTP 429, `Retry-After`, bounded retries and a client-side rate ceiling. [Full source](./examples/ex-22-rate-limit.php)

```php
// 1. Retries off, so the 429 reaches the caller untouched.
try {
    $client->nonprofits->check($ein, retry: false);
} catch (RateLimitException $error) {
    $error->status;             // 429
    $error->retryAfterSeconds;  // the server's number, when it sent one
    $error->requestId; $error->attempts; $error->apiErrors;

    // Schedule your own backoff from the server's number; fall back when absent.
    $wait = $error->retryAfterSeconds ?? 5.0;
    $retryAt = (new DateTimeImmutable())->modify('+' . (int) ceil($wait) . ' seconds');
}

// 2. Bounded automatic retry. Retry-After wins over computed backoff, and
//    retries stay finite — the SDK never retries indefinitely.
$client->nonprofits->check($ein, retry: ['maxRetries' => 1, 'respectRetryAfter' => true]);

// 3. Reduce pressure rather than absorb rejections: cap the outbound rate, and
//    prefer one bulk call to a fan-out of single ones. The SDK throttles, but it
//    does not queue on your behalf.
$paced = new PactmanClient(apiKey: $apiKey, retry: ['maxRetries' => 2], maxRequestsPerSecond: 3.0);
```

#### EX-23 — Transient failures and retries

Transient 5xx and connection failures retried with jittered backoff; auth, validation and not-found never retried. [Full source](./examples/ex-23-transient-retries.php)

```php
// Two 503s absorbed, one successful result returned to the caller. Backoff
// grows exponentially and is jittered, so parallel clients scatter.
$result = $client->nonprofits->check($ein, retry: ['maxRetries' => 3, 'initialDelay' => 0.5, 'maxDelay' => 8.0]);

// Never retried, whatever retryableStatuses contains. Retrying a 404 cannot
// make a record exist; retrying a rejected key just burns it three times.
try {
    $client->nonprofits->check($missingEin, retry: ['maxRetries' => 5, 'retryableStatuses' => [404, 500]]);
} catch (NotFoundException $error) {
    $error->attempts;  // 1
}

// A connection that never reached a server: retried, then surfaced with the
// attempt count and the underlying cause chained.
try {
    $unreachable->nonprofits->check($ein);
} catch (NetworkException $error) {
    $error->attempts;
    $error->getPrevious();  // the underlying TransportException
}

// A retried failure that exhausts its budget is an outage. Record it as "not
// checked", never as a pass.
```

#### EX-24 — Timeouts and operation budgets

A per-attempt deadline against the wall clock a retried call can actually consume. [Full source](./examples/ex-24-timeouts-and-budgets.php)

```php
// Two different events, two different types. Conflating them hides which side
// gave up: a timeout means raise the budget or shed load; a connection failure
// means the other end was not there at all.
try {
    $client->nonprofits->check($ein, timeout: 0.5, retry: false);
} catch (TimeoutException $error) {
    $error->timeout;   // the deadline you configured expired, in seconds
    $error->category;  // ErrorCategory::Timeout, origin Local
}

// Retries multiply the wall clock. The per-attempt deadline bounds one attempt;
// the worst case for the whole call is every attempt plus every backoff delay.
$policy = new RetryOptions(maxRetries: 2, initialDelay: 0.5, maxDelay: 8.0, backoffFactor: 2.0);
$perAttempt = 2.0;

$backoff = 0.0;

for ($attempt = 1; $attempt <= $policy->maxRetries; ++$attempt) {
    $backoff += min($policy->initialDelay * $policy->backoffFactor ** ($attempt - 1), $policy->maxDelay);
}

$worstCase = $perAttempt * ($policy->maxRetries + 1) + $backoff;

// With jitter on — the default — real delays land anywhere in [0, computed], so
// that is a ceiling rather than an estimate. A server-supplied Retry-After is
// honored even when it exceeds maxDelay, so a 429 can outlast it: where a hard
// budget matters, disable retries and schedule the next attempt yourself.
```

#### EX-25 — Raw response and forward compatibility

A record from a newer API version: unknown fields and an unknown enum value, both readable, neither fatal. [Full source](./examples/ex-25-raw-and-forward-compat.php)

```php
$result = $client->nonprofits->check($ein);
$nonprofit = $result->nonprofit;

// Known fields deserialize exactly as they always have.
Sources::bmf($nonprofit)?->status;

// Fields this SDK version does not declare ride along on the same object. No
// upgrade needed, and no deserialization failure — narrow them deliberately.
$registration = $nonprofit->get('state_charity_registration_status');

if (is_string($registration)) {
    // …
}

// An unrecognized value in a documented field. This is the case that breaks
// applications which map eagerly into an enum and default the miss.
const KNOWN_FOUNDATION_TYPES = ['pc', 'pf', 'po'];

$handled = in_array(Sources::bmf($nonprofit)?->foundation_type_code, KNOWN_FOUNDATION_TYPES, true)
    ? 'a known classification'
    : 'unknown — routed to review, not defaulted to a known type';

$result->raw;                                     // the parsed body, unmodified — persist it as evidence
$result->raw['data'] === $nonprofit->toArray();   // true
```

### Bulk

#### EX-17 — Bulk screening of a list

Screening a grantee list, iterating organization-level results and reading the response envelope. [Full source](./examples/ex-17-bulk-screening.php)

```php
// One bulk request is one round trip and one rate-limit slot. Prefer it to a
// loop of single checks.
$result = $client->nonprofits->checkBulk(array_column($portfolio, 'ein'));

$result->status; $result->raw['code']; $result->timeTakenMs; $result->checkCount;
count($result->organizations); count($result->errors); $result->notFoundEins;

// Index by EIN. The response is a set of matched records, not a row-for-row
// answer to your input list — see EX-18.
$byEin = $result->byEin();

foreach ($portfolio as $entry) {
    $organization = $byEin[$entry['ein']] ?? null;

    if ($organization === null) {
        continue;  // no record returned — not a pass
    }

    Sources::bmf($organization)?->status;
    Sources::pub78($organization)?->verified;
    Sources::aroe($organization)?->revocation_date !== null;
    Sources::ofac($organization)?->status;
}

foreach ($result->errors as $detail) {
    $detail->resource; $detail->code; $detail->reason; $detail->eins;
}
```

#### EX-18 — Input order and duplicate EINs

Response order does not follow request order, duplicates collapse in the response but still bill, and usage is read rather than inferred. [Full source](./examples/ex-18-bulk-order-and-duplicates.php)

```php
// Deliberately unsorted, with one EIN repeated. The SDK sends them exactly as
// supplied: it does not reorder and it does not deduplicate.
$requested = ['996589560', '411787097', '996589560', '135562308'];

$before = $client->nonprofits->check('411787097');
$result = $client->nonprofits->checkBulk($requested);

count($result->organizations);  // 3 — the duplicate came back once

// Positional pairing is invalid. This is the pairing that always holds.
$byEin = $result->byEin();

// Usage is reported, not inferred. Every submitted EIN is billable, duplicates
// included, so a count derived from unique inputs will disagree with the invoice.
($result->checkCount ?? 0) - ($before->checkCount ?? 0);

// Opt in when duplicates are an artifact of your data rather than intent.
$client->nonprofits->checkBulk($requested, dedupe: true);
```

#### EX-19 — Partial success and item-level errors

Mixed outcomes on one HTTP 200: usable records, item-level errors, and a full input reconciliation. [Full source](./examples/ex-19-bulk-partial-success.php)

```php
$submitted = ['411787097', '999999999', '996589560', '123456789'];
$result = $client->nonprofits->checkBulk($submitted);

$result->status;         // 200 — some matched and some did not, which is a success
$result->organizations;  // ordinary records; nothing about a sibling failure degrades them
$result->errors;         // ApiErrorDetail: resource, code, reason, eins
$result->notFoundEins;

// Reconcile every input against an outcome. This is the loop that keeps a
// portfolio import honest.
$matched = array_map(static fn ($o): string => (string) $o->ein, $result->organizations);

foreach ($submitted as $ein) {
    $outcome = match (true) {
        in_array($ein, $matched, true) => 'matched',
        in_array($ein, $result->notFoundEins, true) => 'no record — reported in errors',
        default => 'UNACCOUNTED FOR — do not treat as checked',
    };
}

// An EIN the API has no record for is a gap in the data, not a negative finding
// about the organization. Route it to review; do not record it as "screened".
```

#### EX-20 — Bulk batch limits

Empty and over-limit batches rejected against `Endpoints::MAX_BULK_EINS`, plus chunking a larger list yourself. [Full source](./examples/ex-20-bulk-batch-limits.php)

```php
Endpoints::MAX_BULK_EINS;  // 50 — exported, so your app does not hard-code it

try {
    $client->nonprofits->checkBulk([]);
} catch (ValidationException $error) {
    // "checkBulk requires at least one EIN."
}

try {
    $client->nonprofits->checkBulk($fiftyOneEins);
} catch (ValidationException $error) {
    // "…accepts at most 50 EINs per request, received 51. Split the input into
    //  batches; this SDK does not chunk automatically."
}

// Chunking is yours to do, deliberately: the SDK will not silently turn one call
// into several billable requests behind your back.
foreach (array_chunk($portfolio, 25) as $chunk) {
    $result = $client->nonprofits->checkBulk($chunk);

    $organizations = [...$organizations, ...$result->organizations];
    $notFound = [...$notFound, ...$result->notFoundEins];
}
```

#### EX-21 — Usage tracking

`nonprofit_check_count` as a cumulative billing-cycle total that resets each cycle — never a per-request size. [Full source](./examples/ex-21-usage-tracking.php)

```php
$first = $client->nonprofits->check($ein);
$second = $client->nonprofits->check($otherEin);
$bulk = $client->nonprofits->checkBulk([$ein, $otherEin, $thirdEin]);

$bulk->checkCount;                                   // the cycle total, not 3
$bulk->checkCount - ($second->checkCount ?? 0);      // what the bulk call consumed

// Unmatched EINs are not billed, so a delta can be smaller than the batch you
// sent. Read the number the API reports rather than reconstructing usage.
$mixed = $client->nonprofits->checkBulk([$ein, '999999999', '123456789']);

// Read it as a usage gauge against your quota. A value smaller than the one you
// saw yesterday means a new billing cycle started — not that usage went backwards.
$quota - ($mixed->checkCount ?? 0);
```

### End-to-end workflows

#### EX-26 — Nonprofit onboarding workflow

An applicant checked end to end: validation, every source finding, one explicit policy, and the evidence stored. [Full source](./examples/ex-26-onboarding-workflow.php)

```php
try {
    $result = $client->nonprofits->check($applicant['ein']);
} catch (ValidationException $error) {
    return 'reject — invalid EIN, nothing was sent';
} catch (NotFoundException) {
    return 'manual_review — no IRS record for this EIN';
} catch (PactmanException $error) {
    // An outage is "not checked". It is never a pass.
    return 'retry_later — the check did not complete';
}

$nonprofit = $result->nonprofit;
$bmf = Sources::bmf($nonprofit);
$pub78 = Sources::pub78($nonprofit);
$aroe = Sources::aroe($nonprofit);
$ofac = Sources::ofac($nonprofit);

$revoked = $aroe?->revocation_date !== null || $aroe?->revocation_code !== null;
$ofacUnresolved = $ofac === null || $ofac->status === null || str_contains((string) $ofac->status, 'UID:');

// One policy, in one place, expressed against source fields. Change this
// function, not the SDK — none of it is a verdict the API handed down.
$outcome = match (true) {
    $ofacUnresolved => 'block — escalate to compliance',
    $revoked && $aroe?->reinstatement_date === null => 'block — exemption revoked',
    $revoked => 'manual_review — revoked and reinstated',
    $nonprofit->irs_bmf_pub78_conflict === true => 'manual_review — sources disagree',
    $bmf?->status === true && $pub78?->verified === true => 'approve',
    default => 'manual_review — findings incomplete',
};

// Store the evidence, not just the verdict.
$evidence = [
    'ein' => $nonprofit->ein,
    'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'request_id' => $result->requestId,
    'outcome' => $outcome,
    'sources' => ['bmf' => $bmf?->toArray(), 'pub78' => $pub78?->toArray(), 'aroe' => $aroe?->toArray(), 'ofac' => $ofac?->toArray()],
];
```

#### EX-27 — Donor-advised fund grant screening

A DAF sponsor screening recommended grantees, routing private foundations to expenditure responsibility rather than rejecting them. [Full source](./examples/ex-27-daf-grant-screening.php)

```php
$result = $client->nonprofits->checkBulk(array_column($recommendations, 'ein'));
$byEin = $result->byEin();

foreach ($recommendations as $recommendation) {
    $organization = $byEin[$recommendation['ein']] ?? null;

    if ($organization === null) {
        continue;  // hold — no record returned for this EIN
    }

    $bmf = Sources::bmf($organization);
    $aroe = Sources::aroe($organization);

    // A private foundation grantee is not disqualified. It changes the path:
    // expenditure responsibility applies, and the deductibility limit differs.
    $isPrivateFoundation = $bmf?->foundation_type_code === 'pf' || $bmf?->pf_filing_req_cd === '1';

    $decision = match (true) {
        $aroe?->revocation_date !== null => 'decline — exemption revoked',
        Sources::pub78($organization)?->verified !== true => 'hold — not verified in Publication 78',
        $isPrivateFoundation => 'approve with expenditure responsibility',
        default => 'approve — standard grant path',
    };
}
```

#### EX-28 — CRM enrichment

Enriching stored records, writing back only what the API returned, and never overwriting good data with a field the API omitted. [Full source](./examples/ex-28-crm-enrichment.php)

```php
// The EIN is a field on the row, not the array key: PHP would canonicalize a
// numeric-string key to an int, and an EIN that reached the SDK as an int would
// have lost any leading zero.
$result = $client->nonprofits->checkBulk(array_column($crm, 'ein'));
$byEin = $result->byEin();

foreach ($crm as $row) {
    $organization = $byEin[$row['ein']] ?? null;

    if ($organization === null) {
        continue;  // skip — no record returned; the stored row is left alone
    }

    foreach (ENRICHMENT_MAP as $column => $field) {
        // The distinction that keeps an enrichment job honest: a field the API
        // did not return must never overwrite a value you already hold. Writing
        // null over good data because "the API said null" is the classic bug —
        // and here the API did not say anything at all.
        if (!$organization->has($field)) {
            continue;  // not returned
        }

        $value = $organization->get($field);

        if ($value === null) {
            continue;  // returned as null — still not evidence your value is wrong
        }

        if ($value !== $row[$column]) {
            $updates[$column] = $value;
        }
    }

    // Record where the data came from and when, so a later reviewer can tell an
    // enriched value from one a human typed.
    $provenance = [
        'source' => 'pactman-nonprofit-check-plus',
        'request_id' => $result->requestId,
        'enriched_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'fields' => array_keys($updates),
    ];
}
```

#### EX-29 — Pre-disbursement recheck

Re-checking an approved grant before the money moves, and comparing findings rather than trusting the stored verdict. [Full source](./examples/ex-29-pre-disbursement-recheck.php)

```php
/** The fields a disbursement decision actually rests on. */
const MATERIAL_FIELDS = [
    'bmf_status', 'pub78_verified',
    'revocation_code', 'revocation_date', 'reinstatement_date',
    'ofac_status', 'irs_bmf_pub78_conflict',
];

// A recheck that fails to complete blocks the disbursement. It never falls back
// to the stored verdict: "we could not check" is not "it is still fine".
try {
    $result = $client->nonprofits->check($approval['ein']);
} catch (PactmanException $error) {
    return 'hold the disbursement';
}

// Compare field by field. A verdict stored at approval time cannot tell you what
// changed; the findings can.
$changes = [];

foreach (MATERIAL_FIELDS as $field) {
    $then = $approval['findings_at_approval'][$field] ?? null;
    $now = $result->nonprofit?->has($field) ? $result->nonprofit->get($field) : null;

    if ($then !== $now) {
        $changes[$field] = ['then' => $then, 'now' => $now];
    }
}

$decision = match (true) {
    Sources::aroe($result->nonprofit)?->revocation_date !== null => 'STOP — exemption revoked since approval',
    $changes !== [] => 'hold for review — material findings changed',
    default => 'release — findings unchanged since approval',
};

// Exemption status is a fact about a moment, not a property of an organization.
// The gap between approval and disbursement is exactly where it changes.
```

#### EX-30 — Portfolio re-verification

A standing portfolio re-checked on a schedule: chunked bulk requests, findings compared against the last run, and a report of what moved. [Full source](./examples/ex-30-portfolio-reverification.php)

```php
// A courtesy throttle, because this job runs unattended against a whole portfolio.
$client = new PactmanClient(apiKey: $apiKey, retry: ['maxRetries' => 2], maxRequestsPerSecond: 5.0);

$current = [];

foreach (array_chunk($portfolio, 25) as $chunk) {
    try {
        $result = $client->nonprofits->checkBulk($chunk);
    } catch (PactmanException $error) {
        // A chunk that did not complete leaves its EINs unknown — never "clear".
        continue;
    }

    foreach ($result->byEin() as $ein => $organization) {
        $current[(string) $ein] = classify($organization);
    }

    foreach ($result->notFoundEins as $ein) {
        $current[$ein] = 'no_record';
    }
}

// Compare against the last run. What moved is the report; the rest is noise.
foreach ($portfolio as $ein) {
    $before = $lastRun[$ein];
    // An EIN this run could not classify is "not checked", and it must appear in
    // the report as such. A job that silently keeps yesterday's answer is not
    // re-verifying anything.
    $after = $current[$ein] ?? 'NOT CHECKED';
}
```

## Development

```bash
composer install

composer test              # PHPUnit
composer analyse           # PHPStan at max level over src, tests, examples and scripts
composer examples:smoke    # every documented example against the bundled fixture API
composer verify            # all three, which is what CI runs
```

Two things worth knowing about the suite:

- Most tests substitute the HTTP client, which is what keeps them fast and deterministic. `CurlHttpClientTest` does not — it runs the bundled cURL transport against a real socket, so ext-curl, header parsing and the wire format are actually exercised.
- `ResponseContractTest` holds [`src/response-contract.json`](./src/response-contract.json) and `Nonprofit`'s `@property-read` list in sync. The contract is what this package promises each response looks like; a change to one without the other fails the build.

To check a live deployment against that contract:

```bash
php scripts/mock-server.php --port 8787                 # or point at the real API
PACTMAN_API_KEY=your_key php scripts/smoke-live.php 41-1787097 996589560
```

It reports fields the API stopped sending, started sending, or changed the shape of — and never prints a value from the response, so its output is safe to paste into an issue.

## Support

- API documentation: <https://pactman.org/nonprofitcheckplus-api/docs>
- Node.js and Python SDKs for the same API: <https://github.com/PledgeSoftwareTX/new-pactman-nonprofitcheck-api-sdks>
- Issues: <https://github.com/PledgeSoftwareTX/new-pactman-nonprofitcheck-php-sdk/issues>

## License

MIT — see [LICENSE](./LICENSE).
