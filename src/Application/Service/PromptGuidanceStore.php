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
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Prompt\Application\Db\MySQL\Model\PromptGuidanceResource;
use Semitexa\Prompt\Domain\Contract\PromptGuidanceProviderInterface;
use Semitexa\Prompt\Domain\Model\PromptGuidance;

/**
 * DB-backed per-tenant prompt guidance — the additive layer beside the catalog.
 *
 * Reads sit on a render path, so the CURRENT tenant's guidance is loaded ONCE
 * and memoized coroutine-locally per request, exactly as
 * {@see PromptOverrideStore} does: one indexed query per request that renders a
 * prompt, fresh each request, tenant-isolated.
 *
 * Read failures are swallowed, deliberately and loudly-once. Guidance is an
 * enhancement over an always-present catalog body; a missing table must degrade
 * to "no guidance", never take down every prompt render in the application. The
 * write path does NOT swallow — an operator told to record something must not
 * be told it worked when it did not.
 */
#[AsService]
#[SatisfiesServiceContract(of: PromptGuidanceProviderInterface::class)]
final class PromptGuidanceStore implements PromptGuidanceProviderInterface
{
    use OrmBackedStore;

    private const MEMO_KEY = 'prompt.guidance.memo';

    /** How many times an add() re-reads the sequence after losing the position race. */
    private const POSITION_ATTEMPTS = 3;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /** @var array<string, true> tenants already logged as failed this worker (avoid per-request log spam). */
    private static array $loggedFailures = [];

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

    /**
     * @return list<PromptGuidance>
     */
    public function guidanceFor(string $promptId, ?string $scope = null): array
    {
        $rows = [];
        foreach ($this->rowsFor($promptId) as $row) {
            if (!$row->isEnabled()) {
                continue;
            }
            $rowScope = $row->getScope();
            // Unscoped rows always apply; a scoped row only when the caller
            // named that scope. Scoped guidance ADDS to the general guidance —
            // replacing it would make a page-level note silently drop a
            // tenant-wide rule somebody is relying on.
            if ($rowScope !== null && $rowScope !== $scope) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function textFor(string $promptId, ?string $scope = null): string
    {
        $lines = array_map(
            static fn (PromptGuidance $g): string => '- ' . trim($g->getBody()),
            $this->guidanceFor($promptId, $scope),
        );

        return implode("\n", $lines);
    }

    /**
     * Append one piece of guidance. Nothing is rewritten and nothing is
     * replaced, which is the entire difference from an override.
     */
    public function add(
        string $promptId,
        string $body,
        string $author,
        string $reason = '',
        ?string $scope = null,
    ): PromptGuidance {
        $tenant = $this->currentTenantId();

        // The position is read-then-written, so two concurrent adds for the same
        // (tenant, prompt) can reach for the same slot. A unique index makes the
        // loser's insert fail rather than letting both land — and the order then
        // stays the order they were given in, which is the one thing this column
        // exists to guarantee. Bounded: a retry that never settles is a defect to
        // surface, not to spin on.
        $lastError = null;
        for ($attempt = 0; $attempt < self::POSITION_ATTEMPTS; $attempt++) {
            $row = new PromptGuidance(
                id: Uuid7::generate(),
                tenantId: $tenant,
                promptId: $promptId,
                body: $body,
                author: $author,
                position: $this->nextPosition($promptId),
                reason: $reason,
                scope: $scope,
                enabled: true,
                createdAt: new \DateTimeImmutable(),
            );

            try {
                $this->scoped()->insert($row);
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->forgetMemo($tenant);
                continue;
            }

            $this->forgetMemo($tenant);

            return $row;
        }

        throw new \RuntimeException(
            sprintf('Could not allocate a guidance position for "%s" after %d attempts.', $promptId, self::POSITION_ATTEMPTS),
            0,
            $lastError,
        );
    }

    /**
     * Retire (or restore) ONE row without touching its neighbours — the thing an
     * override cannot do, where undoing one sentence means reverting a whole
     * version. Returns false when this tenant has no such row.
     */
    public function setEnabled(string $id, bool $enabled): bool
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return false;
        }

        $this->scoped()->update($row->withEnabled($enabled));
        $this->forgetMemo($this->currentTenantId());

        return true;
    }

