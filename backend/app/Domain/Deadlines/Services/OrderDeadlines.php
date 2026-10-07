<?php

namespace App\Domain\Deadlines\Services;

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\ClaudeClient;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads a court order, resolution or decision filed with a matter and
 * suggests the deadlines that follow from it: when it was received, and
 * what must be done within how many days (or on what date). The AI only
 * reads and proposes; due dates are counted here under Rule 22 (weekends
 * and holidays), and nothing is scheduled until a lawyer confirms each one.
 */
class OrderDeadlines
{
    private const SYSTEM = <<<'PROMPT'
        You read Philippine court orders, resolutions, notices and decisions for a law firm, and identify the deadlines they create for the firm's client.

        Report:
        - what the document is, and the date it bears;
        - the date the firm or client received it, only if the text itself shows it (a "received" stamp, registry return card, or a statement of receipt). If it does not, leave it null: never assume receipt on the date of the order;
        - each act the client must or may do because of it, with the period counted from receipt (for example 15 days to file a motion for reconsideration or a notice of appeal, 10 days to comment, 5 days to file a position paper) or the fixed date the court set (a hearing, pre-trial or the deadline the order itself names).

        Give the period in days exactly as the order or the Rules state it; do not convert it into a calendar date. For each deadline give the legal basis (the order's own words, or the rule, such as Rule 37 Sec. 1 or Rule 41 Sec. 3) and quote the passage of the document it comes from. Include periods the Rules give even if the order does not mention them (for example the period to move for reconsideration of a final order), and say so in the basis. Do not invent facts; if something is unclear, say so in the summary.
        PROMPT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'document_type' => ['type' => 'string'],
            'document_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            'date_received' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD, only if the text shows it'],
            'date_received_quote' => ['type' => ['string', 'null']],
            'summary' => ['type' => 'string'],
            'deadlines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'kind' => ['type' => 'string', 'enum' => ['filing', 'hearing', 'task']],
                        'period_days' => ['type' => ['integer', 'null'], 'description' => 'days counted from receipt'],
                        'fixed_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD when the court set the date'],
                        'fixed_time' => ['type' => ['string', 'null'], 'description' => 'HH:MM, 24-hour'],
                        'basis' => ['type' => 'string'],
                        'quote' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'kind', 'period_days', 'fixed_date', 'fixed_time', 'basis', 'quote'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['document_type', 'document_date', 'date_received', 'date_received_quote', 'summary', 'deadlines'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly ClaudeClient $claude, private readonly Assistant $assistant, private readonly DeadlineCalculator $calculator) {}

    /**
     * @return array{document_type: string, document_date: ?string, date_received: ?string, date_received_quote: ?string, summary: string, deadlines: list<array>}
     */
    public function suggest(Matter $matter, MatterFile $file, User $by): array
    {
        if ($reason = $this->assistant->unavailableReason($matter->firm_id)) {
            throw ValidationException::withMessages(['file' => $reason]);
        }
        if ((int) $file->matter_id !== (int) $matter->id) {
            abort(404);
        }
        $text = trim((string) $file->content_text);
        if ($text === '') {
            throw ValidationException::withMessages(['file' => 'The text of this file has not been read yet (or it is a scan that could not be read). Try again in a minute, or enter the deadlines by hand.']);
        }
        if (mb_strlen($text) > (int) config('services.anthropic.context_chars', 400000)) {
            throw ValidationException::withMessages(['file' => 'This file is too long to read in one go. Upload just the order or the decision\'s dispositive portion.']);
        }

        $context = "Matter: {$matter->title}".($matter->case_number ? "\nCase number: {$matter->case_number}" : '').($matter->court ? "\nCourt: {$matter->court}" : '')."\nOur client is the ".($matter->client_role ?: 'party represented').'.';
        try {
            $response = $this->claude->send(
                [['type' => 'text', 'text' => self::SYSTEM]],
                [['role' => 'user', 'content' => "{$context}\n\nDocument ({$file->original_name}):\n<document>\n{$text}\n</document>"]],
                self::SCHEMA,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if ($response['stop_reason'] === 'refusal') {
            throw ValidationException::withMessages(['file' => 'The assistant declined to read this document. Enter the deadlines by hand.']);
        }
        if ($response['stop_reason'] === 'max_tokens') {
            throw ValidationException::withMessages(['file' => 'The answer was cut short. Try again, or enter the deadlines by hand.']);
        }

        $data = json_decode($response['text'], true);
        if (! is_array($data) || ! isset($data['deadlines'])) {
            throw ValidationException::withMessages(['file' => 'The assistant\'s answer could not be read. Try again.']);
        }

        AuditLog::record('order_deadlines_suggested', $matter->firm_id, $by, $file, ['model' => $response['model'], 'deadlines' => count($data['deadlines'])]);

        return [
            'document_type' => (string) $data['document_type'],
            'document_date' => $this->date($data['document_date'] ?? null),
            'date_received' => $this->date($data['date_received'] ?? null),
            'date_received_quote' => $data['date_received_quote'] ?? null,
            'summary' => (string) $data['summary'],
            'deadlines' => array_map(fn (array $d) => $this->countFrom($d, $this->date($data['date_received'] ?? null)), $data['deadlines']),
        ];
    }

    /** Due date counted under Rule 22 from receipt, or the date the court set (moved past a non-working day only for filings). */
    private function countFrom(array $d, ?string $received): array
    {
        $fixed = $this->date($d['fixed_date'] ?? null);
        $days = isset($d['period_days']) && (int) $d['period_days'] > 0 ? min(3650, (int) $d['period_days']) : null;
        $due = match (true) {
            $fixed !== null => $fixed,
            $days !== null && $received !== null => $this->calculator->calculate(CarbonImmutable::parse($received), $days)->toDateString(),
            default => null,
        };

        return [
            'title' => mb_substr((string) $d['title'], 0, 255),
            'kind' => in_array($d['kind'] ?? null, ['filing', 'hearing', 'task'], true) ? $d['kind'] : 'filing',
            'period_days' => $fixed !== null ? null : $days,
            'due_date' => $due,
            'due_time' => isset($d['fixed_time']) && preg_match('/^\d{2}:\d{2}$/', (string) $d['fixed_time']) ? $d['fixed_time'] : null,
            'basis' => (string) ($d['basis'] ?? ''),
            'quote' => mb_substr((string) ($d['quote'] ?? ''), 0, 1000),
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
