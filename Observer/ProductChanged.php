<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Recapi\Recommendations\Model\Catalog;

/**
 * A product saved or deleted, wherever (admin, REST, a script): recapi gets
 * it once the response is out, and the save never waits on recapi nor fails
 * because of it.
 */
class ProductChanged implements ObserverInterface
{
    public function __construct(private readonly Catalog $catalog, private readonly LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $this->catalog->queue($event->getProduct(), $event->getName() === 'catalog_product_delete_after');
        } catch (\Throwable $error) {
            $this->logger->error('recapi: product not queued: ' . $error->getMessage());
        }
    }
}
