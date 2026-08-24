<?php

/**
 * EX-04 — Applicant name comparison.
 *
 * Compares a submitted name with `organization_name` and
 * `organization_name_aka` without treating punctuation or abbreviation
 * differences as fraud.
 *
 * Run:  PACTMAN_API_KEY=... php examples/ex-04-name-comparison.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\Fixtures;
use Pactman\NonprofitCheckPlus\Examples\ExampleContext;
use Pactman\NonprofitCheckPlus\Examples\Output;

$context = ExampleContext::live();

// The SDK deliberately has no namesMatch(). What counts as a match is policy,
// so the comparison lives in customer code where you can tune and audit it.
$normalize = static function (mixed $name): string {
    $text = strtoupper(is_string($name) ? $name : '');
    $text = (string) preg_replace('/\b(INC|INCORPORATED|CORP|CO|LLC|LTD|THE)\b\.?/', '', $text);
    $text = (string) preg_replace('/[^A-Z0-9 ]/', ' ', $text);

    return trim((string) preg_replace('/\s+/', ' ', $text));
};

$applicant = [
    'ein' => Fixtures::EINS['publicCharity'],
    'legal_name' => 'Meals Today Example Nonprofit, Inc.',
];

$nonprofit = $context->client->nonprofits->check($applicant['ein'])->nonprofit;

$candidates = array_values(array_filter(
    [$nonprofit?->get('organization_name'), $nonprofit?->get('organization_name_aka')],
    'is_string',
));

Output::heading('What the applicant said, and what the record says');
Output::field('applicant legal_name', $applicant['legal_name']);
Output::field('organization_name', $nonprofit?->get('organization_name'));
Output::field('organization_name_aka', $nonprofit?->get('organization_name_aka'));

Output::heading('Compared on normalized forms');
Output::field('applicant normalized', $normalize($applicant['legal_name']));

$matched = false;

foreach ($candidates as $candidate) {
    $isMatch = $normalize($candidate) === $normalize($applicant['legal_name']);
    $matched = $matched || $isMatch;

    Output::field($normalize($candidate), $isMatch ? 'agrees' : 'differs');
}

$outcome = match (true) {
    // No name came back, so nothing was compared. That is not agreement.
    $candidates === [] => 'not_returned',
    $matched => 'agreement',
    default => 'mismatch',
};

Output::heading('Outcome');
Output::field('outcome', $outcome);
Output::field('routed to', $outcome === 'agreement' ? 'continue' : 'manual_review');

Output::note(
    "A mismatch is a reason to look, not a finding: organizations rebrand, file under\n"
    . 'a parent, and appear in IRS data under a name no donor would recognize.',
);

$context->close();
