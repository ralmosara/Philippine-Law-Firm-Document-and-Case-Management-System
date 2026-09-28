<?php

namespace App\Domain\Documents\Services;

use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Documents\Actions\CreateDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Renders document templates. Templates are plain text with {{ field }}
 * placeholders; plain text keeps generated pleadings diffable line by line
 * and removes any chance of stored markup being rendered as HTML.
 */
class DocumentMerger
{
    private const PLACEHOLDER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * Replace placeholders with values. Placeholders without a value are left
     * intact so gaps are visible in the draft rather than silently blank.
     *
     * @param  array<string, scalar|null>  $data
     */
    public function merge(string $template, array $data): string
    {
        return preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($data) {
            $value = $data[$match[1]] ?? null;

            return $value === null || $value === '' ? $match[0] : (string) $value;
        }, $template);
    }

    /**
     * Placeholder names used in a template, in order of first appearance.
     *
     * @return list<string>
     */
    public function fieldsIn(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Standard merge values available for every matter.
     *
     * @return array<string, string|null>
     */
    public function dataFor(Matter $matter, ?User $lawyer = null): array
    {
        $matter->loadMissing(['client', 'responsibleLawyer', 'firm']);
        $lawyer ??= $matter->responsibleLawyer;
        $corp = $matter->client_id ? CorporateProfile::where('client_id', $matter->client_id)->first() : null;

        return [
            'firm_name' => $matter->firm?->name,
            'firm_address' => $matter->firm?->address,
            'client_name' => $matter->client?->name,
            'client_address' => $matter->client?->address,
            'client_tin' => $matter->client?->tin,
            'matter_title' => $matter->title,
            'matter_reference' => $matter->reference,
            'case_number' => $matter->case_number,
            'case_type' => $matter->case_type,
            'court' => $matter->court,
            'court_branch' => $matter->court_branch,
            'judge' => $matter->judge,
            'lawyer_name' => $lawyer?->name,
            'lawyer_roll_number' => $lawyer?->roll_number,
            'lawyer_ibp_number' => $lawyer?->ibp_number,
            'date_today' => now()->format('F j, Y'),
            // The client company's profile, for corporate secretarial documents.
            'corp_sec_reg_no' => $corp?->sec_registration_no,
            'corp_principal_office' => $corp?->principal_office ?: $matter->client?->address,
            'corp_secretary' => $corp?->corporate_secretary,
            'corp_incorporated_on' => $corp?->incorporated_on?->format('F j, Y'),
            'fiscal_year_end_text' => $corp ? $this->fiscalYearEnd($corp->fiscal_year_end) : null,
        ];
    }

    /** "December 31, 2025": the last fiscal year end on or before today. */
    private function fiscalYearEnd(string $monthDay): string
    {
        [$m, $d] = array_map('intval', explode('-', $monthDay));
        $end = now()->startOfDay()->setDate(now()->year, $m, 1);
        $end = $end->setDay(min($d, $end->daysInMonth));
        if ($end->isFuture()) {
            $end = $end->subYearNoOverflow();
        }

        return $end->format('F j, Y');
    }

    /**
     * Create a new draft document on a matter from a template, as version 1.
     *
     * @param  array<string, scalar|null>  $overrides  Values for custom fields, or to replace defaults.
     */
    public function generate(DocumentTemplate $template, Matter $matter, User $by, array $overrides = [], ?string $title = null): Document
    {
        $content = $this->merge($template->body, [...$this->dataFor($matter), ...$overrides]);

        return DB::transaction(function () use ($template, $matter, $by, $content, $title) {
            $document = $matter->documents()->create([
                'firm_id' => $matter->firm_id,
                'template_id' => $template->id,
                'title' => $title ?? "{$template->name} — {$matter->title}",
                'created_by' => $by->id,
            ]);

            app(CreateDocumentVersion::class)->execute($document, $content, $by, "Generated from template \"{$template->name}\"");

            return $document->refresh();
        });
    }
}
