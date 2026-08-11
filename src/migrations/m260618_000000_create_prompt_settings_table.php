<?php

namespace stubr\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use stubr\services\PromptSettings;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

class m260618_000000_create_prompt_settings_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->getTableSchema(PromptSettings::TABLE, true) === null) {
            $this->createTable(PromptSettings::TABLE, [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'value' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, PromptSettings::TABLE, ['name'], true);
        }

        $this->seedLegacySettings();

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->getTableSchema(PromptSettings::TABLE, true) !== null) {
            $this->dropTable(PromptSettings::TABLE);
        }

        return true;
    }

    private function seedLegacySettings(): void
    {
        $legacySettings = Craft::$app->getProjectConfig()->get('plugins.craft-loki.settings') ?? [];
        $legacySettings = is_array($legacySettings) ? ProjectConfigHelper::unpackAssociativeArrays($legacySettings) : [];

        $values = [
            'prompts' => $this->arrayValue($legacySettings['prompts'] ?? null, PromptSettings::defaultPrompts()),
            'fieldAssignments' => $this->arrayValue($legacySettings['fieldAssignments'] ?? null),
            'fieldOrder' => $this->arrayValue($legacySettings['fieldOrder'] ?? null),
        ];

        foreach ($values as $name => $value) {
            $exists = (new Query())
                ->from(PromptSettings::TABLE)
                ->where(['name' => $name])
                ->exists();

            if (!$exists) {
                $this->insert(PromptSettings::TABLE, [
                    'name' => $name,
                    'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    'uid' => StringHelper::UUID(),
                ]);
            }
        }
    }

    private function arrayValue(mixed $value, array $default = []): array
    {
        return is_array($value) ? $value : $default;
    }
}
