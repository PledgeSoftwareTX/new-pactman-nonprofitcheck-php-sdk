<?php

declare(strict_types=1);

namespace Pactman\NonprofitCheckPlus\Tests\Support;

use Pactman\NonprofitCheckPlus\Http\TransportHooks;

/** Records requested delays instead of waiting, so retry tests stay instant. */
final class Clock
{
    /** @var list<float> Every delay the transport asked for, in order. */
    public array $delays = [];

    private float $now = 0.0;

    public function __construct(public float $randomValue = 1.0)
    {
    }

    public function hooks(): TransportHooks
    {
        return new TransportHooks(
            sleep: function (float $seconds): void {
                $this->delays[] = $seconds;
                $this->now += $seconds;
            },
            random: fn (): float => $this->randomValue,
            monotonic: fn (): float => $this->now,
        );
    }

    /** Total time the transport believes has passed. */
    public function elapsed(): float
    {
        return $this->now;
    }
}
