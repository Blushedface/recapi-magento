<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Product cards (name, link, picture, price in the visitor's currency) for
 * the recommendation row: the products recapi recommends that carry no
 * metadata, and the store's own suggestions when recapi has none.
 */
class Cards
{
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly CatalogConfig $catalogConfig,
        private readonly Visibility $visibility,
        private readonly Image $images,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    /**
     * The cards of those products a shopper can find and buy in this store
     * view, in the order asked: recapi learns products from events and
     * doesn't know which ones were disabled or sold out.
     *
     * @param int[] $ids
     * @return list<array{id: string, name: string, url: string, picture: string, price: string}>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $found = $this->load($this->collection()->addIdFilter($ids));
        return array_values(array_filter(array_map(static fn ($id) => $found[$id] ?? null, $ids)));
    }

    /**
     * The row while recapi has nothing to show (keys not set yet, recapi
     * unreachable): the product's related products and up-sells, the
     * merchant's own picks, then other products of its categories. The
     * full-page cache keeps these with the page, the same for every visitor.
     *
     * @return list<array{id: string, name: string, url: string, picture: string, price: string}>
     */
    public function fallback(int $productId, int $count): array
    {
        try {
            $product = $this->products->getById($productId);
        } catch (NoSuchEntityException) {
            return [];
        }
        $picks = array_map('intval', array_merge($product->getRelatedProductIds(), $product->getUpSellProductIds()));
        $cards = $this->forIds(array_diff($picks, [$productId]));
        $categories = array_map('intval', $product->getCategoryIds());
        if (count($cards) < $count && $categories !== []) {
            $seen = array_merge([$productId], array_map(static fn ($card) => (int) $card['id'], $cards));
            $more = $this->collection()
                ->addCategoriesFilter(['in' => $categories])
                ->addIdFilter($seen, true)
                ->setPageSize(2 * $count);
            $cards = array_merge($cards, array_values($this->load($more)));
        }
        return array_slice($cards, 0, $count);
    }

    private function collection(): object
    {
        // As Magento's own product lists select them.
        return $this->collections->create()
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite()
            ->addStoreFilter()
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addAttributeToFilter('status', Status::STATUS_ENABLED);
    }

    /** @return array<int, array{id: string, name: string, url: string, picture: string, price: string}> */
    private function load(object $collection): array
    {
        $cards = [];
        foreach ($collection as $product) {
            if (!$product->isSalable()) {
                continue;
            }
            // The final price is in the visitor's currency, taxes as the
            // store displays them; a configurable product's is its lowest.
            $price = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $cards[(int) $product->getId()] = [
                'id' => (string) $product->getId(),
                'name' => (string) $product->getName(),
                'url' => (string) $product->getProductUrl(),
                'picture' => (string) $this->images->init($product, 'related_products_list')->getUrl(),
                'price' => (string) $this->priceCurrency->format($price, false),
            ];
        }
        return $cards;
    }
}
