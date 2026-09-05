<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;
use Semitexa\Prompt\Domain\Enum\OverrideDrift;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manage per-tenant prompt overrides (the DB layer over the code catalog). The
 * current tenant is resolved from context — in a plain CLI run that is the
 * 'default' tenant.
 */
#[AsCommand(name: 'prompt:override', description: 'Set, list or remove per-tenant prompt overrides.')]
final class PromptOverrideCommand extends Command
{
    #[InjectAsReadonly]
    protected PromptOverrideStore $store;

    protected function configure(): void
    {
        $this
            ->setName('prompt:override')
            ->setDescription('Set, list or remove per-tenant prompt overrides.')
            ->addArgument('action', InputArgument::REQUIRED, 'set | list | remove | history | revert')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Prompt id (for set/remove/history/revert)')
            ->addOption('system', null, InputOption::VALUE_REQUIRED, 'Override system text (for set)')
            ->addOption('rev', null, InputOption::VALUE_REQUIRED, 'Version number to restore (for revert)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (list/history)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        return match ($action) {
            'set' => $this->set($input, $io),
            'remove' => $this->remove($input, $io),
            'list' => $this->list($input, $output, $io),
            'history' => $this->history($input, $output, $io),
            'revert' => $this->revert($input, $io),
            default => $this->invalid($io, $action),
        };
    }

    private function history(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $id = $input->getOption('id');
        if (!is_string($id) || $id === '') {
            $io->error('history requires --id=<prompt-id>.');

            return Command::INVALID;
        }

        $versions = $this->store->history($id);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['id' => $id, 'versions' => $versions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Override history: %s', $id));
        if ($versions === []) {
            $io->text('No override versions for the current tenant.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($versions as $v) {
            $preview = strtok($v['system'], "\n") ?: '';
            if (mb_strlen($preview) > 70) {
                $preview = mb_substr($preview, 0, 70) . '…';
            }
            $rows[] = ['v' . $v['version'], $v['created_at'], $preview];
        }
        $io->table(['Version', 'Saved at', 'System (first line)'], $rows);

        return Command::SUCCESS;
    }

    private function revert(InputInterface $input, SymfonyStyle $io): int
    {
        $id = $input->getOption('id');
        $version = $input->getOption('rev');
        if (!is_string($id) || $id === '' || !is_string($version) || !ctype_digit($version)) {
            $io->error('revert requires --id=<prompt-id> and --rev=<n>.');

            return Command::INVALID;
        }

        if ($this->store->revert($id, (int) $version)) {
            $io->success(sprintf('Restored "%s" to version %s (as a new version).', $id, $version));

            return Command::SUCCESS;
        }

        $io->error(sprintf('No version %s found for "%s".', $version, $id));

        return Command::FAILURE;
    }

    private function set(InputInterface $input, SymfonyStyle $io): int
    {
        $id = $input->getOption('id');
        $system = $input->getOption('system');
        if (!is_string($id) || $id === '' || !is_string($system) || $system === '') {
            $io->error('set requires --id=<prompt-id> and --system="<text>".');

            return Command::INVALID;
        }

        $this->store->set($id, $system);
        $io->success(sprintf('Override set for "%s".', $id));

        return Command::SUCCESS;
    }

    private function remove(InputInterface $input, SymfonyStyle $io): int
    {
        $id = $input->getOption('id');
        if (!is_string($id) || $id === '') {
            $io->error('remove requires --id=<prompt-id>.');

            return Command::INVALID;
        }

        $this->store->remove($id);
        $io->success(sprintf('Override removed for "%s" (if it existed).', $id));

        return Command::SUCCESS;
    }

    /**
     * Lists the overrides with the one fact the row alone never told anyone:
     * whether the framework has rewritten the prompt underneath it.
     */
    private function list(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        try {
            $overrides = $this->store->status();
        } catch (\Throwable $e) {
            // Before this listing grew a drift column it read the memoized
            // render path, which treats a missing prompt_override table as
            // "no overrides". That is right for a render and wrong here: an
            // install that has not run orm:sync would be told it has none.
            $io->error(sprintf('Could not read the overrides: %s. Has orm:sync run on this install?', $e->getMessage()));

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $payload = [];
            foreach ($overrides as $id => $entry) {
                $payload[$id] = ['drift' => $entry['drift']->value] + $entry;
            }
            $output->writeln((string) json_encode(['overrides' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->title('Prompt Overrides (current tenant)');
        if ($overrides === []) {
            $io->text('No overrides for the current tenant.');

            return Command::SUCCESS;
        }

        $rows = [];
        $stale = 0;
        foreach ($overrides as $id => $entry) {
            $preview = strtok($entry['system'], "\n") ?: '';
            if (mb_strlen($preview) > 60) {
                $preview = mb_substr($preview, 0, 60) . '…';
            }
            if ($entry['drift'] === OverrideDrift::ShippedChanged) {
                ++$stale;
            }
            $rows[] = [$id, $entry['drift']->label(), $preview];
        }
        $io->table(['Prompt id', 'Drift', 'System (first line)'], $rows);

        if ($stale > 0) {
            $io->warning(sprintf(
                '%d override(s) were written against a shipped prompt that has changed since. Compare with `prompt:show --id=<id>` and re-apply what you want to keep, or `prompt:override remove --id=<id>` to follow the shipped text again.',
                $stale,
            ));
        }

        return Command::SUCCESS;
    }

    private function invalid(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use set | list | remove | history | revert.', $action));

        return Command::INVALID;
    }
}
