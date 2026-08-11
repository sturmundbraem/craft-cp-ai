<?php
namespace stubr;

// Craft and Yii framework classes
use Craft;
use yii\base\Event;
use craft\base\Model;
use craft\base\Field;
use craft\events\DefineFieldHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Plugins;

// Plugin's own classes
use stubr\models\Settings;
use stubr\FieldHelperAsset;
use stubr\services\PromptSettings;


class Plugin extends \craft\base\Plugin
{
    // A reference to this plugin instance, accessible from anywhere via Plugin::$plugin
    public static $plugin;

    private ?PromptSettings $_promptSettings = null;

    // Database schema version — increment when you change database structure
    public string $schemaVersion = "1.0.2";

    // Show a settings page for this plugin in the CP
    public bool $hasCpSettings = true;

    // Show a section in the CP for this plugin
    public bool $hasCpSection = true;

    // Tell Craft which model holds our plugin settings
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // Render the settings page HTML in the CP
    // This is shown when admin goes to Settings > Plugins > your plugin
    protected function settingsHtml(): string
    {
        
        // Render the Twig template and pass it the settings + fields data
        return \Craft::$app->getView()->renderTemplate(
            'craft-loki/settings.twig',
            [
                'settings' => $this->getSettings(),
            ]
        );

    }

    public function getPromptSettings(): PromptSettings
    {
        return $this->_promptSettings ??= new PromptSettings();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Loki';
        $item['url'] = 'craft-loki/prompts';
        unset($item['icon']);
        $item['fontIcon'] = 'wand-magic-sparkles';

        $item['subnav'] = [
            'prompts' => ['label' => 'Prompts', 'url' => 'craft-loki/prompts'],
            'settings' => ['label' => 'Settings', 'url' => 'settings/plugins/craft-loki'],
        ]; 
        
        return $item;
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules['craft-loki/prompts'] = 'craft-loki/prompts/index';
            }
        );


        // Defer setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            // ...
        });

        // Listen for when Craft renders any field's input HTML
        // This fires once for EACH field on the entry edit page
        Event::on(
            Field::class,                       // Listen on all field objects
            Field::EVENT_DEFINE_INPUT_HTML,      // The event that fires when field HTML is built
            function (DefineFieldHtmlEvent $event) {

            // Only add the wand button to PlainText and CKEditor fields
            if ($event->sender instanceof \craft\fields\PlainText || $event->sender instanceof \craft\ckeditor\Field) {

                // Load our JS and CSS files (fieldHelper.js, styles.css)
                $view = Craft::$app->getView();
                $bundle = FieldHelperAsset::register($view);
                $view->registerJs(
                    'var aiIconBase = ' . json_encode($bundle->baseUrl . '/icons') . ';',
                    \yii\web\View::POS_HEAD
                );

                // Load the prompts config and inject it as a JS variable
                // This makes the prompts available in the browser as window.aiPrompts
                $promptSettings = self::$plugin->getPromptSettings();
                $prompts = $promptSettings->getPrompts();
                $promptsByUid = [];
                foreach ($prompts as $prompt) {
                    if (!empty($prompt['uid'])) {
                        $promptsByUid[$prompt['uid']] = $prompt;
                    }
                }
                Craft::$app->getView()->registerJs('var aiPrompts = ' . json_encode($promptsByUid) . ';', \yii\web\View::POS_HEAD);

                $fieldAssignments = $promptSettings->getFieldAssignments();
                Craft::$app->getView()->registerJs('var aiFieldAssignments = ' . json_encode($fieldAssignments) . ';', \yii\web\View::POS_HEAD);

                // Get the field's handle (e.g. "subtitle") and type (e.g. "PlainText")
                $fieldHandle = $event->sender->handle;
                $fieldLabel = $event->sender->name;   // human-readable label, e.g. "Subtitle"
                if ($event->sender instanceof \craft\fields\PlainText) {
                    $fieldType = 'PlainText';
                } else if ($event->sender instanceof \craft\ckeditor\Field) {
                    $fieldType = 'CKEditor';
                }

                $matrixHandle = null;
                $element = $event->element ?? null;
                if ($element instanceof \craft\elements\Entry && $element->ownerId && $element->fieldId) {
                    $matrixField = Craft::$app->getFields()->getFieldById($element->fieldId);
                    if ($matrixField) {
                        $matrixHandle = $matrixField->handle;
                    }
                }

                $assignedSelf = $fieldAssignments[$fieldHandle] ?? [];
                $assignedMatrix = $matrixHandle ? ($fieldAssignments[$matrixHandle] ?? []) : [];
                $assignedAll = array_merge($assignedSelf, $assignedMatrix);

                $hasPrompts = false;
                if (!empty($assignedAll)) {
                    $existingUids = array_column($prompts, 'uid');
                    foreach ($assignedAll as $uid) {
                        if (in_array($uid, $existingUids, true)) {
                            $hasPrompts = true;
                            break;
                        }
                    }
                }
                if (!$hasPrompts) {
                    foreach ($prompts as $prompt) {
                        $flag = $fieldType === 'PlainText' ? 'allPlainText' : 'allCKEditor';
                        if (in_array($prompt[$flag] ?? null, ['1', 1, true], true)) {
                            $hasPrompts = true;
                            break;
                        }
                    }
                }
                if (!$hasPrompts) {
                    return;
                }

                // Append a wand button to the field's HTML
                $matrixAttr = $matrixHandle ? ' data-matrix-field="' . $matrixHandle . '"' : '';
                $event->html .= '<div class="ai-wand-wrapper"><button type="button" class="ai-wand-btn btn small icon" data-icon="wand-magic-sparkles" data-field="' . $fieldHandle . '" data-label="' . htmlspecialchars($fieldLabel, ENT_QUOTES) . '" data-type="' . $fieldType . '"' . $matrixAttr . '></button></div>';
                }
            }
        );
    }
}
