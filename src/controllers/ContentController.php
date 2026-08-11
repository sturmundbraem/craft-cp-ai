<?php

namespace stubr\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use stubr\services\AIService;
use stubr\Plugin;

class ContentController extends \craft\web\Controller
{
    // This method handles POST requests from fieldHelper.js
    // URL: craft-loki/content/generate
    public function actionGenerate()
    {
        // Read the POST data sent by the JS fetch request
        // Each getBodyParam() reads one value by its key name
        $entryId = (int) Craft::$app->getRequest()->getBodyParam('entryId');       // Cast to integer for database lookup
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');                // Which site/language
        $fieldHandle = Craft::$app->getRequest()->getBodyParam('fieldHandle');       // Which field to fill (e.g. "subtitle")
        $liveValues = Craft::$app->getRequest()->getBodyParam('liveValues', []);
        $promptUid = Craft::$app->getRequest()->getBodyParam('promptUid');
        $prompt = Craft::$app->getRequest()->getBodyParam('prompt');                 // The AI prompt to use
        $provider = Craft::$app->getRequest()->getBodyParam('provider');            // Read provider from POST data
        $createDraft = Craft::$app->getRequest()->getBodyParam('createDraft');         
        $settings = Plugin::$plugin->getSettings();
        $prompts = Plugin::$plugin->getPromptSettings()->getPrompts();

        // $validPrompts = array_column($prompts, 'text');
        // if (!in_array($prompt, $validPrompts)) {
        //     return $this->asJson(['error' => 'Invalid prompt'], 400);
        // }
        $matchedPrompt = null;
        foreach ($prompts as $p) {
            if (($promptUid && ($p['uid'] ?? null) === $promptUid) || (!$promptUid && ($p['text'] ?? null) === $prompt)) {
                $matchedPrompt = $p;
                break;
            }
        }
        if ($matchedPrompt === null) {
            return $this->asJson(['error' => 'Invalid prompt'], 400);
        }
        $prompt = $matchedPrompt['text'] ?? '';
        $provider = $matchedPrompt['provider'] ?? $provider;
        $basePrompt = $settings->basePrompt ?? '';

        $allowedProviders = ['openai', 'claude', 'deepl'];
        if (!in_array($provider, $allowedProviders, true)) {
            return $this->asJson(['error' => 'Invalid provider'], 400);
        }

        // Per-prompt effort level; falls back to medium for prompts saved
        // before this column existed.
        $effort = $matchedPrompt['effort'] ?? 'medium';
        if (!in_array($effort, ['low', 'medium', 'high'], true)) {
            $effort = 'medium';
        }

        // Load the element by ID for the requested site. getElementById() returns
        // any element type (Entry, Asset, etc.) — needed because native attributes
        // like "alt" live on Assets, not just Entries.
        $entry = Craft::$app->getElements()->getElementById($entryId, null, $siteId);


        if (!$entry) {
            return $this->asJson(['error' => 'Element not found'], 404);
        }

        // $this->requirePermission('edit-entries:' . $entry->section->uid);

        // Native (non-custom-field) attributes handled directly, e.g. title, alt
        $isNativeAttr = in_array($fieldHandle, ['title', 'alt'], true);
        if (!$isNativeAttr && !$entry->getFieldLayout()->getFieldByHandle($fieldHandle)) {
            return $this->asJson(['error' => 'Invalid field'], 400);
        }

        $site = Craft::$app->getSites()->getSiteById($siteId);
        $entryTitle = $liveValues['__title'] ?? $entry->title;
        $fieldValues = $entry->getFieldValues();

        // Build a flat map handle => effective value for Twig substitution.
        // Uses liveValues (current unsaved edits) where present, falls back to DB values.
        $effectiveValues = [
            'title' => $entryTitle,
        ];
        foreach ($fieldValues as $handle => $value) {
            $effectiveValues[$handle] = $liveValues[$handle] ?? (string)$value;
        }

        //list of twig variables for easier prompt writing
        $twigVars = [
            'siteLang' => $site->language,
            'fieldHandle' => $fieldHandle,
            'entryTitle' => $entryTitle,
            'fields' => $effectiveValues,
        ];
        
        $prompt     = Craft::$app->getView()->renderString($prompt, $twigVars);
        $basePrompt = Craft::$app->getView()->renderString($basePrompt, $twigVars);

        $context = 'Title: ' . $entryTitle . "\n";
        foreach ($fieldValues as $handle => $value) {
            $effective = $liveValues[$handle] ?? (string)$value;
            $context .= $handle . ': ' . $effective . "\n";
        }

        // Call the AI service to generate text
        // The service picks the right provider (OpenAI, Claude, etc.) based on .env
        $aiService = new AIService();
        
        try {
            $generatedContent = $aiService->generateContent($prompt, $context, $fieldHandle, $provider, $basePrompt, ['effort' => $effort]);
        } catch (\Exception $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
        // The draft flow is Entry-specific (owner chain, Matrix, elements_owners).
        // Non-entry elements (e.g. Assets editing "alt") always take direct-fill.
        if ($createDraft === '1' && $entry instanceof Entry) {
            // Walk up to the root entry (handles Matrix-nested fields).
            // For top-level fields, $rootEntry will equal $entry.
            $rootEntry = $entry;
            while ($rootEntry->getOwner()) {
                $rootEntry = $rootEntry->getOwner();
            }

            // Draft the root entry. Craft re-attaches its nested elements (matrix blocks)
            // to the draft via elements_owners.
            $draft = Craft::$app->getDrafts()->createDraft($rootEntry);

            // Re-fetch the draft so nested matrix relationships are loaded into memory.
            // Without this, saveElement() can silently drop matrix associations.
            $draft = Entry::find()
                ->id($draft->id)
                ->draftId($draft->draftId)
                ->siteId($siteId)
                ->status(null)
                ->one();
                
            // Force matrix values to materialize so saveElement() doesn't write an empty matrix
            foreach ($draft->getFieldLayout()->getCustomFields() as $field) {
                if ($field instanceof \craft\fields\Matrix) {
                    $draft->getFieldValue($field->handle)->all();
                }
            }

            // The "target" inside the draft is either the draft itself (top-level case)
            // or the matching block in the draft (matrix case).
            $target = null;

            if ($rootEntry->id === $entry->id) {
                // Top-level: target is the draft entry itself
                $target = $draft;
            } else {
                // Matrix sub-field: find the matching block within the draft by UID
                foreach ($draft->getFieldLayout()->getCustomFields() as $field) {
                    if (!($field instanceof \craft\fields\Matrix)) continue;
                    $blocks = $draft->getFieldValue($field->handle)->all();
                    foreach ($blocks as $b) {
                        if ($b->uid === $entry->uid) {
                            $target = $b;
                            break 2;
                        }
                    }
                }
            }

            if (!$target) {
                return $this->asJson(['error' => 'Could not locate target element in the draft.'], 500);
            }

            // Apply the user's unsaved edits to the target
            foreach ($liveValues as $handle => $value) {
                if ($handle === '__title') {
                    $target->title = $value;
                    continue;
                }
                if (!$target->getFieldLayout()->getFieldByHandle($handle)) continue;
                $target->setFieldValue($handle, $value);
            }

            // Apply the AI-generated content to the field the user clicked
            $target->setFieldValue($fieldHandle, $generatedContent);

            // Save the target (entry or block — both are saveable elements in Craft 5)
            Craft::$app->getElements()->saveElement($target);

            // Workaround: saveElement() on a draft entry wipes nested-element ownership
            // rows that createDraft() had inserted. Re-link the matrix blocks to the draft
            // from the canonical. Only needed when we saved the draft entry itself
            // (top-level wand case); saving a block doesn't disturb the draft's matrix.
            if ($target === $draft) {
                $db = Craft::$app->getDb();
                $db->createCommand()->delete('{{%elements_owners}}', ['ownerId' => $draft->id])->execute();
                $db->createCommand(<<<SQL
                    INSERT INTO {{%elements_owners}} ([[elementId]], [[ownerId]], [[sortOrder]])
                    SELECT [[o.elementId]], :draftId, [[o.sortOrder]]
                    FROM {{%elements_owners}} AS [[o]]
                    WHERE [[o.ownerId]] = :canonicalId
                SQL, [
                    ':draftId' => $draft->id,
                    ':canonicalId' => $rootEntry->id,
                ])->execute();
            }


            return $this->asJson([
                'draftId' => $draft->draftId,
                'title' => $draft->title,
                'generatedContent' => $generatedContent,
                'fieldHandle' => $fieldHandle,
                'draftUrl' => $draft->getCpEditUrl(),   // Always the root entry's draft URL
            ]);
        }
            
        else {
            return $this->asJson([
                'generatedContent' => $generatedContent,
                'fieldHandle' => $fieldHandle,
            ]);
        }
    }
}
