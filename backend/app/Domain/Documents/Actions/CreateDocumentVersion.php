<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appends a new immutable version to a document. The document row is locked
 * while the next version number is chosen, so concurrent saves are
 * serialised instead of colliding on the (document_id, version_number)
 * unique index.
 */
class CreateDocumentVersion
{
    public function execute(Document $document, string $content, ?User $by = null, ?string $changeSummary = null): DocumentVersion
    {
        return DB::transaction(function () use ($document, $content, $by, $changeSummary) {
            $locked = Document::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->id);

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'content' => "This document is {$locked->status->value} and can no longer be edited.",
                ]);
            }

            $latest = $locked->latestVersion()->first();

            if ($latest !== null && $latest->content === $content) {
                throw ValidationException::withMessages(['content' => 'No changes since the current version.']);
            }

            $version = $locked->versions()->create([
                'version_number' => $locked->current_version + 1,
                'content' => $content,
                'change_summary' => $changeSummary,
                'created_by' => $by?->id,
            ]);

            $locked->forceFill(['current_version' => $version->version_number])->save();
            $document->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });
    }
}
