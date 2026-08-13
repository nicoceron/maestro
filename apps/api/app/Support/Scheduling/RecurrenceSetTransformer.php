<?php

namespace App\Support\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\LocalTimeResolution;
use App\Models\EventSeries;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class RecurrenceSetTransformer
{
    public function __construct(private readonly RecurrenceEngine $recurrence) {}

    /**
     * Translate an inherited clone recurrence set by the wall-clock delta between DTSTART values.
     * Explicit clone RRULE input remains authoritative; inherited COUNT preserves its count while
     * inherited UNTIL moves with the cloned wall-clock schedule.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{rrule:?string,rdates:array<int, array<string, string>>,exdates:list<string>}
     */
    public function forClone(EventSeries $source, array $overrides): array
    {
        $newStart = (string) $overrides['dtstart_local'];
        $newTimezone = (string) ($overrides['timezone'] ?? $source->timezone);
        $resolution = LocalTimeResolution::from((string) ($overrides['dtstart_resolution'] ?? $source->dtstart_resolution->value));

        return [
            'rrule' => array_key_exists('rrule', $overrides)
                ? $this->recurrence->canonicalize($overrides['rrule'])
                : $this->translateUntil($source->rrule, $source->timezone, $source->dtstart_local, $newTimezone, $newStart, $resolution),
            'rdates' => $this->translateRdates($source->rdates ?? [], $source->dtstart_local, $newStart),
            'exdates' => $this->translateLocalList($source->exdates ?? [], $source->dtstart_local, $newStart),
        ];
    }

    /**
     * Partition an existing recurrence set at a stable local recurrence identity. The old set keeps
     * only pre-cutover explicit dates. The new set translates future explicit dates and preserves
     * RFC COUNT semantics by subtracting RRULE instances consumed before the cutover.
     *
     * @param  array<string, mixed>  $command
     * @return array{old_rdates:array<int, array<string, string>>,old_exdates:list<string>,new_rrule:?string,new_rdates:array<int, array<string, string>>,new_exdates:list<string>}
     */
    public function forFuture(EventSeries $source, string $cutoverLocal, array $command): array
    {
        $newStart = (string) ($command['dtstart_local'] ?? $command['starts_at_local'] ?? $cutoverLocal);
        $newTimezone = (string) ($command['timezone'] ?? $source->timezone);
        $resolution = LocalTimeResolution::from((string) ($command['dtstart_resolution'] ?? $command['start_resolution'] ?? $source->dtstart_resolution->value));
        $sourceRdates = $source->rdates ?? [];
        $sourceExdates = $source->exdates ?? [];
        $futureRdates = array_values(array_filter($sourceRdates, fn (array $item): bool => $item['local'] >= $cutoverLocal));
        $futureExdates = array_values(array_filter($sourceExdates, fn (string $item): bool => $item >= $cutoverLocal));
        $inheritedRule = $this->remainingRule($source, $cutoverLocal, $newTimezone, $newStart, $resolution);

        return [
            'old_rdates' => array_values(array_filter($sourceRdates, fn (array $item): bool => $item['local'] < $cutoverLocal)),
            'old_exdates' => array_values(array_filter($sourceExdates, fn (string $item): bool => $item < $cutoverLocal)),
            'new_rrule' => array_key_exists('rrule', $command)
                ? $this->recurrence->canonicalize($command['rrule'])
                : $inheritedRule,
            'new_rdates' => array_key_exists('rdates', $command)
                ? array_values($command['rdates'])
                : $this->translateRdates($futureRdates, $cutoverLocal, $newStart),
            'new_exdates' => array_key_exists('exdates', $command)
                ? array_values($command['exdates'])
                : $this->translateLocalList($futureExdates, $cutoverLocal, $newStart),
        ];
    }

    public function translateIdentity(string $value, string $oldAnchor, string $newAnchor): string
    {
        return $this->translateLocal($value, $oldAnchor, $newAnchor);
    }

    private function remainingRule(
        EventSeries $source,
        string $cutoverLocal,
        string $newTimezone,
        string $newStart,
        LocalTimeResolution $resolution,
    ): ?string {
        $rule = $this->recurrence->canonicalize($source->rrule);

        if ($rule === null) {
            return null;
        }

        $parts = $this->parts($rule);

        if (isset($parts['COUNT'])) {
            $sourceStart = ZonedLocalDateTime::resolve(
                $source->dtstart_local, $source->timezone, $source->dtstart_resolution, 'dtstart_local',
            );
            $cutover = ZonedLocalDateTime::resolve(
                $cutoverLocal, $source->timezone, $source->dtstart_resolution, 'dtstart_local',
            );
            $consumed = count($this->recurrence->expand(
                $source->dtstart_local,
                $source->timezone,
                $rule,
                $sourceStart->subSecond(),
                $cutover->subSecond(),
                5000,
                $source->dtstart_resolution,
            ));
            $remaining = (int) $parts['COUNT'] - $consumed;

            if ($remaining < 1) {
                throw ValidationException::withMessages([
                    'rrule' => 'The original COUNT recurrence has no RRULE instances remaining at this cutover.',
                ]);
            }

            $parts['COUNT'] = (string) $remaining;
        }

        $remaining = $this->recurrence->canonicalize($this->serialize($parts));

        return $this->translateUntil(
            $remaining,
            $source->timezone,
            $cutoverLocal,
            $newTimezone,
            $newStart,
            $resolution,
        );
    }

    private function translateUntil(
        ?string $rule,
        string $oldTimezone,
        string $oldAnchor,
        string $newTimezone,
        string $newAnchor,
        LocalTimeResolution $resolution,
    ): ?string {
        $rule = $this->recurrence->canonicalize($rule);

        if ($rule === null) {
            return null;
        }

        $parts = $this->parts($rule);

        if (! isset($parts['UNTIL'])) {
            return $rule;
        }

        $until = CarbonImmutable::createFromFormat('Ymd\THis\Z', $parts['UNTIL'], 'UTC');

        if (! $until instanceof CarbonImmutable) {
            throw ValidationException::withMessages(['rrule' => 'The recurrence UNTIL value is invalid.']);
        }

        $oldUntilLocal = $until->setTimezone($oldTimezone)->format('Y-m-d\TH:i:s');
        $newUntilLocal = $this->translateLocal($oldUntilLocal, $oldAnchor, $newAnchor);
        $parts['UNTIL'] = ZonedLocalDateTime::resolve(
            $newUntilLocal, $newTimezone, $resolution, 'rrule',
        )->utc()->format('Ymd\THis\Z');

        return $this->recurrence->canonicalize($this->serialize($parts));
    }

    /** @param array<int, array<string, string>> $rdates @return array<int, array<string, string>> */
    private function translateRdates(array $rdates, string $oldAnchor, string $newAnchor): array
    {
        return array_values(array_map(fn (array $item): array => [
            ...$item,
            'local' => $this->translateLocal($item['local'], $oldAnchor, $newAnchor),
        ], $rdates));
    }

    /** @param list<string> $values @return list<string> */
    private function translateLocalList(array $values, string $oldAnchor, string $newAnchor): array
    {
        return array_values(array_map(fn (string $value): string => $this->translateLocal($value, $oldAnchor, $newAnchor), $values));
    }

    private function translateLocal(string $value, string $oldAnchor, string $newAnchor): string
    {
        $old = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $oldAnchor, 'UTC');
        $new = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $newAnchor, 'UTC');
        $local = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $value, 'UTC');

        if (! $old instanceof CarbonImmutable || ! $new instanceof CarbonImmutable || ! $local instanceof CarbonImmutable) {
            throw ValidationException::withMessages(['rrule' => 'Recurrence local identities must use Y-m-d\\TH:i:s.']);
        }

        return $local->addSeconds($new->getTimestamp() - $old->getTimestamp())->format('Y-m-d\TH:i:s');
    }

    /** @return array<string, string> */
    private function parts(string $rule): array
    {
        $parts = [];

        foreach (explode(';', $rule) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        return $parts;
    }

    /** @param array<string, string> $parts */
    private function serialize(array $parts): string
    {
        return implode(';', array_map(fn (string $key, string $value): string => $key.'='.$value, array_keys($parts), $parts));
    }
}
