<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Resource\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260206130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user.otp_challenges table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user".otp_challenges (id UUID NOT NULL, email VARCHAR(255) NOT NULL, code_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, attempts INT NOT NULL DEFAULT 0, last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sent_count INT NOT NULL DEFAULT 1, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ip_hash VARCHAR(255) DEFAULT NULL, user_agent_hash VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_OTP_CHALLENGES_EMAIL ON "user".otp_challenges (email)');
        $this->addSql('CREATE INDEX IDX_OTP_CHALLENGES_EXPIRES_AT ON "user".otp_challenges (expires_at)');
        $this->addSql('CREATE INDEX IDX_OTP_CHALLENGES_EMAIL_LAST_SENT ON "user".otp_challenges (email, last_sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE "user".otp_challenges');
    }
}
