<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Double opt-in newsletter consent, review-first campaigns and delivery ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE newsletter_subscribers (id INT UNSIGNED AUTO_INCREMENT NOT NULL, email VARCHAR(254) NOT NULL, posts TINYINT(1) NOT NULL, products TINYINT(1) NOT NULL, confirmation_hash VARCHAR(64) DEFAULT NULL, confirmation_expires_at DATETIME DEFAULT NULL, unsubscribe_hash VARCHAR(64) NOT NULL, confirmed_at DATETIME DEFAULT NULL, consent_updated_at DATETIME DEFAULT NULL, unsubscribed_at DATETIME DEFAULT NULL, requested_at DATETIME NOT NULL, consent_version VARCHAR(32) NOT NULL, UNIQUE INDEX uniq_newsletter_email (email), UNIQUE INDEX uniq_newsletter_unsubscribe (unsubscribe_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE newsletter_campaigns (id INT UNSIGNED AUTO_INCREMENT NOT NULL, post_id INT UNSIGNED DEFAULT NULL, kind VARCHAR(16) NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, queued_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_newsletter_post (post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE newsletter_deliveries (id INT UNSIGNED AUTO_INCREMENT NOT NULL, campaign_id INT UNSIGNED NOT NULL, subscriber_id INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, sent_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_newsletter_delivery (campaign_id, subscriber_id), INDEX IDX_NEWSLETTER_SUBSCRIBER (subscriber_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE newsletter_campaigns ADD CONSTRAINT FK_NEWSLETTER_POST FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_deliveries ADD CONSTRAINT FK_NEWSLETTER_CAMPAIGN FOREIGN KEY (campaign_id) REFERENCES newsletter_campaigns (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_deliveries ADD CONSTRAINT FK_NEWSLETTER_SUBSCRIBER FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE newsletter_deliveries');
        $this->addSql('DROP TABLE newsletter_campaigns');
        $this->addSql('DROP TABLE newsletter_subscribers');
    }
}
