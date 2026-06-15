<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260615213148 extends AbstractMigration
{
    private const INDEX_NAME = 'UNIQ_1483A5E9E7927C74';

    public function getDescription(): string
    {
        return 'Add a unique index on nullable users.email after checking for duplicate non-empty emails';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $indexes = $schemaManager->listTableIndexes('users');

        foreach ($indexes as $index) {
            if ($index->isUnique() && $index->getColumns() === ['email']) {
                return;
            }
        }

        $duplicateEmails = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT email
            FROM users
            WHERE email IS NOT NULL AND email <> ''
            GROUP BY email
            HAVING COUNT(*) > 1
            ORDER BY email
            LIMIT 10
        SQL);

        if ($duplicateEmails !== []) {
            throw new RuntimeException(sprintf(
                'Cannot add unique index %s on users.email because duplicate non-null/non-empty email values exist: %s. Resolve the duplicates before running this migration.',
                self::INDEX_NAME,
                implode(', ', array_map(static fn (mixed $email): string => (string) $email, $duplicateEmails)),
            ));
        }

        $emptyEmailCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM users WHERE email = ''");
        if ($emptyEmailCount > 1) {
            throw new RuntimeException(sprintf(
                'Cannot add unique index %s on users.email because %d rows contain an empty-string email. Convert empty emails to NULL before running this migration.',
                self::INDEX_NAME,
                $emptyEmailCount,
            ));
        }

        $this->addSql(sprintf('ALTER TABLE users ADD UNIQUE KEY %s (email)', $this->connection->quoteIdentifier(self::INDEX_NAME)));
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $indexes = $schemaManager->listTableIndexes('users');

        foreach ($indexes as $index) {
            if (!$index->isPrimary() && $index->isUnique() && $index->getColumns() === ['email']) {
                $this->addSql(sprintf('ALTER TABLE users DROP KEY %s', $this->connection->quoteIdentifier($index->getName())));

                return;
            }
        }
    }
}
