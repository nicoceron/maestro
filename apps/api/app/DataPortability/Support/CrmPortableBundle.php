<?php

namespace App\DataPortability\Support;

use App\Audit\CanonicalJson;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class CrmPortableBundle
{
    private const ORDER = ['instruments', 'tags', 'custom_field_definitions', 'households', 'people', 'student_profiles', 'staff_profiles', 'person_instruments', 'person_tags', 'custom_field_values', 'household_members', 'guardian_relationships'];

    private const SCHEMAS = [
        'instruments' => ['instrument_ref', 'name', 'active'], 'tags' => ['tag_ref', 'name', 'color', 'active'],
        'custom_field_definitions' => ['definition_key', 'name', 'type', 'applies_to', 'options', 'required', 'active'],
        'households' => ['household_ref', 'name', 'notes'], 'people' => ['person_ref', 'first_name', 'last_name', 'preferred_name', 'email', 'phone', 'birth_date', 'pronouns', 'status', 'external_reference', 'preferred_locale'],
        'student_profiles' => ['person_ref', 'status', 'joined_on', 'left_on', 'school_grade', 'learning_preferences', 'lead_source', 'trial_started_on', 'waitlisted_on'],
        'staff_profiles' => ['person_ref', 'roles', 'status', 'employment_type', 'bio', 'hire_on', 'left_on', 'can_substitute'],
        'person_instruments' => ['person_ref', 'instrument_ref', 'relationship', 'proficiency', 'is_primary', 'years_experience'],
        'person_tags' => ['person_ref', 'tag_ref'], 'custom_field_values' => ['definition_key', 'person_ref', 'value'],
        'household_members' => ['household_ref', 'person_ref', 'role', 'is_primary_contact', 'receives_billing'],
        'guardian_relationships' => ['household_ref', 'guardian_person_ref', 'student_person_ref', 'relationship', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup', 'portal_permissions'],
    ];

    private const DEPENDENCIES = ['instruments' => [], 'tags' => [], 'custom_field_definitions' => [], 'households' => [], 'people' => [], 'student_profiles' => ['people'], 'staff_profiles' => ['people'], 'person_instruments' => ['people', 'instruments'], 'person_tags' => ['people', 'tags'], 'custom_field_values' => ['people', 'custom_field_definitions'], 'household_members' => ['households', 'people'], 'guardian_relationships' => ['households', 'people']];

    public function __construct(private CrmPortableCsv $csv) {}

    public function isBundle(string $bytes): bool
    {
        return str_starts_with(ltrim($bytes), '{');
    }

    /** @return array{manifest:array<string,mixed>,datasets:array<string,list<array<string,string>>>} */
    public function parse(string $bytes): array
    {
        if (strlen($bytes) > (int) config('data-portability.max_upload_bytes', 10485760)) {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_SIZE_INVALID']);
        }
        try {
            $bundle = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_JSON_INVALID']);
        }
        if (! is_array($bundle) || array_keys($bundle) !== ['datasets', 'manifest']) {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_SHAPE_INVALID']);
        }
        $manifest = $bundle['manifest'];
        $encoded = $bundle['datasets'];
        if (! is_array($manifest) || ! is_array($encoded) || ($manifest['schema'] ?? null) !== 'maestro.crm-portability' || ($manifest['version'] ?? null) !== '1.0') {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_SCHEMA_UNSUPPORTED']);
        }
        $declared = $manifest['datasets'] ?? null;
        if (! is_array($declared) || count($declared) !== count(self::ORDER)) {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DATASETS_INVALID']);
        }
        $result = [];
        $total = 0;
        foreach (self::ORDER as $position => $name) {
            $entry = $declared[$position] ?? null;
            $path = 'datasets/'.$name.'.csv';
            if (! is_array($entry) || ($entry['name'] ?? null) !== $name || ($entry['schema_version'] ?? null) !== '1.0' || ($entry['path'] ?? null) !== $path || ($entry['media_type'] ?? null) !== 'text/csv; charset=utf-8; header=present' || ($entry['columns'] ?? null) !== self::SCHEMAS[$name] || ($entry['dependencies'] ?? null) !== self::DEPENDENCIES[$name] || ! is_int($entry['row_count'] ?? null) || ! is_int($entry['byte_count'] ?? null) || ($entry['row_count'] < 0) || ($entry['row_count'] > 25000) || ($entry['byte_count'] < 1) || ! isset($encoded[$path]) || ! is_string($encoded[$path])) {
                throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DATASET_UNDECLARED']);
            }
            $csv = base64_decode($encoded[$path], true);
            if ($csv === false) {
                throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DATASET_ENCODING_INVALID']);
            }
            $total += strlen($csv);
            if ($total > 25 * 1024 * 1024 || strlen($csv) !== (int) ($entry['byte_count'] ?? -1) || ! hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $csv))) {
                throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DATASET_CHECKSUM_INVALID']);
            }
            $rows = $this->csv->parseDataset($csv, $entry['columns']);
            if (count($rows) !== (int) ($entry['row_count'] ?? -1)) {
                throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DATASET_COUNT_INVALID']);
            }$result[$name] = $rows;
        }
        if (array_diff(array_keys($encoded), array_map(fn ($name) => 'datasets/'.$name.'.csv', self::ORDER)) !== []) {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DATASET_UNDECLARED']);
        }
        $this->assertReferences($result);
        $copy = $manifest;
        $digest = $copy['manifest_sha256'] ?? null;
        unset($copy['manifest_sha256']);
        if (! is_string($digest) || ! hash_equals($digest, hash('sha256', CanonicalJson::encode($copy)))) {
            throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_MANIFEST_CHECKSUM_INVALID']);
        }

        return ['manifest' => $manifest, 'datasets' => $result];
    }

    private function assertReferences(array $datasets): void
    {
        $indexes = [];
        foreach (['people' => 'person_ref', 'households' => 'household_ref', 'instruments' => 'instrument_ref', 'tags' => 'tag_ref', 'custom_field_definitions' => 'definition_key'] as $dataset => $column) {
            $values = array_column($datasets[$dataset], $column);
            if (in_array('', $values, true) || count($values) !== count(array_unique($values))) {
                throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_DUPLICATE_REFERENCE']);
            }$indexes[$column] = array_flip($values);
        }
        $requirements = ['student_profiles' => ['person_ref'], 'staff_profiles' => ['person_ref'], 'person_instruments' => ['person_ref', 'instrument_ref'], 'person_tags' => ['person_ref', 'tag_ref'], 'custom_field_values' => ['person_ref', 'definition_key'], 'household_members' => ['person_ref', 'household_ref'], 'guardian_relationships' => ['household_ref', 'guardian_person_ref' => 'person_ref', 'student_person_ref' => 'person_ref']];
        foreach ($requirements as $dataset => $columns) {
            foreach ($datasets[$dataset] as $row) {
                foreach ($columns as $column => $index) {
                    if (is_int($column)) {
                        $column = $index;
                    }$index = is_string($index) && isset($indexes[$index]) ? $index : $column;
                    if (! isset($indexes[$index][$row[$column] ?? ''])) {
                        throw ValidationException::withMessages(['file' => 'CRM_BUNDLE_ORPHAN_REFERENCE']);
                    }
                }
            }
        }
    }
}
