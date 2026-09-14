<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Override;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;

/**
 * Who changed a prompt, and why.
 *
 * The override timeline used to record (tenant, prompt, version, system,
 * created_at) and nothing else. For a person editing prompts occasionally that
 * is survivable; for anything driven by other people's requests it is not —
 * six months on, the row explains what the prompt says and nothing about why it
 * says it, which is the only question anybody asks of it.
 */
final class PromptOverrideAttributionTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        CoroutineLocal::resetCliStore();

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
        $this->orm->getAdapter()->execute(
            'CREATE TABLE prompt_override_history (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                prompt_id TEXT NOT NULL,
                version INTEGER NOT NULL,
                system TEXT NOT NULL,
                author TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
    }

    private function store(): PromptOverrideStore
    {
        return new PromptOverrideStore()->withOrmManager($this->orm);
    }

    #[Test]
    public function a_save_records_who_made_it_and_why(): void
    {
        $store = $this->store();
        $store->set('social.topic', 'New body.', 'an administrator', 'the posts read as spam');

        $history = $store->history('social.topic');

        self::assertCount(1, $history);
        self::assertSame('an administrator', $history[0]['author']);
        self::assertSame('the posts read as spam', $history[0]['reason']);
    }

    #[Test]
    public function an_unattributed_save_still_works(): void
    {
        // Every existing caller passes neither. An unattributed row is worse
        // than an attributed one and far better than a save that stopped
        // compiling, so the fields default to empty rather than being required.
        $store = $this->store();
        $store->set('social.topic', 'New body.');

        $history = $store->history('social.topic');

        self::assertSame('', $history[0]['author']);
        self::assertSame('', $history[0]['reason']);
    }

    #[Test]
    public function a_revert_is_recorded_as_todays_decision_not_the_original_authors(): void
    {
        // Copying the original's author forward would credit them with a
        // decision somebody else made today.
        $store = $this->store();
        $store->set('social.topic', 'First body.', 'the first administrator', 'initial');
        $store->set('social.topic', 'Second body.', 'the second administrator', 'a change of mind');

        self::assertTrue($store->revert('social.topic', 1, 'the third administrator'));

        $history = $store->history('social.topic');
        self::assertSame(3, $history[0]['version']);
        self::assertSame('First body.', $history[0]['system']);
        self::assertSame('the third administrator', $history[0]['author']);
        self::assertSame('Restored version 1', $history[0]['reason']);
    }
}
