<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Topic suggestions from readers (search page)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE topic_suggestions (id INT UNSIGNED AUTO_INCREMENT NOT NULL, topic VARCHAR(160) NOT NULL, details LONGTEXT DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', handled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_topic_suggestions_created (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE topic_suggestions');
    }
}
