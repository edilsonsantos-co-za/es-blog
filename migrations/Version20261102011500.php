<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102011500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-author X OAuth integrations and one-time post sharing history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE x_integration (id INT AUTO_INCREMENT NOT NULL, app_user_id INT UNSIGNED NOT NULL, x_user_id VARCHAR(64) NOT NULL, username VARCHAR(255) DEFAULT NULL, access_token LONGTEXT NOT NULL, refresh_token LONGTEXT DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_X_INTEGRATION_USER (app_user_id), UNIQUE INDEX UNIQ_X_INTEGRATION_ACCOUNT (x_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE x_post_share (id INT AUTO_INCREMENT NOT NULL, post_id INT UNSIGNED NOT NULL, integration_id INT DEFAULT NULL, x_post_id VARCHAR(64) NOT NULL, shared_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_X_POST_SHARE_POST (post_id), UNIQUE INDEX UNIQ_X_POST_SHARE_REMOTE (x_post_id), INDEX IDX_X_POST_SHARE_INTEGRATION (integration_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE x_integration ADD CONSTRAINT FK_X_INTEGRATION_USER FOREIGN KEY (app_user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE x_post_share ADD CONSTRAINT FK_X_POST_SHARE_POST FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE x_post_share ADD CONSTRAINT FK_X_POST_SHARE_INTEGRATION FOREIGN KEY (integration_id) REFERENCES x_integration (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE x_post_share DROP FOREIGN KEY FK_X_POST_SHARE_POST');
        $this->addSql('ALTER TABLE x_post_share DROP FOREIGN KEY FK_X_POST_SHARE_INTEGRATION');
        $this->addSql('ALTER TABLE x_integration DROP FOREIGN KEY FK_X_INTEGRATION_USER');
        $this->addSql('DROP TABLE x_post_share');
        $this->addSql('DROP TABLE x_integration');
    }
}
