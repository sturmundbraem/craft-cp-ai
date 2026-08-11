<?php

namespace stubr\services\providers;

// An interface is a contract — it defines which methods a class MUST have
// Every LLM provider (OpenAI, Claude, etc.) must implement this interface
// This ensures the AIService can use any provider in the same way
interface LlmProviderInterface
{
    // Every provider must have this method with these exact parameters
    // $prompt — the task for the AI (e.g. "Write a catchy subtitle")
    // $context — all the field values from the page, so the AI understands the content
    // $fieldHandle — which field the AI is writing for (e.g. "subtitle")
    // Returns: the generated text as a string
    // $options — extra per-prompt settings, e.g. ['effort' => 'medium'].
    //            Each provider maps these to its own API parameters, and ignores
    //            any option it doesn't support.
    public function generateText(string $prompt, string $context, string $fieldHandle, string $systemPrompt, array $options = []): string;
}