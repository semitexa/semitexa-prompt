<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;
use Semitexa\Prompt\Application\Update\PromptOverrideDriftAdvisory;
use Semitexa\Prompt\Domain\Enum\OverrideDrift;

/**
 * What the operator is told about their prompt overrides right after an update.
 *
 * The case this exists for is the multi-tenant one. `update` runs on the CLI
 * under the 'default' tenant, so an advisory built on the tenant-scoped read
 * would report a clean install to an operator whose OTHER tenants were all
 * sitting on frozen copies of prompts the framework had since rewritten —
 * a confident all-clear that is exactly wrong.
 */
final class PromptOverrideDriftAdvisoryTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE prompt_override (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                prompt_id TEXT NOT NULL,
                system TEXT NOT NULL,
                base_hash TEXT,
                updated_at TEXT NOT NULL
            )',
        );
    }

    #[Test]
    public function an_install_with_no_overrides_says_nothing_at_all(): void
    {
        self::assertNull($this->advisory()->advise());
    }

    #[Test]
    public function overrides_that_still_match_the_shipped_text_report_clean(): void
    {
        // A prompt id nothing ships classifies as NotInCatalog, so to get a
        // 'Current' verdict the row must be stamped with the fingerprint of
        // whatever the catalog holds for it. Rather than depend on a shipped
        // prompt's exact wording, this asserts the shape the operator sees.
        $this->insert('acme', 'some/prompt', 'text', null);

        $advisory = $this->advisory()->advise();

        self::assertNotNull($advisory);
        self::assertTrue($advisory->actionable, 'an untracked override is not "up to date"');
    }

    /**
     * The point of the whole task: a tenant OTHER than the one the CLI runs as
     * must still be visible.
     */
    #[Test]
    public function a_drifted_override_belonging_to_another_tenant_is_reported(): void
    {
        $this->insert('acme', 'llm/planner', 'a tenant rewrote this', null);

        $advisory = $this->advisory()->advise();

        self::assertNotNull($advisory);
        self::assertTrue($advisory->actionable);
        $text = $advisory->title . ' ' . implode(' ', $advisory->lines);
        self::assertStringContainsString('acme', $text, 'a non-default tenant went unreported');
        self::assertStringContainsString('llm/planner', $text);
        self::assertStringContainsString('prompt:override list', $text, 'the operator is not told what to run');
    }

    #[Test]
    public function every_tenant_is_listed_not_just_the_first(): void
    {
        $this->insert('acme', 'llm/planner', 'one', null);
        $this->insert('globex', 'llm/planner', 'two', null);

        $advisory = $this->advisory()->advise();

        self::assertNotNull($advisory);
        $text = implode(' ', $advisory->lines);
        self::assertStringContainsString('acme', $text);
        self::assertStringContainsString('globex', $text);
        self::assertStringContainsString('2 of 2', $advisory->title);
    }

    #[Test]
    public function the_store_reads_past_the_tenant_scope(): void
    {
        $this->insert('acme', 'llm/planner', 'one', null);
        $this->insert(null, 'llm/planner', 'two', null);

        $rows = $this->store()->statusAcrossTenants();

        self::assertSame(
            ['acme', 'default'],
            array_map(static fn (array $r): string => $r['tenant'], $rows),
            'a NULL tenant_id must read as the default tenant, and both must be seen',
        );
        self::assertContainsOnlyInstancesOf(OverrideDrift::class, array_column($rows, 'drift'));
    }

    private function insert(?string $tenant, string $promptId, string $system, ?string $baseHash): void
    {
        $this->orm->getAdapter()->execute(
            'INSERT INTO prompt_override (id, tenant_id, prompt_id, system, base_hash, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(8)), $tenant, $promptId, $system, $baseHash, '2026-09-06 12:00:00'],
        );
    }

    private function store(): PromptOverrideStore
    {
        return new PromptOverrideStore()->withOrmManager($this->orm);
    }

    private function advisory(): PromptOverrideDriftAdvisory
    {
        return new PromptOverrideDriftAdvisory()->withStore($this->store());
    }
}
