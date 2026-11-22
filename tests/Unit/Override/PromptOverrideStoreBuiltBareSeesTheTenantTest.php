<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Override;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;

/**
 * The drift advisory builds this store with `new PromptOverrideStore()`: no
 * tenant store is injected. It answered 'default' then without asking
 * anything, so every tenant read and replaced one tenant's prompt bodies. It
 * must ask core's store, which reads the slot the active tenant store writes.
 */
final class PromptOverrideStoreBuiltBareSeesTheTenantTest extends TestCase
{
    /**
     * Written out, not taken from core's DefaultTenantContextStore::CONTEXT_KEY:
     * it is the slot every released semitexa/tenancy writes, and the
     * constant is private in cores before semitexa-core#258.
     */
    private const TENANCY_KEY = 'semitexa.tenancy.tenant_context';

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

    #[Test]
    public function outside_a_coroutine_one_tenant_never_reads_anothers_override(): void
    {
        // Both slots are process-wide out here: whatever another fixture left
        // in them is put back, not wiped.
        $memoKey = self::memoKey();
        $previous = CoroutineLocal::get(self::TENANCY_KEY);
        $previousMemo = CoroutineLocal::get($memoKey);
        CoroutineLocal::remove($memoKey);
        try {
            self::activate('tenant-acme');
            $this->bareStore()->set('social.topic', 'Acme rule.');
            $acmeHistory = $this->bareStore()->history('social.topic');
            self::assertCount(1, $acmeHistory, 'Acme\'s save is in its own timeline: the reader below is not one that always answers [].');
            self::assertSame('Acme rule.', $acmeHistory[0]['system']);

            self::activate('tenant-globex');
            self::assertNull($this->bareStore()->override('social.topic'), 'Globex gets none of Acme\'s override.');
            self::assertSame([], $this->bareStore()->history('social.topic'), 'Nor its timeline.');
            $this->bareStore()->set('social.topic', 'Globex rule.');

            self::activate('tenant-acme');
            self::assertSame('Acme rule.', $this->bareStore()->override('social.topic'), 'Globex\'s save left Acme\'s body alone.');
        } finally {
            self::putBack(self::TENANCY_KEY, $previous);
            self::putBack($memoKey, $previousMemo);
        }

        self::assertSame(
            ['tenant-acme' => 'Acme rule.', 'tenant-globex' => 'Globex rule.'],
            $this->bodiesByTenant(),
        );
    }

    #[Test]
    public function two_tenants_in_two_coroutines_each_write_under_their_own_id(): void
    {
        $hooks = \Swoole\Runtime::getHookFlags();
        try {
            \Swoole\Coroutine\run(function (): void {
                foreach (['tenant-acme' => 'Acme rule.', 'tenant-globex' => 'Globex rule.'] as $tenantId => $body) {
                    \Swoole\Coroutine::create(function () use ($tenantId, $body): void {
                        // What semitexa/tenancy's store does on set().
                        self::activate($tenantId);
                        // Yield, so the other tenant's coroutine runs in between.
                        \Swoole\Coroutine::sleep(0.005);
                        $this->bareStore()->set('social.topic', $body);
                    });
                }
            });
        } finally {
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        self::assertSame(
            ['tenant-acme' => 'Acme rule.', 'tenant-globex' => 'Globex rule.'],
            $this->bodiesByTenant(),
        );
    }

    private function bareStore(): PromptOverrideStore
    {
        // No tenant store is handed over: that is the case under test.
        return new PromptOverrideStore()->withOrmManager($this->orm);
    }

    /** @return array<string, string> */
    private function bodiesByTenant(): array
    {
        $rows = $this->orm->getAdapter()
            ->execute('SELECT tenant_id, system AS body FROM prompt_override ORDER BY tenant_id')
            ->fetchAll();

        $byTenant = [];
        foreach ($rows as $row) {
            self::assertIsString($row['tenant_id']);
            self::assertIsString($row['body']);
            $byTenant[$row['tenant_id']] = $row['body'];
        }

        return $byTenant;
    }

    private static function memoKey(): string
    {
        $key = new \ReflectionClassConstant(PromptOverrideStore::class, 'MEMO_KEY')->getValue();
        self::assertIsString($key);

        return $key;
    }

    /** Puts a slot back as it was found: removed only if it was absent. */
    private static function putBack(string $key, mixed $previous): void
    {
        if ($previous === null) {
            CoroutineLocal::remove($key);
        } else {
            CoroutineLocal::set($key, $previous);
        }
    }

    private static function activate(string $tenantId): void
    {
        CoroutineLocal::set(self::TENANCY_KEY, new class ($tenantId) implements TenantContextInterface {
            public function __construct(private readonly string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        });
    }
}
