<?php

namespace Tests\Unit\DataPortability;

use App\DataPortability\Support\CrmPortableCsv;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CrmPortableCsvTest extends TestCase
{
    public function test_template_is_header_first_canonical_and_round_trips_formula_like_phone(): void
    {
        $csv = app(CrmPortableCsv::class);
        $template = $csv->template();

        $this->assertStringStartsWith('"person_id","external_reference","first_name"', $template);
        $this->assertStringEndsWith("\r\n", $template);
        $parsed = $csv->parse($template);
        $this->assertFalse($parsed['portable']);
        $this->assertSame('+15555550100', $parsed['rows'][0]['values']['phone']);
        $this->assertSame(2, $parsed['rows'][0]['record_number']);
    }

    public function test_parser_preserves_quoted_multiline_and_literal_whitespace(): void
    {
        $csv = app(CrmPortableCsv::class);
        $columns = CrmPortableCsv::PEOPLE_COLUMNS;
        $row = array_fill(0, count($columns), '');
        $row[array_search('first_name', $columns, true)] = " Ada\nLovelace ";
        $bytes = implode(',', array_map(fn ($value) => '"'.$value.'"', $columns))."\r\n".
            implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', $value).'"', $row))."\r\n";

        $parsed = $csv->parse($bytes);
        $this->assertSame(" Ada\nLovelace ", $parsed['rows'][0]['values']['first_name']);
    }

    public function test_portable_encoding_reversibly_neutralizes_formula_effective_cells(): void
    {
        $csv = app(CrmPortableCsv::class);
        $columns = CrmPortableCsv::PEOPLE_COLUMNS;
        $row = array_fill(0, count($columns), '');
        $row[array_search('first_name', $columns, true)] = 'Ada';
        $row[array_search('email', $columns, true)] = "\t＝2+2";

        $bytes = $csv->encodeDataset($columns, [$row]);
        $this->assertStringContainsString('~b64~', $bytes);
        $this->assertSame("\t＝2+2", $csv->parse($bytes)['rows'][0]['values']['email']);
    }

    public function test_parser_rejects_normalization_equivalent_duplicate_headers(): void
    {
        $this->expectException(ValidationException::class);
        app(CrmPortableCsv::class)->parse("\"Name\",\" name \"\r\n\"Ada\",\"Lovelace\"\r\n");
    }
}
