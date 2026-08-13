<?php

namespace App\Actions\Attendance;

use App\Contracts\Attachments\MalwareScanner;
use App\Enums\LessonNoteAttachmentScanStatus;
use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentScan;
use App\Support\Attachments\MalwareScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ScanLessonNoteAttachment
{
    public function __construct(private readonly MalwareScanner $scanner) {}

    public function handle(LessonNoteAttachment $attachment): LessonNoteAttachmentScan
    {
        $attachment->loadMissing(['latestScan', 'retirement']);
        if ($attachment->latestScan !== null
            && in_array($attachment->latestScan->status, [LessonNoteAttachmentScanStatus::Clean, LessonNoteAttachmentScanStatus::Infected], true)) {
            return $attachment->latestScan;
        }
        if ($attachment->retirement !== null) {
            abort(409, 'Retired attachments cannot be scanned.');
        }

        $disk = Storage::disk($attachment->quarantine_disk);
        try {
            $stream = $disk->readStream($attachment->quarantine_key);
        } catch (Throwable) {
            $stream = false;
        }
        if (! is_resource($stream)) {
            $result = MalwareScanResult::failed('storage', 'source_missing');
        } else {
            try {
                $result = $this->scanner->scan($stream);
            } catch (Throwable) {
                $result = MalwareScanResult::failed('scanner', 'scanner_exception');
            } finally {
                fclose($stream);
            }
        }

        $cleanKey = null;
        if ($result->status === LessonNoteAttachmentScanStatus::Clean) {
            $cleanKey = "clean/{$attachment->studio_id}/{$attachment->lesson_note_id}/".
                bin2hex(random_bytes(20)).".{$attachment->extension}";
            try {
                if (! $disk->copy($attachment->quarantine_key, $cleanKey)) {
                    $result = MalwareScanResult::failed('storage', 'promotion_failed');
                    $cleanKey = null;
                }
            } catch (Throwable) {
                $result = MalwareScanResult::failed('storage', 'promotion_failed');
                $cleanKey = null;
            }
        }

        try {
            $scan = DB::transaction(function () use ($attachment, $result, $cleanKey): LessonNoteAttachmentScan {
                $locked = LessonNoteAttachment::query()->where('studio_id', $attachment->studio_id)
                    ->lockForUpdate()->findOrFail($attachment->getKey());
                $locked->load(['latestScan', 'retirement']);
                if ($locked->latestScan !== null
                    && in_array($locked->latestScan->status, [LessonNoteAttachmentScanStatus::Clean, LessonNoteAttachmentScanStatus::Infected], true)) {
                    return $locked->latestScan;
                }
                if ($locked->retirement !== null) {
                    abort(409, 'Retired attachments cannot be scanned.');
                }

                $attempt = ((int) $locked->scans()->max('attempt')) + 1;

                return LessonNoteAttachmentScan::query()->create([
                    'studio_id' => $locked->studio_id,
                    'lesson_note_attachment_id' => $locked->getKey(),
                    'attempt' => $attempt,
                    'status' => $result->status,
                    'engine' => $result->engine,
                    'engine_version' => $result->engineVersion,
                    'detail_code' => $result->detailCode,
                    'clean_disk' => $result->status === LessonNoteAttachmentScanStatus::Clean ? $locked->quarantine_disk : null,
                    'clean_key' => $result->status === LessonNoteAttachmentScanStatus::Clean ? $cleanKey : null,
                    'scanned_at' => now(),
                ]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($cleanKey !== null) {
                $disk->delete($cleanKey);
            }

            throw $exception;
        }

        if ($cleanKey !== null && $scan->clean_key !== $cleanKey) {
            $disk->delete($cleanKey);
        }

        return $scan;
    }
}
