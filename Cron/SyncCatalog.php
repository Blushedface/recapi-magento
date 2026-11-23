<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Cron;

use Psr\Log\LoggerInterface;
use Recapi\Recommendations\Model\Catalog;

/** The nightly full sync: mass updates, imports and stock changes save no product one by one. */
class SyncCatalog
{
    public function __construct(private readonly Catalog $catalog, private readonly LoggerInterface $logger)
    {
    }

    public function execute(): void
    {
        try {
            $this->catalog->sendAll();
        } catch (\Throwable $error) {
            $this->logger->error('recapi: catalog sync failed: ' . $error->getMessage());
        }
    }
}
