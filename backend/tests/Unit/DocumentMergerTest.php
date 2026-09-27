<?php

namespace Tests\Unit;

use App\Domain\Documents\Services\DocumentMerger;
use PHPUnit\Framework\TestCase;

class DocumentMergerTest extends TestCase
{
    public function test_it_replaces_merge_fields_with_provided_data(): void
    {
        $template = 'Dear {{client_name}}, your hearing is on {{ hearing_date }}.';

        $this->assertSame(
            'Dear Juan Dela Cruz, your hearing is on October 10, 2026.',
            (new DocumentMerger)->merge($template, ['client_name' => 'Juan Dela Cruz', 'hearing_date' => 'October 10, 2026']),
        );
    }

    public function test_missing_or_empty_values_leave_the_placeholder_visible(): void
    {
        $this->assertSame(
            'Case {{ case_number }} / {{judge}}',
            (new DocumentMerger)->merge('Case {{ case_number }} / {{judge}}', ['case_number' => null, 'judge' => '']),
        );
    }

    public function test_values_are_inserted_literally(): void
    {
        // A value that looks like a placeholder must not be expanded again.
        $this->assertSame(
            'Name: {{ secret }}',
            (new DocumentMerger)->merge('Name: {{ name }}', ['name' => '{{ secret }}', 'secret' => 'leaked']),
        );
    }

    public function test_it_lists_fields_in_order_without_duplicates(): void
    {
        $this->assertSame(
            ['client_name', 'amount'],
            (new DocumentMerger)->fieldsIn('{{ client_name }} owes {{amount}}. Signed, {{ client_name }}'),
        );
    }
}
