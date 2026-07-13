<?php

declare(strict_types=1);

namespace Semitexa\Prompt\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Prompt\Domain\Exception\PromptNotFoundException;
use Semitexa\Prompt\Domain\Exception\PromptRenderException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'prompt:render', description: 'Render a prompt with bound variables and print the exact result an LLM would receive.')]
final class PromptRenderCommand extends Command
{
    /**
     * Resolve through the bound repository (the DB-override layer when present)
     * so `prompt:render` shows the EFFECTIVE prompt — the exact text the LLM
     * would receive for the current tenant, overrides applied.
     */
    #[InjectAsReadonly]
    protected PromptRepositoryInterface $repository;

    protected function configure(): void
    {
        $this
            ->setName('prompt:render')
            ->setDescription('Render a prompt with bound variables and print the exact result an LLM would receive.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Prompt id to render')
            ->addOption('var', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Variable binding as name=value (repeatable)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getOption('id');
        if (!is_string($id) || $id === '') {
            $io->error('Provide a prompt id with --id=<id> (see prompt:list).');

            return Command::INVALID;
        }

        /** @var list<string> $rawVars */
        $rawVars = (array) $input->getOption('var');
        $variables = [];
        foreach ($rawVars as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $io->error(sprintf('Invalid --var "%s": expected name=value.', $pair));

                return Command::INVALID;
            }
            $variables[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }

        $renderer = new PromptRenderer();

        try {
            $rendered = $renderer->render($id, $variables, $this->repository);
        } catch (PromptNotFoundException | PromptRenderException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($rendered->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Rendered: %s', $rendered->promptId));
        $io->section('System');
        $io->writeln($rendered->system);

        if ($rendered->messages !== []) {
            $io->section(sprintf('Few-shot (%d message(s))', count($rendered->messages)));
            foreach ($rendered->messages as $message) {
                $io->writeln(sprintf('<info>[%s]</info> %s', $message->role->value, $message->content));
            }
        }

        return Command::SUCCESS;
    }
}
