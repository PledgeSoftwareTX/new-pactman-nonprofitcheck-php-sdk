# Examples

Runnable examples for `pactmandev/nonprofit-check-plus`. Every one reads
`PACTMAN_API_KEY` from the environment and contains no credentials.

```bash
composer install                                # examples autoload the package
PACTMAN_API_KEY=your_key php examples/ex-01-secure-client-init.php
```

Run all of them against the bundled fixture API, which is what CI does:

```bash
php scripts/run-examples-against-mock.php                      # all examples, pass/fail only
EXAMPLES_VERBOSE=1 php scripts/run-examples-against-mock.php   # with their output
php scripts/run-examples-against-mock.php ex-22 ex-23          # a subset
```

## Where each example runs

| Target                            | Examples                                                | |
| --------------------------------- | ------------------------------------------------------- | - |
| Production, or `PACTMAN_BASE_URL` | `ex-01` – `ex-04`, `ex-06`, `ex-07`, `ex-15`            | Ordinary lookups. Pass an EIN as the first argument where noted. |
| The bundled fixture API           | everything else                                         | Needs a record or a response a live API will not produce on request: a revoked exemption, an OFAC match, an HTTP 429, an address that contradicts itself, a field newer than this SDK. |

Fixture-backed examples start [`scripts/mock-server.php`](../scripts/mock-server.php)
themselves and shut it down on the way out. Set `PACTMAN_BASE_URL` to point them
somewhere else. Fixture records live in [`scripts/lib/Fixtures.php`](../scripts/lib/Fixtures.php).

## The examples

### Getting started

|       |                                                                    | |
| ----- | ------------------------------------------------------------------ | - |
| EX-01 | [ex-01-secure-client-init.php](./ex-01-secure-client-init.php)     | Load the key from the environment, pick an environment, set a finite timeout, build one reusable client — and prove the key reaches no log, no exception, no debug output. |
| EX-02 | [ex-02-ein-normalization.php](./ex-02-ein-normalization.php)       | A hyphenated, whitespace-padded EIN normalized to nine digits before the request, with the original kept for diagnostics. |
| EX-03 | [ex-03-identity-lookup.php](./ex-03-identity-lookup.php)           | EIN, name, AKA and Pactman profile URL, plus the raw envelope alongside the typed model. |

### Comparing and validating against the record

|       |                                                                    | |
| ----- | ------------------------------------------------------------------ | - |
| EX-04 | [ex-04-name-comparison.php](./ex-04-name-comparison.php)           | Compare a submitted name with `organization_name` and `organization_name_aka` without treating punctuation or abbreviation differences as fraud. |
| EX-05 | [ex-05-address-validation.php](./ex-05-address-validation.php)     | Validate the returned address structurally — present, self-consistent, or neither. Complete is not the same as correct. |

### Reading the sources

|       |                                                                                    | |
| ----- | ---------------------------------------------------------------------------------- | - |
| EX-06 | [ex-06-bmf-status.php](./ex-06-bmf-status.php)                                     | Every IRS Business Master File field on the response — status, identity, subsection, exemption, ruling, foundation. |
| EX-07 | [ex-07-pub78-deductibility.php](./ex-07-pub78-deductibility.php)                   | Publication 78 verification and deductibility entries, with a donation policy applied in customer code. |
| EX-08 | [ex-08-automatic-revocation.php](./ex-08-automatic-revocation.php)                 | An organization in the IRS Automatic Revocation data, flagged and recorded with its source fields. |
| EX-09 | [ex-09-revocation-reinstatement.php](./ex-09-revocation-reinstatement.php)         | Revocation and reinstatement dates kept separate, and the questions reinstatement does not answer. |
| EX-10 | [ex-10-ofac-screening.php](./ex-10-ofac-screening.php)                             | Four distinct OFAC outcomes — no match, match, null, and not screened at all. |
| EX-11 | [ex-11-source-conflict.php](./ex-11-source-conflict.php)                           | `irs_bmf_pub78_conflict` handled by recording both sources, not by picking one. |
| EX-12 | [ex-12-foundation-classification.php](./ex-12-foundation-classification.php)       | Organization types, foundation and subsection classification for a grantmaker or DAF display. |
| EX-13 | [ex-13-filing-exemption-metadata.php](./ex-13-filing-exemption-metadata.php)       | Filing and exemption codes preserved exactly, or mapped through documented tables with an unknown-value fallback. |
| EX-14 | [ex-14-data-freshness.php](./ex-14-data-freshness.php)                             | Source timestamps, report date and request timing, feeding an application-owned re-review rule. |

### Errors and edge cases

