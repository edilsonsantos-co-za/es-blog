<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sessions move from files in the container to the database (PdoSessionHandler)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL PRIMARY KEY, sess_data LONGBLOB NOT NULL, sess_lifetime INTEGER UNSIGNED NOT NULL, sess_time INTEGER UNSIGNED NOT NULL, INDEX sess_lifetime_idx (sess_lifetime)) COLLATE utf8mb4_bin, ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
