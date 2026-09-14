<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Prompt\Application\Service\PromptGuidanceStore;
use Semitexa\Prompt\Domain\Model\PromptGuidance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manage the additive guidance attached to a prompt, per tenant.
 *
 * The counterpart to `prompt:override`, and deliberately not part of it: an
 * override REPLACES a body, guidance ACCUMULATES beside one. Retiring a single
 * sentence here is `disable`, which an override cannot express at all — undoing
 * one sentence of a customised prompt means reverting a whole version.
 *
 * The current tenant is resolved from context; in a plain CLI run that is the
 * 'default' tenant.
 */
#[AsCommand(name: 'prompt:guidance', description: 'Add, list, disable or remove per-tenant prompt guidance.')]
final class PromptGuidanceCommand extends Command
{
    #[InjectAsReadonly]
    protected PromptGuidanceStore $store;

    protected function configure(): void
    {
        $this
            ->setName('prompt:guidance')
            ->setDescription('Add, list, disable or remove per-tenant prompt guidance.')
            ->addArgument('action', InputArgument::REQUIRED, 'add | list | disable | enable | remove')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Prompt id (for add; narrows list)')
            ->addOption('row', null, InputOption::VALUE_REQUIRED, 'Guidance row id (for disable/enable/remove)')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'The guidance itself (for add)')
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'Who asked for it (for add)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why, in their words (for add)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Narrower key within the prompt (for add)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        return match ($action) {
            'add' => $this->add($input, $io),
            'list' => $this->list($input, $output, $io),
            'disable' => $this->flip($input, $io, false),
            'enable' => $this->flip($input, $io, true),
            'remove' => $this->remove($input, $io),
            default => $this->invalid($io, $action),
        };
    }

    private function add(InputInterface $input, SymfonyStyle $io): int
    {
        $id = $input->getOption('id');
        $text = $input->getOption('text');
        $author = $input->getOption('author');

        if (!is_string($id) || $id === '' || !is_string($text) || $text === '') {
            $io->error('add requires --id=<prompt-id> and --text="…".');

            return Command::FAILURE;
        }

        // Attribution is required on the way IN, not optional with a default.
        // The whole reason this layer exists is that a prompt's history could
        // not say who asked for something; accepting an anonymous row would
        // rebuild the same gap one convenience at a time.
        if (!is_string($author) || $author === '') {
            $io->error('add requires --author=<who asked for it>.');

            return Command::FAILURE;
        }

        $reason = $input->getOption('reason');
        $scope = $input->getOption('scope');

        $row = $this->store->add(
            promptId: $id,
            body: $text,
            author: $author,
            reason: is_string($reason) ? $reason : '',
            scope: is_string($scope) && $scope !== '' ? $scope : null,
        );

        $io->success(sprintf('Guidance %s added to "%s".', $row->getId(), $id));
        $io->writeln('It reaches the prompt only where the template prints {{ guidance }}.');

        return Command::SUCCESS;
    }

    private function list(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $id = $input->getOption('id');
        $rows = $this->store->listAll(is_string($id) && $id !== '' ? $id : null);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(
                array_map(static fn (PromptGuidance $g): array => [
                    'row' => $g->getId(),
                    'prompt' => $g->getPromptId(),
                    'scope' => $g->getScope(),
                    'text' => $g->getBody(),
                    'author' => $g->getAuthor(),
                    'reason' => $g->getReason(),
                    'enabled' => $g->isEnabled(),
                    'created_at' => $g->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                ], $rows),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $io->writeln('No guidance recorded for this tenant.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Row', 'Prompt', 'Scope', 'On', 'Guidance', 'Author', 'Reason'],
            array_map(static fn (PromptGuidance $g): array => [
                $g->getId(),
                $g->getPromptId(),
                $g->getScope() ?? '—',
                $g->isEnabled() ? 'yes' : 'no',
                $g->getBody(),
                $g->getAuthor(),
                $g->getReason(),
            ], $rows),
        );

        return Command::SUCCESS;
    }

    private function flip(InputInterface $input, SymfonyStyle $io, bool $enabled): int
    {
        $row = $input->getOption('row');
        if (!is_string($row) || $row === '') {
            $io->error(($enabled ? 'enable' : 'disable') . ' requires --row=<guidance-row-id>.');

            return Command::FAILURE;
        }

        if (!$this->store->setEnabled($row, $enabled)) {
            $io->error(sprintf('No guidance row "%s" for this tenant.', $row));

            return Command::FAILURE;
        }

        $io->success(sprintf('Guidance %s is now %s.', $row, $enabled ? 'enabled' : 'disabled'));

        return Command::SUCCESS;
    }

    private function remove(InputInterface $input, SymfonyStyle $io): int
    {
        $row = $input->getOption('row');
        if (!is_string($row) || $row === '') {
            $io->error('remove requires --row=<guidance-row-id>.');

            return Command::FAILURE;
        }

        if (!$this->store->remove($row)) {
            $io->error(sprintf('No guidance row "%s" for this tenant.', $row));

            return Command::FAILURE;
        }

        $io->success(sprintf('Guidance %s deleted.', $row));
        $io->writeln('Prefer "disable" next time: a disabled row still answers why the prompt behaves as it does.');

        return Command::SUCCESS;
    }

    private function invalid(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use add | list | disable | enable | remove.', $action));

        return Command::FAILURE;
    }
}
