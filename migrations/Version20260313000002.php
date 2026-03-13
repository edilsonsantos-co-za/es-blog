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
        // Step 1: rename user_id (the LinkedIn URN string) to linkedin_user_id
        $this->addSql('ALTER TABLE linkedin_integration CHANGE user_id linkedin_user_id VARCHAR(255) NOT NULL');

        // Step 2: add app_user_id as INT UNSIGNED to match users.id (which is INT UNSIGNED)
        $this->addSql('ALTER TABLE linkedin_integration ADD app_user_id INT UNSIGNED NULL AFTER linkedin_user_id');

        // Step 3: default any existing rows to user id=1 (admin) before adding FK
        $this->addSql('UPDATE linkedin_integration SET app_user_id = 1 WHERE app_user_id IS NULL');

        // Step 4: add FK constraint
        $this->addSql('ALTER TABLE linkedin_integration ADD CONSTRAINT fk_li_app_user FOREIGN KEY (app_user_id) REFERENCES users (id) ON DELETE CASCADE');

        // Step 5: make NOT NULL + unique (one LinkedIn connection per app user)
        $this->addSql('ALTER TABLE linkedin_integration MODIFY app_user_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE linkedin_integration ADD UNIQUE KEY uk_app_user (app_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE linkedin_integration DROP FOREIGN KEY fk_li_app_user');
        $this->addSql('ALTER TABLE linkedin_integration DROP KEY uk_app_user');
        $this->addSql('ALTER TABLE linkedin_integration DROP COLUMN app_user_id');
        $this->addSql('ALTER TABLE linkedin_integration CHANGE linkedin_user_id user_id VARCHAR(255) NOT NULL');
    }
}
