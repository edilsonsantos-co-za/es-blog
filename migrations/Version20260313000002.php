<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aligns linkedin_integration table with v2 entity mapping.
 * The table was pre-created by v1 with different column names.
 */
final class Version20260313000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align linkedin_integration columns with v2 entity (rename user_id→linkedin_user_id, add app_user_id FK)';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('linkedin_integration');
        $indexes = $schemaManager->listTableIndexes('linkedin_integration');
        $foreignKeys = $schemaManager->listTableForeignKeys('linkedin_integration');

        if (isset($columns['user_id']) && !isset($columns['linkedin_user_id'])) {
            $this->addSql('ALTER TABLE linkedin_integration CHANGE user_id linkedin_user_id VARCHAR(255) NOT NULL');
            $columns['linkedin_user_id'] = $columns['user_id'];
            unset($columns['user_id']);
        }

        if (!isset($columns['app_user_id'])) {
            $this->addSql('ALTER TABLE linkedin_integration ADD app_user_id INT UNSIGNED NULL AFTER linkedin_user_id');
            $this->addSql('UPDATE linkedin_integration SET app_user_id = 1 WHERE app_user_id IS NULL');
            $this->addSql('ALTER TABLE linkedin_integration MODIFY app_user_id INT UNSIGNED NOT NULL');
        }

        $hasAppUserForeignKey = false;
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->getLocalColumns() === ['app_user_id']) {
                $hasAppUserForeignKey = true;
                break;
            }
        }

        if (!$hasAppUserForeignKey) {
            $this->addSql('ALTER TABLE linkedin_integration ADD CONSTRAINT fk_li_app_user FOREIGN KEY (app_user_id) REFERENCES users (id) ON DELETE CASCADE');
        }

        $hasAppUserUniqueIndex = false;
        foreach ($indexes as $index) {
            if ($index->isUnique() && $index->getColumns() === ['app_user_id']) {
                $hasAppUserUniqueIndex = true;
                break;
            }
        }

        if (!$hasAppUserUniqueIndex) {
            $this->addSql('ALTER TABLE linkedin_integration ADD UNIQUE KEY uk_app_user (app_user_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('linkedin_integration');
        $indexes = $schemaManager->listTableIndexes('linkedin_integration');
        $foreignKeys = $schemaManager->listTableForeignKeys('linkedin_integration');

        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->getLocalColumns() === ['app_user_id']) {
                $this->addSql(sprintf('ALTER TABLE linkedin_integration DROP FOREIGN KEY %s', $this->connection->quoteIdentifier($foreignKey->getName())));
            }
        }

        foreach ($indexes as $index) {
            if (!$index->isPrimary() && $index->isUnique() && $index->getColumns() === ['app_user_id']) {
                $this->addSql(sprintf('ALTER TABLE linkedin_integration DROP KEY %s', $this->connection->quoteIdentifier($index->getName())));
            }
        }

        if (isset($columns['app_user_id'])) {
            $this->addSql('ALTER TABLE linkedin_integration DROP COLUMN app_user_id');
        }

        if (isset($columns['linkedin_user_id']) && !isset($columns['user_id'])) {
            $this->addSql('ALTER TABLE linkedin_integration CHANGE linkedin_user_id user_id VARCHAR(255) NOT NULL');
        }
    }
}
