<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Resource\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shared.async_outbox and shared.async_consumption tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS shared');
        $this->addSql('CREATE TABLE shared.async_outbox (id BIGSERIAL NOT NULL, event_id UUID NOT NULL, event_name VARCHAR(255) NOT NULL, payload JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claimed_by VARCHAR(255) DEFAULT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, last_error TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHARED_ASYNC_OUTBOX_EVENT_ID ON shared.async_outbox (event_id)');
        $this->addSql('CREATE INDEX IDX_SHARED_ASYNC_OUTBOX_PENDING ON shared.async_outbox (processed_at, claimed_at, id) WHERE processed_at IS NULL');

        $this->addSql('CREATE TABLE shared.async_consumption (id BIGSERIAL NOT NULL, event_id UUID NOT NULL, subscriber VARCHAR(255) NOT NULL, handler_method VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claimed_by VARCHAR(255) DEFAULT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHARED_ASYNC_CONSUMPTION_HANDLER ON shared.async_consumption (event_id, subscriber, handler_method)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared.async_consumption');
        $this->addSql('DROP TABLE shared.async_outbox');
    }
}
