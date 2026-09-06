<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Update;

use Semitexa\Prompt\Application\Service\PromptOverrideStore;
use Semitexa\Prompt\Domain\Enum\OverrideDrift;
use Semitexa\Update\Attribute\AsUpdateAdvisory;
use Semitexa\Update\Domain\Contract\UpdateAdvisoryInterface;
use Semitexa\Update\Domain\Model\Advisory\UpdateAdvisory;

/**
 * Tells the operator which prompt overrides the update just left behind.
 *
 * A tenant override wins over the shipped prompt forever — that is what it is
 * for. The cost is that it is also a frozen copy, so an update that improves a
 * prompt leaves that tenant on the old wording and nothing says so.
 * `prompt:override list` has reported this for a while, but only when somebody
 * runs it, and nobody runs a read command on the off-chance. The moment it
 * matters is the update that caused it, which is here.
 *
 * Reads across tenants deliberately: the update runs on the CLI under the
 * 'default' tenant, so a scoped read would report a clean install to a
 * multi-tenant operator whose other tenants had all drifted.
 */
#[AsUpdateAdvisory(name: 'override-drift', module: 'semitexa/prompt')]
final class PromptOverrideDriftAdvisory implements UpdateAdvisoryInterface
{
    private ?PromptOverrideStore $store = null;

    public function advise(): ?UpdateAdvisory
    {
        $id = 'semitexa/prompt:override-drift';
        $title = 'Prompt overrides';

        // Not caught here. The contract says an advisory reports rather than
        // throws, and the discovery turns an escaped throwable into an advisory
        // that says the check could not run — which is the honest answer, and
        // the one thing this must never do is disappear quietly and let the
        // operator read the silence as "no overrides have drifted".
        $rows = $this->store()->statusAcrossTenants();

        if ($rows === []) {
            return null; // nothing overridden on this install; nothing to say
        }

        $stale = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['drift'] !== OverrideDrift::Current,
        ));

        if ($stale === []) {
            return UpdateAdvisory::clean($id, $title, sprintf(
                'All %d override(s) still match the text they were written against.',
                count($rows),
            ));
        }

        $lines = [];
        foreach ($stale as $row) {
            $lines[] = sprintf(
                '%s / %s — %s',
                $row['tenant'],
                $row['prompt'],
                $row['drift']->label(),
            );
        }
        $lines[] = 'Review with: bin/semitexa prompt:override list';

        return UpdateAdvisory::actionable($id, sprintf(
            '%s: %d of %d override(s) no longer match the shipped text',
            $title,
            count($stale),
            count($rows),
        ), $lines);
    }

    /**
     * Test seam, the same shape the stores use. The advisory contract requires
     * a parameterless constructor, so the store cannot be a constructor
     * argument — and a check nothing can point at a fixture is a check nobody
     * writes a test for.
     */
    public function withStore(PromptOverrideStore $store): self
    {
        $this->store = $store;

        return $this;
    }

    private function store(): PromptOverrideStore
    {
        return $this->store ??= new PromptOverrideStore();
    }
}
