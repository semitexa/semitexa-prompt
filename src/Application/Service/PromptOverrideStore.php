<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptOverrideHistoryResource;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptOverrideResource;
use Semitexa\Prompt\Domain\Contract\PromptOverrideProviderInterface;
use Semitexa\Prompt\Domain\Enum\OverrideDrift;

/**
 * DB-backed per-tenant prompt overrides — the live-editable layer over the
 * code-shipped catalog. Modeled on the locale TranslationOverrideStore.
 *
 * The lookup can sit on a render path, so per-call queries are avoided: the
 * CURRENT tenant's overrides are loaded ONCE and memoized coroutine-locally per
 * request, keyed by tenant. One indexed query per request that touches
 * overrides, fresh each request (no cross-request staleness), tenant-isolated.
 * A tenant with no overrides memoizes an empty map — still one cheap query.
 */
#[AsService]
#[SatisfiesServiceContract(of: PromptOverrideProviderInterface::class)]
final class PromptOverrideStore implements PromptOverrideProviderInterface
{
    private const MEMO_KEY = 'prompt.override.memo';

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    private ?DomainRepository $repository = null;

    private ?DomainRepository $historyRepository = null;

    /** @var array<string, true> tenants already logged as failed this worker (avoid per-request log spam). */
    private static array $loggedFailures = [];

    /** Test seam — production path uses property injection. */
    public function withOrmManager(OrmManager $orm): self
    {
        $this->orm = $orm;
        $this->repository = null;

        return $this;
    }

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

    public function override(string $promptId): ?string
    {
        return $this->overridesFor()[$promptId] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->overridesFor();
    }

    /**
     * Set (or replace) one tenant override — the admin/live-edit write path.
     * Tenant-stamped; invalidates the per-request memo.
     */
    public function set(string $promptId, string $system): void
    {
        $tenant = $this->currentTenantId();
        $existing = $this->findRow($promptId);
        $baseHash = $this->shippedHash($promptId);

        $row = new PromptOverrideResource(
            id: $existing?->id ?? Uuid7::generate(),
            tenant_id: $tenant,
            prompt_id: $promptId,
            system: $system,
            base_hash: $baseHash,
            updated_at: new \DateTimeImmutable(),
        );

        if ($existing === null) {
            try {
                $this->scoped()->insert($row);
            } catch (\Throwable $e) {
                // Possibly a lost concurrent first-write race on the unique
                // (tenant, prompt_id) index — the row exists now; re-fetch its
                // id and update instead of failing.
                $winner = $this->findRow($promptId);
                if ($winner === null) {
                    // No winner ⇒ NOT the duplicate-key race and nothing was
                    // persisted. Surface the real failure — an admin write must
                    // never report success on a lost write.
                    throw $e;
                }
                $this->scoped()->update(new PromptOverrideResource(
                    id: $winner->id,
                    tenant_id: $tenant,
                    prompt_id: $promptId,
                    system: $system,
                    base_hash: $baseHash,
                    updated_at: new \DateTimeImmutable(),
                ));
            }
        } else {
            $this->scoped()->update($row);
        }

        $this->recordHistory($tenant, $promptId, $system);
        $this->forgetMemo($tenant);
    }

