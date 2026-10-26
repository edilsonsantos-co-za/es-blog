<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026211939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feed automation tables (sources, items, runs) and LinkedIn share columns on posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE automation_runs (id INT UNSIGNED AUTO_INCREMENT NOT NULL, trigger_source VARCHAR(16) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, feeds_read INT DEFAULT 0 NOT NULL, items_added INT DEFAULT 0 NOT NULL, posts_created INT DEFAULT 0 NOT NULL, log JSON NOT NULL, success TINYINT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE feed_items (id INT UNSIGNED AUTO_INCREMENT NOT NULL, fingerprint VARCHAR(40) NOT NULL, link VARCHAR(1024) NOT NULL, title VARCHAR(500) NOT NULL, summary LONGTEXT DEFAULT NULL, published_at DATETIME DEFAULT NULL, score INT DEFAULT 0 NOT NULL, status VARCHAR(16) DEFAULT \'new\' NOT NULL, error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, source_id INT UNSIGNED NOT NULL, post_id INT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_1491BAF1FC0B754A (fingerprint), INDEX IDX_1491BAF1953C1C61 (source_id), INDEX IDX_1491BAF14B89032C (post_id), INDEX idx_feed_items_status (status, score), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE feed_sources (id INT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(768) NOT NULL, folder VARCHAR(120) DEFAULT NULL, enabled TINYINT DEFAULT 1 NOT NULL, last_fetched_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_684F7187F47645AE (url), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE feed_items ADD CONSTRAINT FK_1491BAF1953C1C61 FOREIGN KEY (source_id) REFERENCES feed_sources (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE feed_items ADD CONSTRAINT FK_1491BAF14B89032C FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE posts ADD source_url VARCHAR(1024) DEFAULT NULL, ADD linkedin_post_urn VARCHAR(255) DEFAULT NULL, ADD linkedin_shared_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed_items DROP FOREIGN KEY FK_1491BAF1953C1C61');
        $this->addSql('ALTER TABLE feed_items DROP FOREIGN KEY FK_1491BAF14B89032C');
        $this->addSql('DROP TABLE automation_runs');
        $this->addSql('DROP TABLE feed_items');
        $this->addSql('DROP TABLE feed_sources');
        $this->addSql('ALTER TABLE posts DROP source_url, DROP linkedin_post_urn, DROP linkedin_shared_at');
    }
}
