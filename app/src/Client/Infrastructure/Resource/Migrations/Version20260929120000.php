<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\Resource\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create client.client_invitations table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client.client_invitations (id UUID NOT NULL, client_id UUID NOT NULL, email VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        // At most one pending invitation per client and email; concurrent duplicates fail here.
        $this->addSql("CREATE UNIQUE INDEX UNIQ_CLIENT_INVITATION_PENDING_CLIENT_EMAIL ON client.client_invitations (client_id, email) WHERE status = 'pending'");
        $this->addSql('CREATE INDEX IDX_CLIENT_INVITATION_EMAIL ON client.client_invitations (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE client.client_invitations');
    }
}
