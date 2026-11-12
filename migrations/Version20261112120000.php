<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authors get a display name for readers; the owner account shows as Edilson Santos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD display_name VARCHAR(120) DEFAULT NULL AFTER role');
        $this->addSql("UPDATE users SET display_name = 'Edilson Santos' WHERE username = 'yec.santos'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP display_name');
    }
}
