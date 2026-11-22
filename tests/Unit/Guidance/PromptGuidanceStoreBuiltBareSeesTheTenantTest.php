<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Tests\Unit\Guidance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Prompt\Application\Service\PromptGuidanceStore;

/**
 * The renderer builds this store with `new PromptGuidanceStore()`: no tenant
 * store is injected. It answered 'default' then without asking anything, so
 * one tenant's guidance was rendered into every tenant's prompts. It must ask
 * core's store, which reads the slot the active tenant store writes.
 */
final class PromptGuidanceStoreBuiltBareSeesTheTenantTest extends TestCase
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
                created_at TEXT NOT NULL,
                UNIQUE (tenant_id, prompt_id, position)
            )',
        );
    }

    #[Test]
    public function outside_a_coroutine_one_tenant_never_reads_anothers_guidance(): void
    {
        // Both slots are process-wide out here: whatever another fixture left
        // in them is put back, not wiped.
        $memoKey = self::memoKey();
        $previous = CoroutineLocal::get(self::TENANCY_KEY);
        $previousMemo = CoroutineLocal::get($memoKey);
        CoroutineLocal::remove($memoKey);
        try {
            self::activate('tenant-acme');
            $acme = $this->bareStore()->add('social.topic', 'Acme rule.', 'an administrator');

            self::activate('tenant-globex');
            self::assertSame('', $this->bareStore()->textFor('social.topic'), 'Globex renders none of Acme\'s guidance.');
            self::assertFalse($this->bareStore()->remove($acme->getId()), 'Globex cannot remove Acme\'s row.');
            $this->bareStore()->add('social.topic', 'Globex rule.', 'an administrator');

            self::activate('tenant-acme');
            self::assertSame('- Acme rule.', $this->bareStore()->textFor('social.topic'), 'Acme renders only its own.');
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
                        $this->bareStore()->add('social.topic', $body, 'an administrator');
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

    private function bareStore(): PromptGuidanceStore
    {
        // No tenant store is handed over: that is the case under test.
        return new PromptGuidanceStore()->withOrmManager($this->orm);
    }

    /** @return array<string, string> */
    private function bodiesByTenant(): array
    {
        $rows = $this->orm->getAdapter()
            ->execute('SELECT tenant_id, body AS body FROM prompt_guidance ORDER BY tenant_id')
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
        $key = new \ReflectionClassConstant(PromptGuidanceStore::class, 'MEMO_KEY')->getValue();
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
