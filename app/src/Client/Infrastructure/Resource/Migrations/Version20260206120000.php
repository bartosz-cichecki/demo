<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\Resource\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create client.client_memberships table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client.client_memberships (id UUID NOT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, roles_json JSONB NOT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CLIENT_MEMBERSHIP_CLIENT_USER ON client.client_memberships (client_id, user_id)');
        $this->addSql('CREATE INDEX IDX_CLIENT_MEMBERSHIP_CLIENT ON client.client_memberships (client_id)');
        $this->addSql('CREATE INDEX IDX_CLIENT_MEMBERSHIP_USER ON client.client_memberships (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE client.client_memberships');
    }
}
