<?php

namespace App\Support\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class AttachmentTypePolicy
{
    /**
     * @return array{extension: string, original_name: string, declared_mime: ?string, detected_mime: string, size_bytes: int, sha256: string}
     */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The attachment upload did not complete successfully.']);
        }

        $size = $file->getSize();
        $minimum = (int) config('lesson-notes.attachments.minimum_bytes');
        $maximum = (int) config('lesson-notes.attachments.maximum_bytes');
        if (! is_int($size) || $minimum < 1 || $maximum < $minimum || $size < $minimum || $size > $maximum) {
            throw ValidationException::withMessages([
                'file' => "The attachment must be between {$minimum} and {$maximum} bytes.",
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = config('lesson-notes.attachments.allowed_types', []);
        if (! is_array($allowed) || ! array_key_exists($extension, $allowed)) {
            throw ValidationException::withMessages(['file' => 'The attachment extension is not allowed.']);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file->getPathname());
        if (! is_string($detected) || ! in_array($detected, (array) $allowed[$extension], true)) {
            throw ValidationException::withMessages([
                'file' => 'The attachment contents do not match its allowed file type.',
            ]);
        }

        $sha256 = hash_file('sha256', $file->getPathname());
        if ($sha256 === false) {
            throw ValidationException::withMessages(['file' => 'The attachment could not be inspected.']);
        }

        $originalName = str_replace('\\', '/', $file->getClientOriginalName());
        $originalName = basename($originalName);
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
        $originalName = trim($originalName);
        if ($originalName === '') {
            $originalName = "attachment.{$extension}";
        }

        return [
            'extension' => $extension,
            'original_name' => mb_strcut($originalName, 0, 255, 'UTF-8'),
            'declared_mime' => $this->nullableMime($file->getClientMimeType()),
            'detected_mime' => $detected,
            'size_bytes' => $size,
            'sha256' => $sha256,
        ];
    }

    private function nullableMime(?string $mime): ?string
    {
        $mime = trim((string) $mime);

        return $mime === '' ? null : mb_strcut($mime, 0, 120, 'UTF-8');
    }
}