|       |                                                                            | |
| ----- | -------------------------------------------------------------------------- | - |
| EX-15 | [ex-15-malformed-ein.php](./ex-15-malformed-ein.php)                       | Every malformed shape rejected locally, with an instrumented HTTP client proving no request was sent. |
| EX-16 | [ex-16-not-found.php](./ex-16-not-found.php)                               | A well-formed EIN with no record: `NotFoundException`, sanitized diagnostics, and why bulk behaves differently. |
| EX-22 | [ex-22-rate-limit.php](./ex-22-rate-limit.php)                             | HTTP 429, `Retry-After`, bounded retries and a client-side rate ceiling. |
| EX-23 | [ex-23-transient-retries.php](./ex-23-transient-retries.php)               | Transient 5xx and connection failures retried with jittered backoff; auth, validation and not-found never retried. |
| EX-24 | [ex-24-timeouts-and-budgets.php](./ex-24-timeouts-and-budgets.php)         | A per-attempt deadline against the wall clock a retried call can actually consume, and how to fit a caller-facing budget. |
| EX-25 | [ex-25-raw-and-forward-compat.php](./ex-25-raw-and-forward-compat.php)     | A record from a newer API version: unknown fields and an unknown enum value, both readable, neither fatal. |

### Bulk

|       |                                                                                      | |
| ----- | ------------------------------------------------------------------------------------ | - |
| EX-17 | [ex-17-bulk-screening.php](./ex-17-bulk-screening.php)                               | Screening a grantee list, iterating organization-level results and reading the response envelope. |
| EX-18 | [ex-18-bulk-order-and-duplicates.php](./ex-18-bulk-order-and-duplicates.php)         | Response order does not follow request order, duplicates collapse in the response but still bill, and usage is read rather than inferred. |
| EX-19 | [ex-19-bulk-partial-success.php](./ex-19-bulk-partial-success.php)                   | Mixed outcomes on one HTTP 200: usable records, item-level errors, and a full input reconciliation. |
| EX-20 | [ex-20-bulk-batch-limits.php](./ex-20-bulk-batch-limits.php)                         | Empty and over-limit batches rejected against `Endpoints::MAX_BULK_EINS`, plus chunking a larger list yourself. |
| EX-21 | [ex-21-usage-tracking.php](./ex-21-usage-tracking.php)                               | `nonprofit_check_count` as a cumulative billing-cycle total that resets each cycle — never a per-request size. |

### End-to-end workflows

|       |                                                                                      | |
| ----- | ------------------------------------------------------------------------------------ | - |
| EX-26 | [ex-26-onboarding-workflow.php](./ex-26-onboarding-workflow.php)                     | An applicant checked end to end: validation, every source finding, one explicit policy, and the evidence stored. |
| EX-27 | [ex-27-daf-grant-screening.php](./ex-27-daf-grant-screening.php)                     | A DAF sponsor screening recommended grantees, routing private foundations to expenditure responsibility rather than rejecting them. |
| EX-28 | [ex-28-crm-enrichment.php](./ex-28-crm-enrichment.php)                               | Enriching stored records, writing back only what the API returned, and never overwriting good data with a field the API omitted. |
| EX-29 | [ex-29-pre-disbursement-recheck.php](./ex-29-pre-disbursement-recheck.php)           | Re-checking an approved grant before the money moves, and comparing findings rather than trusting the stored verdict. |
| EX-30 | [ex-30-portfolio-reverification.php](./ex-30-portfolio-reverification.php)           | A standing portfolio re-checked on a schedule: chunked bulk requests, findings compared against the last run, and a report of what moved. |

### Shorter reads

Four files sit alongside the numbered set for a first look:

| | |
| - | - |
| [quickstart.php](./quickstart.php)         | Check one EIN and read the result. |
| [bulk.php](./bulk.php)                     | Check several in one request, and account for the ones with no record. |
| [error-handling.php](./error-handling.php) | The error taxonomy, one branch at a time. |
| [psr18-client.php](./psr18-client.php)     | Sending through your own PSR-18 client instead of the bundled cURL transport. |

## A note on `null` versus absent

The API omits a field it has no data for, and returns `null` for a field it has
looked at and found empty. Those route differently — "not screened" is not "no
match" — so the SDK keeps them apart, and so do these examples:

```php
$nonprofit->has('ofac_status');   // did the API return this field at all?
$nonprofit->get('ofac_status');   // its value, which may legitimately be null
```

`Output::display()` in [`lib/Output.php`](./lib/Output.php) prints `<not returned>`
for the first case, so you can see the difference in the terminal output rather
than having to infer it.
