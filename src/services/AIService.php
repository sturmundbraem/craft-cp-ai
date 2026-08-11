<?php

namespace stubr\services;

use stubr\services\providers\OpenAiProvider;
use stubr\services\providers\LlmProviderInterface;

// The AI service acts as a bridge between the controller and the LLM providers
// It reads the .env config to decide which provider to use, then forwards the request
class AIService
{
    // Takes a prompt, page context, and field handle
    // Returns the AI-generated text as a string
    public function generateContent(string $prompt, $context, $fieldHandle, string $provider, string $basePrompt, array $options = []): string
    {
        $factory = LlmProviderFactory::getInstance();
        $llmProvider = $factory->getProvider($provider);
        return $llmProvider->generateText($prompt, $context, $fieldHandle, $basePrompt, $options);
    }
}