    /**
     * Every override of the current tenant with a verdict on whether it has
     * gone stale — the admin/CLI read path, never the render path.
     *
     * An override wins over the catalog forever, so a tenant who customised a
     * prompt keeps their copy even after the framework rewrites the shipped
     * one. That is the point of an override; the problem is that nothing used
     * to say it had happened. {@see OverrideDrift} is the verdict.
     *
     * Deliberately its own query: {@see overridesFor()} is memoized on a render
     * path and returns only the text it needs there.
     *
     * Also deliberately NOT wrapped in the best-effort catch that path uses. A
     * render must survive a missing table by falling back to the catalog; a
     * diagnostic that answered "no overrides" when it simply could not read
     * them would be worse than useless. The caller reports the failure.
     *
     * @return array<string, array{system: string, drift: OverrideDrift, updated_at: string}>
     *
     * @throws \Throwable when the overrides cannot be read at all
     */
    public function status(): array
    {
        $catalog = new PromptRegistry();
        $out = [];

        /** @var list<PromptOverrideResource> $rows */
        $rows = $this->scoped()->query()
            ->fetchAllAs(PromptOverrideResource::class, $this->orm()->getMapperRegistry());

        foreach ($rows as $row) {
            $out[$row->prompt_id] = [
                'system' => $row->system,
                'drift' => OverrideDrift::classify($row->base_hash, $catalog->tryGet($row->prompt_id)?->system),
                'updated_at' => $row->updated_at->format(\DateTimeInterface::ATOM),
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * The fingerprint of what the CODE catalog ships for this prompt right now.
     *
     * Reads {@see PromptRegistry} directly rather than the container's
     * {@see \Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface}: the
     * bound implementation is the layered one, which would hand back the
     * override being written and stamp the row with a hash of itself.
     */
    private function shippedHash(string $promptId): ?string
    {
        $shipped = (new PromptRegistry())->tryGet($promptId);

        return $shipped === null ? null : OverrideDrift::fingerprint($shipped->system);
    }

    /**
     * The append-only version timeline for a prompt (newest first).
     *
     * @return list<array{version: int, system: string, created_at: string}>
     */
    public function history(string $promptId): array
    {
        $rows = $this->historyRows($promptId);
        usort($rows, static fn(PromptOverrideHistoryResource $a, PromptOverrideHistoryResource $b): int => $b->version <=> $a->version);

        return array_map(static fn(PromptOverrideHistoryResource $r): array => [
            'version' => $r->version,
            'system' => $r->system,
            'created_at' => $r->created_at->format(\DateTimeInterface::ATOM),
        ], $rows);
    }

    /**
     * Restore a prior version: re-applies its body as a NEW override version
     * (the history stays append-only). Returns false if the version is unknown.
     */
    public function revert(string $promptId, int $version): bool
    {
        foreach ($this->historyRows($promptId) as $row) {
            if ($row->version === $version) {
                $this->set($promptId, $row->system);

                return true;
            }
        }

        return false;
    }

    /**
     * Append a version row for a save. Best-effort: a history-log failure must
     * not fail the save itself (the current override is already written).
     */
    private function recordHistory(string $tenant, string $promptId, string $system): void
    {
        try {
            $next = 1;
            foreach ($this->historyRows($promptId) as $row) {
                if ($row->version >= $next) {
                    $next = $row->version + 1;
                }
            }

            $this->historyScoped()->insert(new PromptOverrideHistoryResource(
                id: Uuid7::generate(),
                tenant_id: $tenant,
                prompt_id: $promptId,
                version: $next,
                system: $system,
                created_at: new \DateTimeImmutable(),
            ));
        } catch (\Throwable $e) {
            if (!isset(self::$loggedFailures['history:' . $tenant])) {
                self::$loggedFailures['history:' . $tenant] = true;
                FallbackErrorLogger::log('Prompt override history unavailable; the override was saved but not versioned', [
                    'tenant' => $tenant,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<PromptOverrideHistoryResource>
     */
    private function historyRows(string $promptId): array
    {
        /** @var list<PromptOverrideHistoryResource> $rows */
        $rows = $this->historyScoped()->query()
            ->where(PromptOverrideHistoryResource::column('prompt_id'), Operator::Equals, $promptId)
            ->fetchAllAs(PromptOverrideHistoryResource::class, $this->orm()->getMapperRegistry());

        return $rows;
    }

    private function historyScoped(): DomainRepository
    {
        return $this->historyRepository()->forTenant($this->currentTenantId());
    }

    private function historyRepository(): DomainRepository
    {
        return $this->historyRepository ??= $this->orm()->repository(
            PromptOverrideHistoryResource::class,
            PromptOverrideHistoryResource::class,
        );
    }

    /** Remove one tenant override (falls back to the catalog again). */
    public function remove(string $promptId): void
    {
        $existing = $this->findRow($promptId);
        if ($existing !== null) {
            $this->scoped()->delete($existing);
            $this->forgetMemo($this->currentTenantId());
        }
    }

    private function findRow(string $promptId): ?PromptOverrideResource
    {
        /** @var PromptOverrideResource|null $row */
        $row = $this->scoped()->query()
            ->where(PromptOverrideResource::column('prompt_id'), Operator::Equals, $promptId)
            ->fetchOneAs(PromptOverrideResource::class, $this->orm()->getMapperRegistry());

        return $row;
    }

    /**
     * The current tenant's override map (prompt id => system text), memoized per
     * request.
     *
     * @return array<string, string>
     */
    private function overridesFor(): array
    {
        $tenant = $this->currentTenantId();

        /** @var array<string, array<string, string>> $memo */
        $memo = CoroutineLocal::get(self::MEMO_KEY, []);
        if (isset($memo[$tenant])) {
            return $memo[$tenant];
        }

        $map = [];
        try {
            /** @var list<PromptOverrideResource> $rows */
            $rows = $this->scoped()->query()
                ->fetchAllAs(PromptOverrideResource::class, $this->orm()->getMapperRegistry());
            foreach ($rows as $row) {
                $map[$row->prompt_id] = $row->system;
            }
        } catch (\Throwable $e) {
            // No table yet / DB hiccup: overrides are a best-effort enhancement
            // over the always-present catalog — never break prompt resolution.
            // A genuine misconfiguration would otherwise silently disable
            // overrides with no trace, so log ONCE per tenant per worker.
            if (!isset(self::$loggedFailures[$tenant])) {
                self::$loggedFailures[$tenant] = true;
                FallbackErrorLogger::log('Tenant prompt overrides unavailable; falling back to the catalog', [
                    'tenant' => $tenant,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
            $map = [];
        }

        $memo[$tenant] = $map;
        CoroutineLocal::set(self::MEMO_KEY, $memo);

        return $map;
    }

    private function forgetMemo(string $tenant): void
    {
        /** @var array<string, array<string, string>> $memo */
        $memo = CoroutineLocal::get(self::MEMO_KEY, []);
        unset($memo[$tenant]);
        CoroutineLocal::set(self::MEMO_KEY, $memo);
    }

    private function scoped(): DomainRepository
    {
        return $this->repository()->forTenant($this->currentTenantId());
    }

    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            PromptOverrideResource::class,
            PromptOverrideResource::class,
        );
    }

    private function orm(): OrmManager
    {
        if (!isset($this->orm)) {
            $this->orm = new OrmManager();
        }

        return $this->orm;
    }
}
