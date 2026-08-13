<?php

namespace App\DataPortability\Support;

use Illuminate\Validation\ValidationException;
use Normalizer;

final class CrmPortableCsv
{
    public const SCHEMA = 'maestro_people_households';

    public const VERSION = '1.0';

    private const ENCODING = 'base64-v1';

    private const ENCODED_PREFIX = '~b64~';

    private const MAX_FIELD_BYTES = 65_536;

    private const MAX_RECORD_BYTES = 1_048_576;

    /** @var list<string> */
    public const PEOPLE_COLUMNS = [
        'person_id', 'external_reference', 'first_name', 'last_name', 'preferred_name',
        'email', 'phone', 'birth_date', 'pronouns', 'status', 'preferred_locale',
        'student_status', 'student_joined_on', 'student_school_grade', 'household_id',
        'household_name', 'household_notes', 'household_role', 'is_primary_contact',
        'receives_billing',
    ];

    /** @var list<string> */
    private const PORTABLE_COLUMNS = ['_maestro_schema', '_maestro_version', '_maestro_encoding', ...self::PEOPLE_COLUMNS];

    public function template(): string
    {
        return $this->write(self::PEOPLE_COLUMNS, [[
            '', '', 'Ada', 'Lovelace', '', 'ada@example.test', '+15555550100',
            '2010-12-10', '', 'active', 'en', 'active', '2026-01-01', '10', '',
            'Lovelace Family', '', 'student', 'false', 'false',
        ]]);
    }

