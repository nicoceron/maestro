<?php

namespace App\Support\Attendance;

use Illuminate\Validation\ValidationException;

final class AttendancePayload
{
    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function normalize(array $attributes, array $policySnapshot = []): array
    {
        $outcome = $attributes['outcome'];
        $makeupPolicy = $policySnapshot['makeup_policy'] ?? 'none';
        $policyMakeup = $makeupPolicy === 'none' ? 'waived' : 'required';
        [$derivedBilling, $derivedMakeup] = match ($outcome) {
            'present', 'late' => ['bill', 'none'],
            'absent_excused' => ['pending_review', $policyMakeup],
            'absent_unexcused', 'no_show' => ['bill', 'none'],
            'teacher_cancelled' => ['pending_review', $policyMakeup],
        };
        $billing = $derivedBilling;
        $makeup = $derivedMakeup;

        if (in_array($outcome, ['present', 'late'], true)
            && ($billing !== 'bill' || $makeup !== 'none')) {
            throw ValidationException::withMessages([
                'billing_disposition' => 'Present and late attendance must be billed normally.',
                'makeup_disposition' => 'Present and late attendance cannot create makeup work.',
            ]);
        }

        if ($outcome === 'teacher_cancelled'
            && (! in_array($billing, ['no_charge', 'credit', 'pending_review'], true)
                || ! in_array($makeup, ['required', 'waived'], true))) {
            throw ValidationException::withMessages([
                'billing_disposition' => 'Teacher cancellations must be no-charge or credited.',
                'makeup_disposition' => 'Teacher cancellations require an explicit makeup decision.',
            ]);
        }

        $minutesLate = (int) ($attributes['minutes_late'] ?? 0);
        if (($outcome === 'late' && $minutesLate < 1) || ($outcome !== 'late' && $minutesLate !== 0)) {
            throw ValidationException::withMessages([
                'minutes_late' => 'Minutes late is required only for a late outcome.',
            ]);
        }

        return [
            ...$attributes,
            'billing_disposition' => $billing,
            'makeup_disposition' => $makeup,
            'minutes_late' => $minutesLate,
            'reason' => isset($attributes['reason']) ? trim((string) $attributes['reason']) ?: null : null,
        ];
    }
}
