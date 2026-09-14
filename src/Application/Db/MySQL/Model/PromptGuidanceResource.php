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
 * Append-only operator guidance attached to a prompt, per tenant.
 *
 * Separate from {@see PromptOverrideResource} on purpose: an override REPLACES
 * a prompt body and there is exactly one per (tenant, prompt), while guidance
 * ACCUMULATES beside a body that is never touched. Rows carry who asked and
 * why, and are retired by flipping `enabled` rather than by deletion — the
 * record of what was asked for is the part that answers questions later.
 */
#[FromTable(name: 'prompt_guidance')]
#[Index(columns: ['tenant_id', 'prompt_id'], name: 'idx_prompt_guidance_lookup')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class PromptGuidanceResource
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

        /** A narrower key within the prompt (a page, a room); null applies everywhere. */
        #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $scope,

        /**
         * Monotonic per (tenant, prompt_id) — the order the guidance was given
         * in, which is the order it reads in.
         *
         * An explicit fact rather than one derived from `created_at`: two rows
         * added in the same second share a timestamp, and the resulting order
         * then came down to a UUID tiebreak that is not insertion order. The
         * history table already keeps its sequence this way for the same reason.
         */
        #[Column(type: MySqlType::Int)]
        public int $position,

        #[Column(type: MySqlType::Text)]
        public string $body,

        #[Column(type: MySqlType::Varchar, length: 191)]
        public string $author,

        #[Column(type: MySqlType::Text)]
        public string $reason,

        #[Column(type: MySqlType::Boolean)]
        public bool $enabled,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,
    ) {}
}