    /** @return array{portable:bool,columns:list<string>,rows:list<array{record_number:int,values:array<string,string>}>} */
    public function parse(string $bytes): array
    {
        $this->preflight($bytes);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes);
        rewind($stream);
        $started = hrtime(true);
        $header = $this->readRecord($stream, 1);
        $portable = $header === self::PORTABLE_COLUMNS;
        $normalizedHeadings = array_map($this->normalizeKey(...), $header);
        if (count($header) === 0 || count($header) > 64 || count(array_unique($normalizedHeadings)) !== count($header)) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_COLUMNS_INVALID']);
        }
        foreach ($header as $heading) {
            if ($heading === '' || strlen($heading) > 128 || $this->isFormulaEffective($heading)) {
                throw ValidationException::withMessages(['file' => 'CRM_IMPORT_COLUMNS_INVALID']);
            }
        }

        $rows = [];
        $record = 1;
        while (($values = $this->readRecord($stream, ++$record, eofAllowed: true)) !== null) {
            if ((hrtime(true) - $started) > 5_000_000_000) {
                throw ValidationException::withMessages(['file' => 'CRM_IMPORT_PARSE_TIMEOUT']);
            }
            if ($values === [null] || $values === ['']) {
                continue;
            }
            if (count($rows) >= (int) config('data-portability.max_rows', 25_000)) {
                throw ValidationException::withMessages(['file' => 'CRM_IMPORT_ROW_LIMIT_EXCEEDED']);
            }
            if (count($values) !== count($header)) {
                throw ValidationException::withMessages(['file' => 'CRM_IMPORT_ROW_WIDTH_INVALID:'.$record]);
            }
            foreach ($values as $value) {
                if (strlen((string) $value) > self::MAX_FIELD_BYTES) {
                    throw ValidationException::withMessages(['file' => 'CRM_IMPORT_FIELD_TOO_LARGE:'.$record]);
                }
            }

            $assoc = array_combine($header, array_map(fn ($value): string => (string) $value, $values));
            if ($portable) {
                if ($assoc['_maestro_schema'] !== self::SCHEMA || $assoc['_maestro_version'] !== self::VERSION || $assoc['_maestro_encoding'] !== self::ENCODING) {
                    throw ValidationException::withMessages(['file' => 'CRM_IMPORT_SCHEMA_UNSUPPORTED:'.$record]);
                }
                $assoc = array_intersect_key($assoc, array_flip(self::PEOPLE_COLUMNS));
                foreach ($assoc as $column => $value) {
                    $assoc[$column] = $this->decodePortable($value, $record);
                }
            }
            $rows[] = ['record_number' => $record, 'values' => $assoc];
        }
        fclose($stream);

        return ['portable' => $portable, 'columns' => $portable ? self::PEOPLE_COLUMNS : $header, 'rows' => $rows];
    }

    /** @param list<string> $columns @param iterable<list<scalar|null>> $rows */
    public function encodeDataset(array $columns, iterable $rows): string
    {
        $portableColumns = ['_maestro_schema', '_maestro_version', '_maestro_encoding', ...$columns];
        $portableRows = [];
        foreach ($rows as $row) {
            $portableRows[] = [self::SCHEMA, self::VERSION, self::ENCODING, ...array_map(
                fn ($value): string => $this->encodePortable((string) ($value ?? '')),
                $row,
            )];
        }

        return $this->write($portableColumns, $portableRows);
    }

    /** @param list<string> $expectedColumns @return list<array<string,string>> */
    public function parseDataset(string $bytes, array $expectedColumns): array
    {
        $this->preflight($bytes);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $expected = ['_maestro_schema', '_maestro_version', '_maestro_encoding', ...$expectedColumns];
        if ($this->readRecord($stream, 1) !== $expected) {
            throw ValidationException::withMessages(['bundle' => 'CRM_BUNDLE_DATASET_COLUMNS_INVALID']);
        }
        $rows = [];
        $record = 1;
        while (($values = $this->readRecord($stream, ++$record, true)) !== null) {
            if ($values === [null] || $values === ['']) {
                continue;
            }
            if (count($values) !== count($expected) || count($rows) >= (int) config('data-portability.max_rows', 25000)) {
                throw ValidationException::withMessages(['bundle' => 'CRM_BUNDLE_DATASET_BOUNDS_INVALID']);
            }
            $assoc = array_combine($expected, array_map(static fn ($value) => (string) $value, $values));
            if ($assoc['_maestro_schema'] !== self::SCHEMA || $assoc['_maestro_version'] !== self::VERSION || $assoc['_maestro_encoding'] !== self::ENCODING) {
                throw ValidationException::withMessages(['bundle' => 'CRM_BUNDLE_DATASET_PROFILE_INVALID']);
            }
            $row = [];
            foreach ($expectedColumns as $column) {
                $row[$column] = $this->decodePortable($assoc[$column], $record);
            } $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function normalizeKey(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        $value = $normalized === false ? $value : $normalized;

        return mb_strtolower(preg_replace('/[\p{Z}\s]+/u', ' ', trim($value)) ?? trim($value));
    }

    private function preflight(string $bytes): void
    {
        if ($bytes === '' || strlen($bytes) > (int) config('data-portability.max_upload_bytes', 10_485_760)) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_SIZE_INVALID']);
        }
        if (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_ENCODING_INVALID']);
        }

        $quoted = false;
        $atFieldStart = true;
        $length = strlen($bytes);
        for ($index = 0; $index < $length; $index++) {
            $char = $bytes[$index];
            if ($quoted) {
                if ($char === '"') {
                    if (($bytes[$index + 1] ?? null) === '"') {
                        $index++;

                        continue;
                    }
                    $quoted = false;
                    $next = $bytes[$index + 1] ?? null;
                    if ($next !== null && ! in_array($next, [',', "\r", "\n"], true)) {
                        throw ValidationException::withMessages(['file' => 'CRM_IMPORT_CSV_MALFORMED']);
                    }
                }

                continue;
            }
            if ($char === '"') {
                if (! $atFieldStart) {
                    throw ValidationException::withMessages(['file' => 'CRM_IMPORT_CSV_MALFORMED']);
                }
                $quoted = true;
                $atFieldStart = false;

                continue;
            }
            if ($char === ',') {
                $atFieldStart = true;

                continue;
            }
            if ($char === "\r" || $char === "\n") {
                $atFieldStart = true;

                continue;
            }
            $atFieldStart = false;
        }
        if ($quoted) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_CSV_MALFORMED']);
        }
    }

    /** @return list<string|null>|null */
    private function readRecord($stream, int $record, bool $eofAllowed = false): ?array
    {
        $start = ftell($stream);
        $values = fgetcsv($stream, null, ',', '"', '');
        if ($values === false) {
            if ($eofAllowed && feof($stream)) {
                return null;
            }
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_CSV_MALFORMED:'.$record]);
        }
        $end = ftell($stream);
        if (is_int($start) && is_int($end) && ($end - $start) > self::MAX_RECORD_BYTES) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_RECORD_TOO_LARGE:'.$record]);
        }

        return $values;
    }

    private function write(array $columns, iterable $rows): string
    {
        $records = [$this->serializeRecord($columns)];
        foreach ($rows as $row) {
            $records[] = $this->serializeRecord($row);
        }

        return implode("\r\n", $records)."\r\n";
    }

    private function serializeRecord(array $values): string
    {
        return implode(',', array_map(
            static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"',
            $values,
        ));
    }

    private function encodePortable(string $value): string
    {
        return $this->isFormulaEffective($value) || str_starts_with($value, self::ENCODED_PREFIX)
            ? self::ENCODED_PREFIX.base64_encode($value)
            : $value;
    }

    private function decodePortable(string $value, int $record): string
    {
        if (! str_starts_with($value, self::ENCODED_PREFIX)) {
            return $value;
        }
        $decoded = base64_decode(substr($value, strlen(self::ENCODED_PREFIX)), true);
        if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_PORTABLE_ENCODING_INVALID:'.$record]);
        }

        return $decoded;
    }

    private function isFormulaEffective(string $value): bool
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        $effective = preg_replace('/^[\p{Z}\p{C}]+/u', '', $normalized === false ? $value : $normalized) ?? $value;

        return preg_match('/^[=+\-@]/u', $effective) === 1;
    }
}
