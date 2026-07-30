<?php

namespace App\Domain\Documents\Services;

class DocumentMerger
{
    /**
     * Merge fields into a document template.
     * Replaces occurrences of {{field_name}} with the corresponding value from the data array.
     *
     * @param string $templateContent
     * @param array $data Associative array of field names and values
     * @return string
     */
    public function merge(string $templateContent, array $data): string
    {
        $mergedContent = $templateContent;

        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            // Simple string replacement for now. In a real scenario, this might need 
            // to handle HTML encoding if the template is HTML and values are raw strings.
            $mergedContent = str_replace($placeholder, (string) $value, $mergedContent);
        }

        return $mergedContent;
    }
}
