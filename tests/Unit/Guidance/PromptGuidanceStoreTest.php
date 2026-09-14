<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Guidance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Orm\OrmManager;
use Semitexa\Prompt\Application\Service\PromptGuidanceStore;
use Semitexa\Prompt\Domain\Model\PromptGuidance;

/**
 * The store behind the guidance layer, against a real (in-memory) database.
 *
 * The rules worth pinning are the ones that distinguish guidance from an
 * override: rows accumulate instead of replacing, they come back in the order
 * they were given, one can be retired without touching its neighbours, and a
 * missing table degrades to silence rather than taking a render down.
 */
final class PromptGuidanceStoreTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        // The store memoizes a tenant's rows coroutine-locally, and in a CLI
        // process that store is process-global — so without this reset a row
        // written by one test is still visible to the next one, which would
        // quietly make the missing-table case pass for the wrong reason.
        CoroutineLocal::resetCliStore();

        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->createTable();
    }

    private function createTable(): void
    {
        $this->orm->getAdapter()->execute(
            'CREATE TABLE prompt_guidance (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                prompt_id TEXT NOT NULL,
                scope TEXT,
                position INTEGER NOT NULL,
                body TEXT NOT NULL,
                author TEXT NOT NULL,
                reason TEXT NOT NULL,
                enabled INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
    }

    private function store(): PromptGuidanceStore
    {
        return new PromptGuidanceStore()->withOrmManager($this->orm);
    }

    #[Test]
    public function guidance_accumulates_instead_of_replacing(): void
    {
        // The defining difference from an override, which holds exactly one row
        // per (tenant, prompt) and rewrites it on every save.
        $store = $this->store();
        $store->add('social.topic', 'Fewer hashtags.', 'an administrator');
        $store->add('social.topic', 'Do not open with the weather.', 'another administrator');

        $rows = $store->guidanceFor('social.topic');

        self::assertCount(2, $rows);
        self::assertSame('Fewer hashtags.', $rows[0]->getBody());
        self::assertSame('Do not open with the weather.', $rows[1]->getBody());
    }

    #[Test]
    public function the_rendered_text_lists_the_rows_in_the_order_they_were_given(): void
    {
        $store = $this->store();
        $store->add('social.topic', 'First.', 'someone');
        $store->add('social.topic', 'Second.', 'someone');

        self::assertSame("- First.\n- Second.", $store->textFor('social.topic'));

        // Position is an explicit, durable fact — not a re-derivation of the
        // clock, which ties for rows added in the same second.
        $rows = $store->guidanceFor('social.topic');
        self::assertSame([1, 2], [$rows[0]->getPosition(), $rows[1]->getPosition()]);
    }

    #[Test]
    public function one_row_can_be_retired_without_touching_its_neighbours(): void
    {
        // What an override cannot do: undoing one sentence of a customised
        // prompt means reverting a whole version.
        $store = $this->store();
        $keep = $store->add('social.topic', 'Fewer hashtags.', 'someone');
        $drop = $store->add('social.topic', 'Open with the weather.', 'someone');

        self::assertTrue($store->setEnabled($drop->getId(), false));

        $rows = $store->guidanceFor('social.topic');
        self::assertCount(1, $rows);
        self::assertSame($keep->getId(), $rows[0]->getId());

        // Retired, not gone: the row still answers why the prompt once behaved
        // that way.
        self::assertCount(2, $store->listAll('social.topic'));
    }

    #[Test]
    public function a_disabled_row_can_be_brought_back(): void
    {
        $store = $this->store();
        $row = $store->add('social.topic', 'Fewer hashtags.', 'someone');

        $store->setEnabled($row->getId(), false);
        self::assertSame([], $store->guidanceFor('social.topic'));

        self::assertTrue($store->setEnabled($row->getId(), true));
        self::assertCount(1, $store->guidanceFor('social.topic'));
    }

    #[Test]
    public function flipping_or_removing_an_unknown_row_reports_failure_rather_than_pretending(): void
    {
        $store = $this->store();

        self::assertFalse($store->setEnabled('no-such-row', false));
        self::assertFalse($store->remove('no-such-row'));
    }

    #[Test]
    public function scoped_guidance_adds_to_the_general_guidance_it_does_not_replace_it(): void
    {
        // Replacing would let a page-level note silently drop a tenant-wide rule
        // somebody is relying on.
        $store = $this->store();
        $store->add('social.topic', 'Everywhere.', 'someone');
        $store->add('social.topic', 'Only on the launch page.', 'someone', scope: 'page:launch');

        self::assertCount(1, $store->guidanceFor('social.topic'));
        self::assertCount(2, $store->guidanceFor('social.topic', 'page:launch'));
        self::assertCount(1, $store->guidanceFor('social.topic', 'page:other'));
    }

    #[Test]
    public function guidance_for_one_prompt_never_leaks_into_another(): void
    {
        $store = $this->store();
        $store->add('social.topic', 'Fewer hashtags.', 'someone');

        self::assertSame([], $store->guidanceFor('cms.seo.write'));
        self::assertSame('', $store->textFor('cms.seo.write'));
    }

    #[Test]
    public function every_row_carries_who_asked_and_why(): void
    {
        // The question anybody actually asks of a customised prompt six months
        // later, and the one the override history could not answer at all.
        $store = $this->store();
        $store->add('social.topic', 'Fewer hashtags.', 'an administrator', 'they said the posts read as spam');

        $row = $store->guidanceFor('social.topic')[0];

        self::assertSame('an administrator', $row->getAuthor());
        self::assertSame('they said the posts read as spam', $row->getReason());
        self::assertInstanceOf(\DateTimeImmutable::class, $row->getCreatedAt());
    }

    #[Test]
    public function a_missing_table_degrades_to_no_guidance_rather_than_failing_a_render(): void
    {
        // Guidance is additive over an always-present catalog body. A read that
        // threw would make an optional layer able to take down every prompt in
        // the application.
        $this->orm->getAdapter()->execute('DROP TABLE prompt_guidance');

        $store = $this->store();

        self::assertSame([], $store->guidanceFor('social.topic'));
        self::assertSame('', $store->textFor('social.topic'));
    }

    #[Test]
    public function listing_refuses_to_answer_none_when_it_cannot_read_at_all(): void
    {
        // The read path's silence is right for a render and wrong for a report:
        // an admin listing that said "no guidance" when the table was missing
        // would be worse than an error.
        $this->orm->getAdapter()->execute('DROP TABLE prompt_guidance');

        $this->expectException(\Throwable::class);

        $this->store()->listAll();
    }

    #[Test]
    public function a_row_deleted_outright_is_gone_from_the_listing_too(): void
    {
        $store = $this->store();
        $row = $store->add('social.topic', 'Recorded by mistake.', 'someone');

        self::assertTrue($store->remove($row->getId()));
        self::assertSame([], $store->listAll());
    }

    #[Test]
    public function the_model_reports_what_it_was_given(): void
    {
        $row = new PromptGuidance(
            id: 'row-1',
            tenantId: 'acme',
            promptId: 'social.topic',
            body: 'Fewer hashtags.',
            author: 'an administrator',
        );

        self::assertTrue($row->isEnabled());
        self::assertNull($row->getScope());
        self::assertSame(0, $row->getPosition());
        self::assertSame('', $row->getReason());
        self::assertFalse($row->withEnabled(false)->isEnabled());
        self::assertTrue($row->isEnabled(), 'withEnabled must not mutate the original');
    }
}
