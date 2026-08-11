<?php

namespace stubr\services\providers;

use GuzzleHttp\Client;
use stubr\services\providers\LlmProviderInterface;
use craft\helpers\App;
use stubr\Plugin;

// Handles communication with the OpenAI (ChatGPT) API
// Implements the interface so it has the same method signature as all other providers
class OpenAiProvider implements LlmProviderInterface
{
    // Which model each effort tier uses. The nano/mini tiers are pinned to dated
    // snapshots, so their behaviour won't shift under us when OpenAI updates them.
    private const MODELS = [
        'low'    => 'gpt-5.4-nano-2026-03-17',
        'medium' => 'gpt-5.4-mini-2026-03-17',
        'high'   => 'gpt-5.6-terra',
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
        $apiKey = App::parseEnv(Plugin::$plugin->getSettings()->openaiApiKey);
        if (!$apiKey) {
            throw new \Exception('OpenAI API key not configured');
        }

        // Send a POST request to OpenAI's chat completions endpoint
        try {
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,   // Authentication
                    'Content-Type' => 'application/json',       // Tell OpenAI we're sending JSON
                ],
                'json' => [                                     // The request body (automatically encoded to JSON by Guzzle)
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $fullPrompt]  // The prompt we send to the AI
                    ]
                ]
            ]);
        }
        catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            throw new \Exception('OpenAI API request failed: ' . $errorBody);
        }
        // Parse the JSON response from OpenAI
        $body = json_decode($response->getBody(), true);

        // Dig into the response structure to get the actual generated text
        // OpenAI returns: { choices: [ { message: { content: "the text" } } ] }
        return $body['choices'][0]['message']['content'];

    }
}