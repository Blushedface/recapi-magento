<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Recapi\Recommendations\Model\Catalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento recapi:sync-catalog`: the whole catalog to recapi now, as the
 * nightly cron job sends it (after an import, say). di.xml passes a proxy:
 * every bin/magento command builds this one, and only this one needs the
 * catalog.
 */
class SyncCatalog extends Command
{
    public function __construct(private readonly State $state, private readonly Catalog $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('recapi:sync-catalog')
            ->setDescription("Send every product of the default store view to recapi: name, link, picture, price, categories, and whether it can be bought");
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // The CLI has no area: product links and images need the storefront's.
            $sent = $this->state->emulateAreaCode(Area::AREA_FRONTEND, [$this->catalog, 'sendAll']);
        } catch (\Throwable $error) {
            $output->writeln('<error>recapi: ' . $error->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('recapi stored %d products.', $sent));
        return Command::SUCCESS;
    }
}
