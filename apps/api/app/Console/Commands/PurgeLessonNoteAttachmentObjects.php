<?php

namespace App\Console\Commands;

use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentPurge;
use App\Models\LessonNoteAttachmentPurgeClaim;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PurgeLessonNoteAttachmentObjects extends Command
{
    protected $signature = 'attachments:purge {--studio= : Limit cleanup to one studio ULID}';

    protected $description = 'Purge expired private lesson-note attachment objects while retaining immutable audit metadata';

    public function handle(RequestDatabaseContext $context): int
    {
        $studios = Studio::query()->orderBy('id');
        if (is_string($this->option('studio')) && $this->option('studio') !== '') {
            $studios->whereKey($this->option('studio'));
        }

        $purged = 0;
        $errors = 0;
        $studios->pluck('id')->each(function (string $studioId) use ($context, &$purged, &$errors): void {
            try {
                $context->activateStudioIdForSession($studioId);
                LessonNoteAttachment::query()
                    ->where('studio_id', $studioId)
                    ->orderBy('id')
                    ->chunkById(
                        min(1000, max(1, (int) config('lesson-notes.attachments.purge_batch_size'))),
                        function ($attachments) use (&$purged, &$errors): void {
                            foreach ($attachments as $attachment) {
                                try {
                                    $purged += $this->purgeAttachment((string) $attachment->getKey());
                                } catch (Throwable $exception) {
                                    $errors++;
                                    $this->components->error(
                                        "Attachment {$attachment->getKey()} cleanup failed: {$exception->getMessage()}",
                                    );
                                }
                            }
                        },
                        'id',
                    );
            } catch (Throwable $exception) {
                $errors++;
                $this->components->error("Studio {$studioId} cleanup failed: {$exception->getMessage()}");
            } finally {
                $context->clearStudio();
            }
        });

        $this->info("Purged {$purged} lesson-note attachment object(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function purgeAttachment(string $attachmentId): int
    {
        $objects = DB::transaction(function () use ($attachmentId): array {
            $attachment = LessonNoteAttachment::query()->lockForUpdate()->findOrFail($attachmentId);
            $attachment->load(['latestScan', 'retirement']);

            return $this->eligibleObjects($attachment);
        }, attempts: 3);

        $purged = 0;
        foreach ($objects as $object) {
            if ($this->purgeObject($attachmentId, $object)) {
                $purged++;
            }
        }

        return $purged;
    }

    /** @return list<array{kind: string, disk: string, key: string, reason: string}> */
    private function eligibleObjects(LessonNoteAttachment $attachment): array
    {
        $objects = [];
        $quarantineCutoff = now()->subHours((int) config('lesson-notes.attachments.quarantine_retention_hours'));
        $pendingCutoff = now()->subHours((int) config('lesson-notes.attachments.pending_retention_hours'));
        $retiredCutoff = now()->subHours((int) config('lesson-notes.attachments.retired_retention_hours'));
        $retired = $attachment->retirement?->retired_at?->lessThanOrEqualTo($retiredCutoff) ?? false;
        $scanExpired = $attachment->latestScan?->scanned_at?->lessThanOrEqualTo($quarantineCutoff) ?? false;
        $pendingExpired = $attachment->latestScan === null
            && $attachment->created_at->lessThanOrEqualTo($pendingCutoff);

        if ($retired || $scanExpired || $pendingExpired) {
            $objects[] = [
                'kind' => 'quarantine',
                'disk' => $attachment->quarantine_disk,
                'key' => $attachment->quarantine_key,
                'reason' => $retired
                    ? 'retired_retention_elapsed'
                    : ($pendingExpired ? 'pending_retention_elapsed' : 'quarantine_retention_elapsed'),
            ];
        }

        if ($retired) {
            $cleanScans = $attachment->scans()->where('status', 'clean')
                ->whereNotNull('clean_disk')->whereNotNull('clean_key')->get();
            foreach ($cleanScans as $scan) {
                $objects[] = [
                    'kind' => 'clean',
                    'disk' => $scan->clean_disk,
                    'key' => $scan->clean_key,
                    'reason' => 'retired_retention_elapsed',
                ];
            }
        }

        return $objects;
    }

    /** @param array{kind: string, disk: string, key: string, reason: string} $object */
    private function purgeObject(string $attachmentId, array $object): bool
    {
        $claim = $this->claimObject($attachmentId, $object);
        if ($claim === null) {
            return false;
        }

        try {
            $disk = Storage::disk($object['disk']);
            if ($disk->exists($object['key']) && ! $disk->delete($object['key'])) {
                throw new \RuntimeException('The private attachment object could not be deleted.');
            }
        } catch (Throwable $exception) {
            DB::transaction(function () use ($claim, $exception): void {
                $locked = LessonNoteAttachmentPurgeClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
                $locked->status = 'failed';
                $locked->failure_code = $exception instanceof \InvalidArgumentException
                    ? 'disk_not_configured'
                    : 'storage_delete_failed';
                $locked->lease_expires_at = null;
                $locked->save();
            }, attempts: 3);

            throw $exception;
        }

        DB::transaction(function () use ($claim, $object): void {
            $locked = LessonNoteAttachmentPurgeClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            LessonNoteAttachmentPurge::query()->firstOrCreate([
                'studio_id' => $locked->studio_id,
                'lesson_note_attachment_id' => $locked->lesson_note_attachment_id,
                'object_kind' => $object['kind'],
                'disk' => $object['disk'],
                'object_key' => $object['key'],
            ], [
                'reason' => $object['reason'],
                'purged_at' => now(),
            ]);
            $locked->status = 'completed';
            $locked->failure_code = null;
            $locked->lease_expires_at = null;
            $locked->completed_at = now();
            $locked->save();
        }, attempts: 3);

        return true;
    }

    /**
     * @param  array{kind: string, disk: string, key: string, reason: string}  $object
     */
    private function claimObject(string $attachmentId, array $object): ?LessonNoteAttachmentPurgeClaim
    {
        return DB::transaction(function () use ($attachmentId, $object): ?LessonNoteAttachmentPurgeClaim {
            $attachment = LessonNoteAttachment::query()->lockForUpdate()->findOrFail($attachmentId);
            $attachment->load(['latestScan', 'retirement']);
            $stillEligible = collect($this->eligibleObjects($attachment))->contains(
                fn (array $candidate): bool => $candidate === $object,
            );
            if (! $stillEligible) {
                return null;
            }

            $claim = LessonNoteAttachmentPurgeClaim::query()->firstOrNew([
                'studio_id' => $attachment->studio_id,
                'lesson_note_attachment_id' => $attachment->getKey(),
                'object_kind' => $object['kind'],
                'disk' => $object['disk'],
                'object_key' => $object['key'],
            ]);
            if ($claim->exists && ($claim->status === 'completed'
                || ($claim->status === 'claimed' && $claim->lease_expires_at?->isFuture()))) {
                return null;
            }

            if (! $claim->exists) {
                $claim->reason = $object['reason'];
            }
            $claim->status = 'claimed';
            $claim->failure_code = null;
            $claim->claimed_at = now();
            $claim->lease_expires_at = now()->addMinutes(15);
            $claim->completed_at = null;
            $claim->save();

            return $claim;
        }, attempts: 3);
    }
}
