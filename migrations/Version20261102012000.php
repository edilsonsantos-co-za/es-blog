<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102012000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Claim X shares before sending: status, reviewed text hash, error, nullable remote id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE x_post_share ADD status VARCHAR(16) NOT NULL, ADD text_hash VARCHAR(64) NOT NULL, ADD error VARCHAR(255) DEFAULT NULL, CHANGE x_post_id x_post_id VARCHAR(64) DEFAULT NULL');
        // Rows from the previous schema always held a remote id, so they were sent.
        $this->addSql("UPDATE x_post_share SET status = 'sent' WHERE status = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM x_post_share WHERE x_post_id IS NULL");
        $this->addSql('ALTER TABLE x_post_share DROP status, DROP text_hash, DROP error, CHANGE x_post_id x_post_id VARCHAR(64) NOT NULL');
    }
}
