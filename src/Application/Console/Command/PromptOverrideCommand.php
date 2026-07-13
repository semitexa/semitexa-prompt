<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;
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
            ->addArgument('action', InputArgument::REQUIRED, 'set | list | remove')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Prompt id (for set/remove)')
            ->addOption('system', null, InputOption::VALUE_REQUIRED, 'Override system text (for set)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        return match ($action) {
            'set' => $this->set($input, $io),
            'remove' => $this->remove($input, $io),
            'list' => $this->list($input, $output, $io),
            default => $this->invalid($io, $action),
        };
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

    private function list(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $overrides = $this->store->all();

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['overrides' => $overrides], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Prompt Overrides (current tenant)');
        if ($overrides === []) {
            $io->text('No overrides for the current tenant.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($overrides as $id => $system) {
            $preview = strtok($system, "\n") ?: '';
            if (mb_strlen($preview) > 80) {
                $preview = mb_substr($preview, 0, 80) . '…';
            }
            $rows[] = [$id, $preview];
        }
        $io->table(['Prompt id', 'System (first line)'], $rows);

        return Command::SUCCESS;
    }

    private function invalid(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use set | list | remove.', $action));

        return Command::INVALID;
    }
}
