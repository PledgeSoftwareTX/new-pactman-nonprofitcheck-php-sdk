<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Dev;

/**
 * Signatures of a JSON response, and the differences between two of them.
 *
 * A recorded copy of a live response is worthless as a drift detector: every run
 * returns a fresh `report_date`, a different `timeTaken` and a usage counter that
 * only goes up, so a byte comparison fails for reasons that have nothing to do
 * with the API changing. What is stable is the shape — which fields exist, what
 * type each carries, and what form its values take. That is what a signature
 * captures, and comparing one against a recorded baseline is how
 * `smoke-live.php` answers "has the API changed?" without re-recording every
 * time the IRS data behind an organization is refreshed.
 *
 * A signature is a flat, sorted map of path to type token:
 *
 *     [
 *       'code' => 'number',
 *       'data.ein' => 'digits:9',
 *       'data.most_recent_bmf' => 'date',
 *       'data.organization_types[].deductibility_limitation' => 'text',
 *       'data.pub78_verified' => 'boolean',
 *       'data.revocation_code' => 'null',
 *       'errors' => 'null',
 *     ]
 *
 * Flat, so a field that appears, disappears or changes type is one line in a git
 * diff, and so comparing two signatures is a key-by-key walk rather than a
 * recursive descent that has to re-derive structure it already knows.
 *
 * The two halves are compared separately — {@see schemaDiff()} over the paths,
 * {@see typeDiff()} over the tokens — because they fail for different reasons
 * and mean different things. A field that disappeared breaks callers that read
 * it; a field that changed type breaks callers that parse it. Reporting them as
 * one number would say only that something moved.
 *
 * Tokens:
 *   object, array, boolean, number, null   the JSON type, structural
 *   date          `M/D/YYYY h:mm:ss AM` — the format every API timestamp uses
 *   date:iso      an ISO-8601 timestamp, which this API does not currently send
 *   digits:9      a string of digits, grouped by length: "411787097" is
 *                 digits:9, "01085-2643" is digits:5-4, "00" is digits:2
 *   url           an http(s) URL
 *   ofac-sentence the SDN sentence `ofac_status` carries, in either wording
 *   empty         an empty or whitespace-only string
 *   text          any other string
 *
 * A path that carries more than one token across a single response — a field
 * that is a date on one organization in a bulk batch and null on another —
 * records them sorted and joined by "|", as in `date|null`.
 *
 * Only shapes go in. No value from the response is ever recorded, so a baseline
 * is safe to commit and a diff is safe to print.
 */
