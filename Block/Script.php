<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Recapi\Recommendations\Model\Config;

/**
 * The storefront's script: product views and add-to-carts, from the visitor's
 * browser with the JS library.
 */
class Script extends Template
{
    // A version recapi serves (sdk/js/published.json) whose magento.js the
    // template imports: an entry file, so this address redirects to the
    // current version's and every fix reaches the store within a day.
    public const SDK_VERSION = '0.13.0';

    public function __construct(Context $context, private readonly Config $config, array $data = [])
    {
        parent::__construct($context, $data);
    }

    /**
     * What the script needs, or null when the keys are missing. The full-page
     * cache serves this HTML to every visitor of the URL, so it holds only what
     * is the same for all of them: the script reads the visitor.
     */
    public function getPage(): ?array
    {
        $api = $this->config->apiUrl();
        $key = $this->config->publicKey();
        if ($api === '' || $key === '') {
            return null;
        }
        $request = $this->getRequest();
        $store = $this->_storeManager->getStore();
        $action = $request->getFullActionName();
        $product = $action === 'catalog_product_view' ? (int) $request->getParam('id') : 0;
        return [
            'api' => $api,
            'sdk' => $api . '/sdk/' . self::SDK_VERSION . '/magento.js',
            'key' => $key,
            'platform' => 'magento/' . Config::VERSION,
            'product' => $product > 0 ? (string) $product : null,
            // Each row its own placement, so the dashboard and the lift test
            // tell them apart: the product page's, the cart's, and a block a
            // theme puts anywhere else. An unconfigured placement serves as
            // the default one and joins its lift test, so a store's settings
            // carry over.
            'placement' => match ($action) {
                'catalog_product_view' => 'product',
                'checkout_cart_index' => 'cart',
                default => 'block',
            },
            'cookieRestriction' => $this->config->cookieRestriction(),
            'website' => (string) $this->_storeManager->getWebsite()->getId(),
            // The row's lookup for the products recapi sends without
            // metadata. A price in metadata is the shop's, in its base
            // currency: shown only to visitors who browse in that currency.
            'cards' => $this->getUrl('recapi/cards'),
            'currency' => $store->getCurrentCurrencyCode() === $store->getBaseCurrencyCode() ? (string) $store->getBaseCurrencyCode() : null,
        ];
    }
}
