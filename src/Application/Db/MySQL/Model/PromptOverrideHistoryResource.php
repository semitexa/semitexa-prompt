<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * Append-only lineage of a tenant's prompt overrides — one row per save. The
 * current override lives in {@see PromptOverrideResource} (fast one-row read);
 * this table keeps the timeline so an operator can review and restore any prior
 * version. Restoring re-applies an old body as a NEW version, so the log is
 * strictly append-only.
 */
#[FromTable(name: 'prompt_override_history')]
#[Index(columns: ['tenant_id', 'prompt_id', 'version'], unique: true, name: 'uniq_prompt_override_history_version')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class PromptOverrideHistoryResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        #[Column(type: MySqlType::Varchar, length: 191)]
        public string $prompt_id,

        /** Monotonic per (tenant, prompt_id); the current override is the max version. */
        #[Column(type: MySqlType::Int)]
        public int $version,

        #[Column(type: MySqlType::LongText)]
        public string $system,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,
    ) {}
}
