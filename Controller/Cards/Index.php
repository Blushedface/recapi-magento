<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Controller\Cards;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Recapi\Recommendations\Model\Cards;

/**
 * GET /recapi/cards?ids=12,7,31: the cards of recommended products that came
 * without metadata, all in one request. Public, as the product pages are.
 */
class Index implements HttpGetActionInterface
{
    // The row asks for 4; a few more for themes that show a longer row.
    public const MAX_IDS = 20;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $json,
        private readonly Cards $cards,
    ) {
    }

    public function execute(): ResultInterface
    {
        $ids = array_filter(explode(',', (string) $this->request->getParam('ids')), static fn ($id) => preg_match('/^[1-9][0-9]{0,8}$/', $id) === 1);
        $cards = $this->cards->forIds(array_map('intval', array_slice(array_values($ids), 0, self::MAX_IDS)));
        return $this->json->create()->setData(['cards' => $cards]);
    }
}
