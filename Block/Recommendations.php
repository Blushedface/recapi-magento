<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Block;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Recapi\Recommendations\Model\Cards;
use Recapi\Recommendations\Model\Config;

/**
 * recapi's row on product pages: the slot the storefront's script fills with
 * recapi's recommendations, printed with the store's own suggestions in it so
 * that it is never empty while recapi has nothing to show. With the layout's
 * `list` argument `bought_together` it is the product page's second row,
 * filled by the same call: printed hidden and empty, it shows only once recapi
 * has products for it, as the store has no bought-together products of its own.
 */
class Recommendations extends Template implements IdentityInterface
{
    public const CARDS = 4;

    /** The second row's `list` argument, and the placement it asks for. */
    public const BOUGHT_TOGETHER = 'bought_together';

    private ?array $fallback = null;

    public function __construct(Context $context, private readonly Config $config, private readonly Cards $cards, array $data = [])
    {
        parent::__construct($context, $data);
    }

    /**
     * The row, or null when it would stay empty: no keys yet and no product
     * of the store's to show. The heading is the layout's `heading` argument.
     *
     * @return array{heading: string, fallback: list<array>, slot: string, hidden: bool}|null
     */
    public function getRow(): ?array
    {
        $live = $this->config->apiUrl() !== '' && $this->config->publicKey() !== '';
        $heading = $this->getData('heading');
        $heading = is_string($heading) && $heading !== '' ? $heading : null;
        if ($this->together()) {
            if (!$live || !$this->config->boughtTogether() || $this->product() === 0) {
                return null;
            }
            return ['heading' => $heading ?? (string) __('Often bought together'), 'fallback' => [], 'slot' => 'recapi-bought-together', 'hidden' => true];
        }
        $fallback = $this->fallback();
        if (!$live && $fallback === []) {
            return null;
        }
        return [
            'heading' => $heading ?? (string) __('You may also like'),
            'fallback' => $fallback,
            'slot' => 'recapi-recommendations',
            'hidden' => false,
        ];
    }

    /**
     * The full-page cache drops the page when a product it shows changes
     * (price, stock, name).
     */
    public function getIdentities(): array
    {
        return array_map(static fn ($card) => Product::CACHE_TAG . '_' . $card['id'], $this->fallback());
    }

    private function together(): bool
    {
        return $this->getData('list') === self::BOUGHT_TOGETHER;
    }

    private function product(): int
    {
        $request = $this->getRequest();
        $product = $request->getFullActionName() === 'catalog_product_view' ? (int) $request->getParam('id') : 0;
        return max($product, 0);
    }

    private function fallback(): array
    {
        if ($this->fallback === null) {
            $product = $this->product();
            $this->fallback = $product > 0 && !$this->together() ? $this->cards->fallback($product, self::CARDS) : [];
        }
        return $this->fallback;
    }
}
