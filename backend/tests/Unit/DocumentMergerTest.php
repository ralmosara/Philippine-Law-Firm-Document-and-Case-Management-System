<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Domain\Documents\Services\DocumentMerger;

class DocumentMergerTest extends TestCase
{
    public function test_it_replaces_merge_fields_with_provided_data()
    {
        $merger = new DocumentMerger();
        
        $template = "<p>Dear {{client_name}}, your hearing is on {{hearing_date}}.</p>";
        $data = [
            'client_name' => 'Juan Dela Cruz',
            'hearing_date' => 'October 10, 2026'
        ];
        
        $result = $merger->merge($template, $data);
        
        $this->assertEquals("<p>Dear Juan Dela Cruz, your hearing is on October 10, 2026.</p>", $result);
    }
}
