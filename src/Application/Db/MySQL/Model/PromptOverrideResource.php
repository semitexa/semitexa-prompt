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
 * One tenant's override of a single prompt's system text.
 *
 * Tenant-scoped (same_storage): the ORM gate filters every read by the ambient
 * tenant, so one tenant can neither read nor overwrite another's overrides for
 * the identical prompt id. The code-shipped catalog ({@see \Semitexa\Prompt\Application\Service\PromptRegistry})
 * is the fallback for any prompt not overridden here.
 */
#[FromTable(name: 'prompt_override')]
#[Index(columns: ['tenant_id', 'prompt_id'], unique: true, name: 'uniq_prompt_override_scope')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class PromptOverrideResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        /** Owning tenant; the ORM gate filters every query by this. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        #[Column(type: MySqlType::Varchar, length: 191)]
        public string $prompt_id,

        #[Column(type: MySqlType::LongText)]
        public string $system,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {}
}
