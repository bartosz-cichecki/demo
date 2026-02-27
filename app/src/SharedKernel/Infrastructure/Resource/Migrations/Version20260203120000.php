<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Resource\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260203120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shared.event_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS shared');
        $this->addSql('CREATE TABLE shared.event_log (id BIGSERIAL NOT NULL, event_id UUID NOT NULL, event_name VARCHAR(255) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, payload JSONB NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHARED_EVENT_LOG_EVENT_ID ON shared.event_log (event_id)');
        $this->addSql('CREATE INDEX IDX_SHARED_EVENT_LOG_OCCURRED_AT ON shared.event_log (occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared.event_log');
    }
}
