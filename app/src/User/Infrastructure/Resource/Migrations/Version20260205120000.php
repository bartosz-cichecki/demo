<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Resource\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260205120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user.users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA "user"');
        $this->addSql('CREATE TABLE "user".users (id UUID NOT NULL, email VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, blocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, blocked_reason VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_EMAIL ON "user".users (email)');
        $this->addSql('CREATE INDEX IDX_USER_STATUS ON "user".users (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE "user".users');
        $this->addSql('DROP SCHEMA "user"');
    }
}
