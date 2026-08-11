<?php

namespace stubr\services\providers;

use stubr\services\providers\LlmProviderInterface;
use GuzzleHttp\Client;
use craft\helpers\App;
use stubr\Plugin;

class DeepLProvider implements LlmProviderInterface
{
    // DeepL is a translation API, not an LLM — it has no effort concept, so
    // $options is accepted (the interface requires it) and ignored.
    public function generateText($prompt, $context, $fieldHandle, string $systemPrompt, array $options = []):string {
        $client = new Client();

        $apiKey = App::parseEnv(Plugin::$plugin->getSettings()->deeplApiKey);
        if (!$apiKey) {
            throw new \Exception('DeepL API key not configured');
        }

        $targetLang = $prompt;

        preg_match('/' . $fieldHandle . ': (.+)/', $context, $matches);
        $textToTranslate = $matches[1] ?? '';

        try {
            $response = $client->post('https://api-free.deepl.com/v2/translate', [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
                ],
                'form_params' => [
                    'text' => $textToTranslate,
                    'target_lang' => $targetLang,
                ]
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            throw new \Exception('DeepL API error: ' . $errorBody);
        }

        $body = json_decode($response->getBody(), true);
        return $body['translations'][0]['text'];        

    }
}