    /**
     * Delete a row outright. Prefer {@see setEnabled()}: a disabled row still
     * answers "why does this prompt behave like that", and a deleted one does
     * not. This exists for guidance recorded in error.
     */
    public function remove(string $id): bool
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return false;
        }

        $this->scoped()->delete($row);
        $this->forgetMemo($this->currentTenantId());

        return true;
    }

    /**
     * Every guidance row this tenant holds, enabled or not, oldest first —
     * the admin/CLI read path.
     *
     * Deliberately NOT wrapped in the read path's best-effort catch: a render
     * survives a missing table by showing no guidance, but a listing that
     * answered "none" when it simply could not read them would be worse than an
     * error. The caller reports the failure.
     *
     * @return list<PromptGuidance>
     *
     * @throws \Throwable when the guidance cannot be read at all
     */
    public function listAll(?string $promptId = null): array
    {
        $query = $this->scoped()->query();
        if ($promptId !== null) {
            $query = $query->where(PromptGuidanceResource::column('prompt_id'), Operator::Equals, $promptId);
        }

        /** @var list<PromptGuidance> $rows */
        $rows = $query->fetchAllAs(PromptGuidance::class, $this->mapperRegistry());

        return self::inGivenOrder($rows);
    }

    /**
     * This tenant's rows for one prompt, memoized per request.
     *
     * @return list<PromptGuidance>
     */
    private function rowsFor(string $promptId): array
    {
        $tenant = $this->currentTenantId();

        /** @var array<string, array<string, list<PromptGuidance>>> $memo */
        $memo = CoroutineLocal::get(self::MEMO_KEY, []);
        if (isset($memo[$tenant])) {
            return $memo[$tenant][$promptId] ?? [];
        }

        $byPrompt = [];
        try {
            /** @var list<PromptGuidance> $rows */
            $rows = $this->scoped()->query()
                ->fetchAllAs(PromptGuidance::class, $this->mapperRegistry());
            foreach (self::inGivenOrder($rows) as $row) {
                $byPrompt[$row->getPromptId()][] = $row;
            }
        } catch (\Throwable $e) {
            // No table yet / DB hiccup. Never break prompt resolution over an
            // additive layer — but a genuine misconfiguration would otherwise
            // disable guidance with no trace, so log ONCE per tenant per worker.
            if (!isset(self::$loggedFailures[$tenant])) {
                self::$loggedFailures[$tenant] = true;
                FallbackErrorLogger::log('Tenant prompt guidance unavailable; rendering without it', [
                    'tenant' => $tenant,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
            $byPrompt = [];
        }

        $memo[$tenant] = $byPrompt;
        CoroutineLocal::set(self::MEMO_KEY, $memo);

        return $byPrompt[$promptId] ?? [];
    }

    /**
     * The next slot in this prompt's sequence for this tenant.
     *
     * Computed from the rows rather than taken from the clock: `created_at` is
     * second-granular, so two additions in the same second tied and fell back to
     * a UUID comparison that is not insertion order — measured as a flaky test
     * before it could be measured as a wrong prompt. Same approach the override
     * history takes for `version`.
     */
    private function nextPosition(string $promptId): int
    {
        $next = 1;
        foreach ($this->listAll($promptId) as $row) {
            if ($row->getPosition() >= $next) {
                $next = $row->getPosition() + 1;
            }
        }

        return $next;
    }

    /**
     * Grouped by prompt, then oldest first within each — the order the guidance
     * was given in, which is the order it reads in. The id breaks a tie only for
     * rows written before positions existed, or by a concurrent pair that
     * computed the same slot.
     *
     * Position alone is not enough to sort by: it is monotonic PER PROMPT, so an
     * unfiltered listing sorted on it put every prompt's first row together,
     * then every prompt's second, and read as scrambled the moment two prompts
     * had guidance.
     *
     * @param list<PromptGuidance> $rows
     * @return list<PromptGuidance>
     */
    private static function inGivenOrder(array $rows): array
    {
        usort(
            $rows,
            static fn (PromptGuidance $a, PromptGuidance $b): int
                => [$a->getPromptId(), $a->getPosition(), $a->getId()]
                <=> [$b->getPromptId(), $b->getPosition(), $b->getId()],
        );

        return $rows;
    }

    private function findRow(string $id): ?PromptGuidance
    {
        /** @var PromptGuidance|null $row */
        $row = $this->scoped()->query()
            ->where(PromptGuidanceResource::column('id'), Operator::Equals, $id)
            ->fetchOneAs(PromptGuidance::class, $this->mapperRegistry());

        return $row;
    }

    private function forgetMemo(string $tenant): void
    {
        /** @var array<string, array<string, list<PromptGuidance>>> $memo */
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
        return $this->domainRepository(PromptGuidanceResource::class, PromptGuidance::class);
    }
}
