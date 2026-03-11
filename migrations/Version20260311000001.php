<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema — creates all tables for edfolio-blog-v2.
 *
 * This migration is the single source of truth for the database structure.
 * It is intentionally non-destructive: every CREATE TABLE uses IF NOT EXISTS
 * so that running this against an existing edfolio_blog database is safe.
 */
final class Version20260311000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, categories, tags, posts, post_tags, menu_items, custom_fields, config, cache, linkedin_integration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `users` (
                `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `username`    VARCHAR(100)     NOT NULL,
                `password`    VARCHAR(255)     NOT NULL,
                `email`       VARCHAR(255)     DEFAULT NULL,
                `role`        ENUM('admin','editor','author') NOT NULL DEFAULT 'author',
                `encryption`  VARCHAR(20)      NOT NULL DEFAULT 'sha512',
                `mfa_secret`  VARCHAR(255)     NOT NULL DEFAULT 'disabled',
                `about`       TEXT             DEFAULT NULL,
                `avatar`      VARCHAR(255)     DEFAULT NULL,
                `facebook`    VARCHAR(255)     DEFAULT NULL,
                `twitter`     VARCHAR(255)     DEFAULT NULL,
                `instagram`   VARCHAR(255)     DEFAULT NULL,
                `bluesky`     VARCHAR(255)     DEFAULT NULL,
                `linkedin`    VARCHAR(255)     DEFAULT NULL,
                `github`      VARCHAR(255)     DEFAULT NULL,
                `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `UNIQ_1483A5E9F85E0677` (`username`),
                KEY `idx_role` (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `categories` (
                `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `slug`        VARCHAR(255)     NOT NULL,
                `title`       VARCHAR(255)     NOT NULL,
                `description` TEXT             DEFAULT NULL,
                `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `UNIQ_3AF34668989D9B62` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `tags` (
                `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `slug`        VARCHAR(255)     NOT NULL,
                `name`        VARCHAR(255)     NOT NULL,
                `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `UNIQ_6FBC9426989D9B62` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `posts` (
                `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `author_id`     INT UNSIGNED     NOT NULL,
                `category_id`   INT UNSIGNED     DEFAULT NULL,
                `slug`          VARCHAR(255)     NOT NULL,
                `title`         VARCHAR(255)     NOT NULL,
                `body`          LONGTEXT         NOT NULL,
                `description`   TEXT             DEFAULT NULL,
                `type`          ENUM('post','page','subpage','profile') NOT NULL DEFAULT 'post',
                `status`        ENUM('published','draft','scheduled')   NOT NULL DEFAULT 'published',
                `image`         VARCHAR(255)     DEFAULT NULL,
                `video`         VARCHAR(255)     DEFAULT NULL,
                `audio`         VARCHAR(255)     DEFAULT NULL,
                `link`          VARCHAR(255)     DEFAULT NULL,
                `quote`         TEXT             DEFAULT NULL,
                `views`         INT UNSIGNED     NOT NULL DEFAULT 0,
                `published_at`  DATETIME         DEFAULT NULL,
                `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `UNIQ_885DBAFA989D9B62` (`slug`),
                KEY `idx_author`    (`author_id`),
                KEY `idx_category`  (`category_id`),
                KEY `idx_type`      (`type`),
                KEY `idx_status`    (`status`),
                KEY `idx_published` (`published_at`),
                KEY `idx_created`   (`created_at`),
                FULLTEXT KEY `idx_search` (`title`, `body`, `description`),
                CONSTRAINT `FK_885DBAFA1A3D9F45` FOREIGN KEY (`author_id`)
                    REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `FK_885DBAFA12469DE2` FOREIGN KEY (`category_id`)
                    REFERENCES `categories` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `post_tags` (
                `post_id` INT UNSIGNED NOT NULL,
                `tag_id`  INT UNSIGNED NOT NULL,
                PRIMARY KEY (`post_id`, `tag_id`),
                KEY `idx_tag` (`tag_id`),
                CONSTRAINT `FK_D5EABF745A8A6C8D` FOREIGN KEY (`post_id`)
                    REFERENCES `posts` (`id`) ON DELETE CASCADE,
                CONSTRAINT `FK_D5EABF74BAD26311` FOREIGN KEY (`tag_id`)
                    REFERENCES `tags` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `menu_items` (
                `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `parent_id`  INT UNSIGNED     DEFAULT NULL,
                `name`       VARCHAR(255)     NOT NULL,
                `slug`       VARCHAR(255)     NOT NULL,
                `class`      VARCHAR(100)     DEFAULT NULL,
                `target`     VARCHAR(20)      DEFAULT NULL,
                `position`   INT UNSIGNED     NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_parent`   (`parent_id`),
                KEY `idx_position` (`position`),
                CONSTRAINT `FK_D754D550727ACA70` FOREIGN KEY (`parent_id`)
                    REFERENCES `menu_items` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `custom_fields` (
                `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `post_id`     INT UNSIGNED     NOT NULL,
                `field_key`   VARCHAR(255)     NOT NULL,
                `field_value` TEXT             DEFAULT NULL,
                `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_post` (`post_id`),
                KEY `idx_key`  (`field_key`),
                CONSTRAINT `FK_8A4FEAE14B89032C` FOREIGN KEY (`post_id`)
                    REFERENCES `posts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `config` (
                `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `config_key`   VARCHAR(255)     NOT NULL,
                `config_value` TEXT             DEFAULT NULL,
                `created_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `UNIQ_D48A2F7C3EC4ACE` (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `cache` (
                `cache_key`   VARCHAR(255)     NOT NULL,
                `cache_value` LONGTEXT         DEFAULT NULL,
                `expires_at`  DATETIME         DEFAULT NULL,
                `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`cache_key`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `linkedin_integration` (
                `id`                INT              NOT NULL AUTO_INCREMENT,
                `app_user_id`       INT UNSIGNED     NOT NULL,
                `linkedin_user_id`  VARCHAR(255)     NOT NULL,
                `access_token`      TEXT             DEFAULT NULL,
                `token_expires_at`  DATETIME         DEFAULT NULL,
                `user_urn`          VARCHAR(255)     DEFAULT NULL,
                `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `UNIQ_LI_APP_USER`     (`app_user_id`),
                UNIQUE KEY `UNIQ_LI_USER_ID`      (`linkedin_user_id`),
                CONSTRAINT `FK_LI_APP_USER` FOREIGN KEY (`app_user_id`)
                    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('DROP TABLE IF EXISTS `linkedin_integration`');
        $this->addSql('DROP TABLE IF EXISTS `cache`');
        $this->addSql('DROP TABLE IF EXISTS `config`');
        $this->addSql('DROP TABLE IF EXISTS `custom_fields`');
        $this->addSql('DROP TABLE IF EXISTS `menu_items`');
        $this->addSql('DROP TABLE IF EXISTS `post_tags`');
        $this->addSql('DROP TABLE IF EXISTS `posts`');
        $this->addSql('DROP TABLE IF EXISTS `tags`');
        $this->addSql('DROP TABLE IF EXISTS `categories`');
        $this->addSql('DROP TABLE IF EXISTS `users`');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
