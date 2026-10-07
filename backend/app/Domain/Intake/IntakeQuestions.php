<?php

namespace App\Domain\Intake;

use App\Domain\Matters\Models\Firm;
use Illuminate\Support\Str;

/**
 * The firm's own questions on the public consultation form, by type of case
 * (the date of dismissal for a labor case, the title number for land).
 * Answers are kept with the request and carried into the prospect or
 * matter's description.
 *
 * Stored on the firm as {case type: [{key, label, type, required, hint}]}.
 */
class IntakeQuestions
{
    public const TYPES = ['text', 'textarea', 'date', 'number'];

    public const MAX_PER_TYPE = 10;

    /** @return list<array{key: string, label: string, type: string, required: bool, hint: ?string}> */
    public function for(Firm $firm, string $caseType): array
    {
        return array_values($firm->intake_questions[$caseType] ?? []);
    }

    /**
     * Clean what the firm entered: give each question a stable key from its label.
     *
     * @param  array<string, list<array{label: string, type: string, required?: bool, hint?: ?string}>>  $input
     */
    public function normalise(array $input): array
    {
        $out = [];
        foreach ($input as $caseType => $questions) {
            $keys = [];
            foreach (array_slice($questions, 0, self::MAX_PER_TYPE) as $q) {
                $label = trim((string) ($q['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $key = Str::slug(Str::limit($label, 40, ''), '_') ?: 'question';
                $base = $key;
                for ($n = 2; in_array($key, $keys, true); $n++) {
                    $key = "{$base}_{$n}";
                }
                $keys[] = $key;
                $out[$caseType][] = [
                    'key' => $key,
                    'label' => $label,
                    'type' => in_array($q['type'] ?? 'text', self::TYPES, true) ? $q['type'] : 'text',
                    'required' => (bool) ($q['required'] ?? false),
                    'hint' => filled($q['hint'] ?? null) ? trim($q['hint']) : null,
                ];
            }
        }

        return $out;
    }

    /** Validation rules for the answers to a case type's questions. */
    public function rules(Firm $firm, string $caseType): array
    {
        $rules = ['answers' => ['nullable', 'array']];
        foreach ($this->for($firm, $caseType) as $q) {
            $rules["answers.{$q['key']}"] = [
                $q['required'] ? 'required' : 'nullable',
                ...match ($q['type']) {
                    'date' => ['date_format:Y-m-d'],
                    'number' => ['numeric', 'min:0', 'max:1000000000000'],
                    'textarea' => ['string', 'max:2000'],
                    default => ['string', 'max:500'],
                },
            ];
        }

        return $rules;
    }

    /** @return array<string, string> attribute names for the error messages: the question itself */
    public function attributes(Firm $firm, string $caseType): array
    {
        return collect($this->for($firm, $caseType))->mapWithKeys(fn ($q) => ["answers.{$q['key']}" => $q['label']])->all();
    }

    /**
     * The answers as given, with each question's wording at the time.
     *
     * @return list<array{label: string, answer: string}>
     */
    public function answers(Firm $firm, string $caseType, array $given): array
    {
        return collect($this->for($firm, $caseType))
            ->filter(fn ($q) => filled($given[$q['key']] ?? null))
            ->map(fn ($q) => ['label' => $q['label'], 'answer' => trim((string) $given[$q['key']])])
            ->values()->all();
    }

    /** The answers appended to a description that carries into a prospect or matter. */
    public static function withAnswers(?string $description, ?array $answers): ?string
    {
        if (! $answers) {
            return $description;
        }

        return trim(($description ?? '')."\n\nAnswers on the consultation form:\n".implode("\n", array_map(fn ($a) => "- {$a['label']}: {$a['answer']}", $answers)));
    }
}