final class Contract
{
    /** The format every timestamp in this API uses. See {@see Fixtures::apiDate()}. */
    private const API_DATE = '/^\d{1,2}\/\d{1,2}\/\d{4},? \d{1,2}:\d{2}:\d{2} ?(?:AM|PM)$/i';

    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}|$)/';

    /** Digits, optionally in hyphen-separated groups: EINs, ZIPs, IRS codes. */
    private const DIGIT_GROUPS = '/^\d+(?:-\d+)*$/';

    private const URL_LIKE = '/^https?:\/\//i';

    /**
     * The clause both OFAC wordings share.
     *
     * Matching the clause rather than either whole sentence keeps a genuine
     * change of wording visible — it would fall back to `text` — while a subject
     * that goes from "was NOT included" to "may be included", or a possible
     * match whose UID differs, stays the same shape. That is a change in the
     * data, not the contract.
     */
    private const OFAC_SENTENCE = '/Specially Designated Nationals ?\(SDN\) list/i';

    /** Classifies a string by the form of its value, never by the value itself. */
    public static function formatOf(string $value): string
    {
        // Newer runtimes format times with a narrow no-break space; the API sends
        // a plain one. Normalize so the same timestamp is not two different tokens.
        $text = str_replace(["\u{202F}", "\u{00A0}"], ' ', $value);

        return match (true) {
            trim($text) === '' => 'empty',
            preg_match(self::API_DATE, $text) === 1 => 'date',
            preg_match(self::ISO_DATE, $text) === 1 => 'date:iso',
            preg_match(self::DIGIT_GROUPS, $text) === 1 => 'digits:' . implode('-', array_map(
                strlen(...),
                explode('-', $text),
            )),
            preg_match(self::URL_LIKE, $text) === 1 => 'url',
            preg_match(self::OFAC_SENTENCE, $text) === 1 => 'ofac-sentence',
            default => 'text',
        };
    }

    /**
     * Builds the signature of a decoded JSON response.
     *
     * @return array<string, string>
     */
    public static function signatureOf(mixed $value): array
    {
        /** @var array<string, array<string, true>> $tokens */
        $tokens = [];
        self::collect($value, '', $tokens);

        ksort($tokens);

        $signature = [];

        foreach ($tokens as $path => $set) {
            $names = array_keys($set);
            sort($names);
            $signature[$path] = implode('|', $names);
        }

        return $signature;
    }

    /**
     * Fields the API stopped sending, and fields it started sending.
     *
     * Additions count. A field the API added is forward-compatible for a caller —
     * the SDK surfaces it through `get()` either way — but it is still the API
     * changing, and a baseline that quietly absorbs additions cannot tell you
     * when it did.
     *
     * @param array<string, string> $baseline
     * @param array<string, string> $current
     *
     * @return list<array{kind: string, path: string, token?: string, from?: string, to?: string}>
     */
    public static function schemaDiff(array $baseline, array $current): array
    {
        $changes = [];

        foreach ($baseline as $path => $token) {
            if (!array_key_exists($path, $current)) {
                $changes[] = ['kind' => 'removed', 'path' => $path, 'token' => $token];
            }
        }

        foreach ($current as $path => $token) {
            if (!array_key_exists($path, $baseline)) {
                $changes[] = ['kind' => 'added', 'path' => $path, 'token' => $token];
            }
        }

        return self::sortChanges($changes);
    }

    /**
     * Fields whose type or value format changed, across the paths both
     * signatures have.
     *
     * Paths only one of them has are {@see schemaDiff()}'s to report, so a single
     * renamed field is one failure rather than two.
     *
     * @param array<string, string> $baseline
     * @param array<string, string> $current
     *
     * @return list<array{kind: string, path: string, token?: string, from?: string, to?: string}>
     */
    public static function typeDiff(array $baseline, array $current): array
    {
        $changes = [];

        foreach ($baseline as $path => $token) {
            if (array_key_exists($path, $current) && $current[$path] !== $token) {
                $changes[] = ['kind' => 'changed', 'path' => $path, 'from' => $token, 'to' => $current[$path]];
            }
        }

        return self::sortChanges($changes);
    }

    /**
     * True when an observed token is permitted by a declared shape.
     *
     * `src/response-contract.json` declares each field as the tokens it may
     * carry, joined by "|" — `"digits:9|null"`, `"boolean|null"`. It also uses
     * `string` as a wildcard over every string form, for the fields where the
     * SDK promises "some text" rather than a particular shape. That is the one
     * looseness in the vocabulary, and it is deliberate: declaring
     * `organization_name` as `text` would fail the day an organization's name is
     * all digits.
     */
    public static function satisfies(string $declared, string $observed): bool
    {
        foreach (explode('|', $observed) as $token) {
            if (!self::permits($declared, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one observed token is one a declared shape allows.
     *
     * {@see satisfies()} asks the same question of a whole observed token string;
     * this is the single-token form, which is what {@see contractDiff()} needs to
     * name the tokens that offend rather than only that one did.
     */
    public static function permits(string $allowed, string $token): bool
    {
        $permitted = explode('|', $allowed);

        return \in_array($token, $permitted, true)
            || (\in_array('string', $permitted, true) && self::isStringToken($token));
    }

    /** True for a token that describes the form of a string value. */
    private static function isStringToken(string $token): bool
    {
        return in_array($token, ['text', 'empty', 'date', 'date:iso', 'url', 'ofac-sentence'], true)
            || str_starts_with($token, 'digits:');
    }

    /** @param array{kind: string, path: string, token?: string, from?: string, to?: string} $change */
    public static function describe(array $change): string
    {
        return match ($change['kind']) {
            'removed' => sprintf('- %s was %s', $change['path'], $change['token'] ?? '?'),
            'added' => sprintf('+ %s is %s', $change['path'], $change['token'] ?? '?'),
            default => sprintf('~ %s %s → %s', $change['path'], $change['from'] ?? '?', $change['to'] ?? '?'),
        };
    }

    private static function tokenFor(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            is_string($value) => self::formatOf($value),
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            default => get_debug_type($value),
        };
    }

    /** @param array<string, array<string, true>> $tokens */
    private static function collect(mixed $value, string $path, array &$tokens): void
    {
        if ($path !== '') {
            $tokens[$path][self::tokenFor($value)] = true;
        }

        if (!is_array($value)) {
            return;
        }

        // Every element of a list contributes to one path, so a batch of ten
        // organizations describes one record shape rather than ten.
        if (array_is_list($value)) {
            foreach ($value as $item) {
                self::collect($item, "{$path}[]", $tokens);
            }

            return;
        }

        foreach ($value as $key => $child) {
            self::collect($child, $path === '' ? (string) $key : "{$path}.{$key}", $tokens);
        }
    }

    /**
     * The recording held against a live response, with the differences a
     * recording cannot speak to left out.
     *
     * A baseline is one organization's response on one afternoon, so much of
     * what separates it from today's run is not the API moving — it is a
     * different subject, or the same subject whose Pub 78 row lapsed since. Two
     * kinds of difference fall out of that, and neither is drift.
     *
     * Nullability. `pub78_city` was `text` when the recording was made and is
     * `null` now. The field is still there and still declared nullable; this
     * organization simply has no Pub 78 city. Only a move between two forms a
     * value actually took — `digits:9` to `text` — says the API changed.
     *
     * Reachability. `organization_types` arrived null, so the paths beneath it
     * had nowhere to be and read as removed. {@see coverageDiff()} already
     * excuses that against the contract; a recording needs it in both
     * directions, because which side has the populated parent is an accident of
     * which ran first.
     *
     * Both are counted rather than dropped, so a green run still says how much
     * it passed over. What the recording cannot answer, the contract checks do:
     * a field that must not be null is declared that way in
     * `src/response-contract.json`, and {@see contractDiff()} fails on it there.
     *
     * @param array<string, string> $before
     * @param array<string, string> $current
     *
     * @return array{changes: list<array{kind: string, path: string, token?: string, from?: string, to?: string}>, nullable: int, unreachable: int}
     */
    public static function baselineDiff(array $before, array $current): array
    {
        $changes = [];
        $nullable = 0;
        $unreachable = 0;

        foreach ($before as $path => $token) {
            if (array_key_exists($path, $current)) {
                if ($current[$path] === $token) {
                    continue;
                }

                if (self::nullabilityOnly($token, $current[$path])) {
                    ++$nullable;

                    continue;
                }

                $changes[] = ['kind' => 'changed', 'path' => $path, 'from' => $token, 'to' => $current[$path]];

                continue;
            }

            if (self::unreachableIn($path, $current)) {
                ++$unreachable;

                continue;
            }

            $changes[] = ['kind' => 'removed', 'path' => $path, 'token' => $token];
        }

        foreach ($current as $path => $token) {
            if (array_key_exists($path, $before)) {
                continue;
            }

            if (self::unreachableIn($path, $before)) {
                ++$unreachable;

                continue;
            }

            $changes[] = ['kind' => 'added', 'path' => $path, 'token' => $token];
        }

        return [
            'changes' => self::sortChanges($changes),
            'nullable' => $nullable,
            'unreachable' => $unreachable,
        ];
    }

    /**
     * Whether two tokens differ only over whether a value arrived.
     *
     * Drop `null` from both sides and compare what is left. `date` against
     * `date|null` leaves the same form on each. `date` against `null` leaves one
     * side with nothing, and a side that recorded no form makes no claim about
     * the form — so there is nothing there to have moved. `digits:9` against
     * `text` leaves two different forms, which is drift and stays reported.
     */
    private static function nullabilityOnly(string $before, string $after): bool
    {
        $left = self::withoutNull($before);
        $right = self::withoutNull($after);

        return $left === [] || $right === [] || $left === $right;
    }

    /**
     * A token's forms, in the order signatures store them, with `null` dropped.
     *
     * @return list<string>
     */
    private static function withoutNull(string $token): array
    {
        return array_values(
            array_filter(explode('|', $token), static fn (string $one): bool => $one !== 'null'),
        );
    }

    /**
     * Removals first: a field that disappeared breaks callers that read it.
     *
     * @param list<array{kind: string, path: string, token?: string, from?: string, to?: string}> $changes
     *
     * @return list<array{kind: string, path: string, token?: string, from?: string, to?: string}>
     */
    private static function sortChanges(array $changes): array
    {
        $order = ['removed' => 0, 'changed' => 1, 'added' => 2];

        usort($changes, static function (array $left, array $right) use ($order): int {
            return [$order[$left['kind']] ?? 3, $left['path']]
                <=> [$order[$right['kind']] ?? 3, $right['path']];
        });

        return $changes;
    }

    // --- the package's own prediction ----------------------------------------
    //
    // Everything above compares one live response against another recorded
    // earlier, which answers "did the API move?" but never "does the API still
    // match what this package tells its users?". The second question is the one
    // with a caller on the other end of it: `Nonprofit`'s `@property-read` list
    // promises `bmf_status` is a bool, and a user writes `if ($np->bmf_status)`
    // on the strength of that promise. Nothing in a self-recorded baseline can
    // notice when the API disagrees, because the baseline is the API's own
    // output — it agrees with itself by construction.
    //
    // `src/response-contract.json` is the other side of that comparison: the
    // shape this package predicts, in the same token vocabulary as a signature
    // so the two can be held against each other directly. It is checked in,
    // identical for everyone, and derived from the documented model rather than
    // from anyone's account — so a diff to it is a deliberate change to what the
    // SDK promises, reviewable as such, rather than a record of what one
    // organization looked like on one afternoon.

    /**
     * The flat expected signature for one endpoint, built from the shared parts.
     *
     * The record is described once and used for both endpoints, so single and
     * bulk cannot drift apart in the contract the way they can on the wire —
     * where `bmf_status` arrives as a string from one and a bool from the other.
     * One description means one of those two has to be reported as wrong.
     *
     * @param array<string, mixed> $contract
     *
     * @return array<string, string>
     */
    public static function composeExpected(array $contract, string $kind): array
    {
        $single = $kind === 'single';
        $prefix = $single ? 'data.' : 'data[].';

        /** @var array<string, string> $expected */
        $expected = $contract['envelope'];
        $expected['data'] = $single ? 'null|object' : 'array|null';
        $expected['errors[]'] = 'object';
        $expected['errors[].eins[]'] = 'string';

        /** @var array<string, string> $errorDetail */
        $errorDetail = $contract['errorDetail'];

        foreach ($errorDetail as $field => $token) {
            $expected["errors[].{$field}"] = $token;
        }

        if (!$single) {
            $expected['data[]'] = 'object';
        }

        /** @var array<string, string> $nonprofit */
        $nonprofit = $contract['nonprofit'];

        foreach ($nonprofit as $field => $token) {
            $expected["{$prefix}{$field}"] = $token;
        }

        // Nullable elements, not just a nullable array: the API sends a null in
        // the list where Publication 78 has a deductibility row it cannot
        // resolve, so a caller reading the first entry has to check. The
        // `@property-read` list on `Nonprofit` says the same thing.
        $expected["{$prefix}organization_types[]"] = 'null|object';

        /** @var array<string, string> $organizationType */
        $organizationType = $contract['organizationType'];

        foreach ($organizationType as $field => $token) {
            $expected["{$prefix}organization_types[].{$field}"] = $token;
        }

        ksort($expected, SORT_STRING);

        return $expected;
    }

    /**
     * Paths the live response carries a value the contract permits no form of.
     *
     * Paths the contract has never heard of are {@see coverageDiff()}'s to
     * report, so a field the API invented is one failure rather than two.
     *
     * @param array<string, string> $expected
     * @param array<string, string> $observed
     *
     * @return list<array{kind: string, path: string, token?: string, from?: string, to?: string}>
     */
    public static function contractDiff(array $expected, array $observed): array
    {
        $changes = [];

        foreach ($observed as $path => $token) {
            if (!array_key_exists($path, $expected)) {
                continue;
            }

            $allowed = $expected[$path];

            $offending = array_values(array_filter(
                explode('|', $token),
                static fn (string $one): bool => !self::permits($allowed, $one),
            ));

            if ($offending !== []) {
                $changes[] = [
                    'kind' => 'changed',
                    'path' => $path,
                    'from' => $allowed,
                    'to' => implode('|', $offending),
                ];
            }
        }

        return self::sortChanges($changes);
    }

    /**
     * Fields the API sent that the package does not predict, and fields it
     * predicts that the API did not send.
     *
     * Both directions fail. An unpredicted field is readable only by a caller who
     * already knows to look — `$nonprofit->get('field')` hides it from everyone
     * else — and a predicted field that stopped arriving breaks every caller that
     * reads it. The documented model notices neither, so this is the only place
     * either one is caught.
     *
     * The exception is a path that had nowhere to arrive: `errors[].reason` while
     * `errors` is null, `data.organization_types[].organization_type` while that
     * array is null or empty. The parent already accounts for the child's
     * absence, and every successful response has a null `errors` — reporting
     * those would fail every green run and say nothing. They are counted as
     * unreachable.
     *
     * A container that vanished is reported once, at its shallowest path: a
     * `data` that stopped arriving is one failure, not fifty-nine.
     *
     * @param array<string, string> $expected
     * @param array<string, string> $observed
     *
     * @return array{changes: list<array{kind: string, path: string, token?: string, from?: string, to?: string}>, unreachable: int}
     */
    public static function coverageDiff(array $expected, array $observed): array
    {
        $changes = [];

        foreach ($observed as $path => $token) {
            if (!array_key_exists($path, $expected)) {
                $changes[] = ['kind' => 'added', 'path' => $path, 'token' => $token];
            }
        }

        $missing = array_diff_key($expected, $observed);
        $unreachable = 0;

        foreach ($missing as $path => $token) {
            if (self::unreachableIn($path, $observed)) {
                ++$unreachable;

                continue;
            }

            foreach (self::ancestorsOf($path) as $ancestor) {
                if (array_key_exists($ancestor, $missing)) {
                    continue 2;
                }
            }

            $changes[] = ['kind' => 'removed', 'path' => $path, 'token' => $token];
        }

        return ['changes' => self::sortChanges($changes), 'unreachable' => $unreachable];
    }

    /**
     * Every enclosing path of a signature path, innermost first.
     *
     *   data.organization_types[].organization_type
     *     → data.organization_types[], data.organization_types, data
     *
     * @return list<string>
     */
    private static function ancestorsOf(string $path): array
    {
        $ancestors = [];
        $rest = $path;

        while (true) {
            if (str_ends_with($rest, '[]')) {
                $rest = substr($rest, 0, -2);
            } else {
                $dot = strrpos($rest, '.');

                if ($dot === false) {
                    return $ancestors;
                }

                $rest = substr($rest, 0, $dot);
            }

            $ancestors[] = $rest;
        }
    }

    /**
     * Whether a container above this path arrived in a form with no room for it.
     *
     * A null has no members and an empty array has no elements, so nothing under
     * either was ever going to appear.
     *
     * @param array<string, string> $observed
     */
    private static function unreachableIn(string $path, array $observed): bool
    {
        foreach (self::ancestorsOf($path) as $ancestor) {
            if (!array_key_exists($ancestor, $observed)) {
                continue;
            }

            $tokens = explode('|', $observed[$ancestor]);

            $nonNull = array_filter($tokens, static fn (string $one): bool => $one !== 'null');

            if ($nonNull === []) {
                return true;
            }

            if (\in_array('array', $tokens, true) && !array_key_exists("{$ancestor}[]", $observed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "2 removed, 1 added" — the counts that are not zero.
     *
     * @param list<array{kind: string, path: string, token?: string, from?: string, to?: string}> $changes
     */
    public static function summarizeChanges(array $changes): string
    {
        $counts = ['removed' => 0, 'changed' => 0, 'added' => 0];

        foreach ($changes as $change) {
            $counts[$change['kind']] = ($counts[$change['kind']] ?? 0) + 1;
        }

        $parts = [];

        foreach ($counts as $kind => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$kind}";
            }
        }

        return $parts === [] ? 'no differences' : implode(', ', $parts);
    }

    /**
     * One line per change, indented to sit under the line it explains.
     *
     * Every change, with nothing elided. A run that says a field moved and then
     * hides which one sends you back to the deployment to find out by hand, and
     * the list is only long when something large moved — which is exactly when
     * the whole of it is what you need.
     *
     * @param list<array{kind: string, path: string, token?: string, from?: string, to?: string}> $changes
     */
    public static function formatChanges(array $changes, string $indent = '      '): string
    {
        $lines = array_map(self::describe(...), $changes);

        return implode("\n", array_map(static fn (string $line): string => $indent . $line, $lines));
    }
}
