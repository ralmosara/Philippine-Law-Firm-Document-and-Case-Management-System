<?php

namespace App\Domain\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Anthropic Messages API client for the matter assistant. */
class ClaudeClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    public function configured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * @param  list<array{type: string, text: string, cache_control?: array}>  $system
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  when given, the answer is JSON matching it (structured outputs)
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int, cache_read_tokens: int, stop_reason: string}
     */
    public function send(array $system, array $messages, ?array $jsonSchema = null): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->acceptJson()
                ->timeout(170)
                ->post(self::URL, [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => (int) config('services.anthropic.max_tokens', 4096),
                    'system' => $system,
                    'messages' => $messages,
                    ...$jsonSchema ? ['output_config' => ['format' => ['type' => 'json_schema', 'schema' => $jsonSchema]]] : [],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('The assistant could not be reached. Please try again.');
        }

        if ($response->failed()) {
            throw new RuntimeException(match (true) {
                in_array($response->status(), [429, 529], true) => 'The assistant is busy right now. Please try again in a minute.',
                in_array($response->status(), [401, 403], true) => 'The assistant is not set up correctly (API key). Ask your administrator.',
                $response->status() === 400 => 'The request was too large or invalid. Try a narrower question.',
                default => 'The assistant could not answer (error '.$response->status().'). Please try again.',
            });
        }

        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');

        return [
            'text' => $text,
            'model' => (string) $response->json('model'),
            'input_tokens' => (int) $response->json('usage.input_tokens'),
            'output_tokens' => (int) $response->json('usage.output_tokens'),
            'cache_read_tokens' => (int) $response->json('usage.cache_read_input_tokens'),
            'stop_reason' => (string) $response->json('stop_reason'),
        ];
    }
}
