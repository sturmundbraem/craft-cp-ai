<?php

namespace stubr\services\providers;

use GuzzleHttp\Client;
use stubr\services\providers\LlmProviderInterface;
use craft\helpers\App;
use stubr\Plugin;

// Handles communication with the Claude API
// Implements the interface so it has the same method signature as all other providers
class ClaudeProvider implements LlmProviderInterface
{
    // Which model each effort tier uses. Prices per million tokens (input/output):
    // Haiku 4.5 $1/$5 · Sonnet 5 $3/$15 · Opus 5 $5/$25
    private const MODELS = [
        'low'    => 'claude-haiku-4-5',
        'medium' => 'claude-sonnet-5',
        'high'   => 'claude-opus-5',
    ];

    public function generateText(string $prompt, string $context, string $fieldHandle, string $systemPrompt, array $options = []): string
    {
        // Easy/Medium/Hard from the prompt settings picks the model
        $tier = $options['effort'] ?? 'medium';
        $model = self::MODELS[$tier] ?? self::MODELS['medium'];

        // Build the full prompt that combines: page context + task + target field
        $fullPrompt = "Here is the content of the page:\n" . $context . "\nTask: " . $prompt . "\nWrite the content for the field: " . $fieldHandle;

        // --- Real API call (currently skipped because of the return above) ---

        // Guzzle is an HTTP client library (like Python's requests)
        $client = new Client();

        // Read the API key from the .env file — NEVER hardcode API keys!
        $apiKey = App::parseEnv(Plugin::$plugin->getSettings()->claudeApiKey);
        if (!$apiKey) {
            throw new \Exception('Claude API key not configured');
        }
        // Send a POST request to Claude's chat completions endpoint
        try {
            $response = $client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,   // Authentication
                'Content-Type' => 'application/json',       // Tell Claude we're sending JSON
                'anthropic-version' => '2023-06-01'
            ],
            'json' => [
                'model' => $model,
                // max_tokens caps thinking AND the visible answer together —
                // Sonnet 5 and Opus 5 think by default, so keep headroom.
                'max_tokens' => 4096,
                'system' => $systemPrompt, 
                'messages' => [
                    ['role' => 'user', 'content' => $fullPrompt]
                ]
            ]
        ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            throw new \Exception('Claude API error: ' . $errorBody);
        }
        // Parse the JSON response from OpenAI
        $body = json_decode($response->getBody(), true);

        // Claude returns a list of blocks, e.g. a "thinking" block followed by the
        // answer. Walk the list and return the first real text block.
        foreach ($body['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'];
            }
        }

        throw new \Exception('Claude returned no text block');

    }
}