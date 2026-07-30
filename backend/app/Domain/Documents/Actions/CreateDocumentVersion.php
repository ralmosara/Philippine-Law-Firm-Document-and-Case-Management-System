<?php

namespace App\Domain\Documents\Actions;

use Illuminate\Support\Facades\DB;

class CreateDocumentVersion
{
    /**
     * Spawns a new document_versions row for a given document.
     * Enforces append-only tracking of edits.
     *
     * @param int $documentId
     * @param string $contentHtml
     * @param int|null $userId
     * @return int The new version number
     */
    public function execute(int $documentId, string $contentHtml, ?int $userId = null): int
    {
        return DB::transaction(function () use ($documentId, $contentHtml, $userId) {
            // Determine the next version number
            $latestVersion = DB::table('document_versions')
                ->where('document_id', $documentId)
                ->max('version_number') ?? 0;
                
            $nextVersion = $latestVersion + 1;
            
            DB::table('document_versions')->insert([
                'document_id' => $documentId,
                'content_html' => $contentHtml,
                'version_number' => $nextVersion,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            
            return $nextVersion;
        });
    }
}
