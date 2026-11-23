<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Describes the default store view's products to recapi (PUT /v1/items):
 * name, link, picture and price as metadata, so the row draws its cards
 * without asking the store, category names as tags, and active only while a
 * shopper can find and buy the product, so recapi never recommends a disabled
 * or sold-out one.
 *
 * A saved product is sent once the response is out; mass updates, imports
 * and stock changes save no product one by one, hence the nightly full sync.
 * A saved product's stock is read from its own stock item, not only from the
 * salable index: on a store whose indexers run "Update by Schedule" (new
 * stores' default) the index sees a new product only at the next cron run,
 * so it would go inactive until the nightly sync.
 */
class Catalog
{
    /** The API's limits (docs/api.md). */
    public const METADATA_BYTES = 2048;
    public const TITLE_BYTES = 512;
    public const MAX_TAGS = 32;
    public const TAG_BYTES = 64;
    public const BATCH = 1000;
    /** The end-of-request send: one try, as orders, so a recapi outage costs the admin nothing. */
    public const TIMEOUT = 2.0;
    /** A batch of 1,000 complete items is at most ~5.5 MB: well within 30 s and the API's 8 MB. */
    public const SYNC_TIMEOUT = 30.0;
    private const PAGE = 500;
    // As the library encodes them: the bytes the API counts.
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var array<string, array|null> product id => its item once deleted, null when saved */
    private array $queued = [];
    private bool $atShutdown = false;
    /** @var array<int, string>|null */
    private ?array $categoryNames = null;

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly ProductRepositoryInterface $products,
        private readonly CollectionFactory $collections,
        private readonly CategoryCollectionFactory $categories,
        private readonly Visibility $visibility,
        private readonly Image $images,
        private readonly Emulation $emulation,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends the product at the end of the request: by then the save's
     * transaction is committed and the admin form's stock is saved too. A
     * deleted product goes as inactive, named as it was.
     */
    public function queue(object $product, bool $deleted = false): void
    {
        $id = (string) $product->getId();
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1) {
            return;
        }
        $title = self::cut(self::text((string) $product->getName()), self::TITLE_BYTES);
        $this->queued[$id] = $deleted ? ['item_id' => $id, 'active' => false] + ($title === '' ? [] : ['title' => $title]) : null;
        if (!$this->atShutdown) {
            $this->atShutdown = true;
            register_shutdown_function(fn () => $this->sendQueued());
        }
    }

    /** The queued products, in one request: a failure is logged once, and the nightly sync sends them. */
    public function sendQueued(): void
    {
        $queued = $this->queued;
        $this->queued = [];
        if ($queued === []) {
            return;
        }
        try {
            $store = $this->stores->getDefaultStoreView();
            $api = $this->config->apiUrl($store->getId());
            $key = $this->config->secretKey($store->getId());
            if ($api === '' || $key === '') {
                return;
            }
            // As the library's end-of-request send: the page goes out first.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $items = $this->inStore($store, function () use ($queued, $store): array {
                $items = [];
                foreach ($queued as $id => $gone) {
                    try {
                        $items[] = $gone ?? $this->item($this->products->getById($id, false, $store->getId(), true), $store, true);
                    } catch (NoSuchEntityException) {
                        // Deleted later in the same request.
                    }
                }
                return $items;
            });
            if (count($items) === 1) {
                $this->call($api, $key, 'PUT', '/v1/items/' . rawurlencode($items[0]['item_id']), $items[0]);
            } else {
                foreach (array_chunk($items, self::BATCH) as $batch) {
                    $this->call($api, $key, 'POST', '/v1/items:batch', ['items' => $batch]);
                }
            }
        } catch (\Throwable $error) {
            $this->logger->error(sprintf('recapi: %d products not sent (the nightly sync sends them): %s', count($queued), $error->getMessage()));
        }
    }

    /**
     * Every product of the catalog, 1,000 per request with the library's
     * retries: the CLI command and the nightly cron job.
     *
     * A refused batch (a 400, the quota) or product stops nothing: the next
     * ones may go through, each refused product is logged, and the exception
     * at the end names the refusal. An outage the retries didn't outlast
     * stops the sync.
     *
     * @return int how many products recapi stored
     */
    public function sendAll(): int
    {
        $store = $this->stores->getDefaultStoreView();
        $api = $this->config->apiUrl($store->getId());
        $key = $this->config->secretKey($store->getId());
        if ($api === '' || $key === '') {
            throw new \RuntimeException('the API address or the secret key is not set for the default store view');
        }
        if (!class_exists(\Recapi\Client::class)) {
            // The PHP library, as recapi serves it (sdk/php/recapi.php).
            require_once dirname(__DIR__) . '/lib/recapi.php';
        }
        $client = new \Recapi\Client($key, $api, timeout: self::SYNC_TIMEOUT, logger: $this->logger);
        $refused = 0;
        $reason = '';
        $sent = $this->inStore($store, function () use ($client, $store, &$refused, &$reason): int {
            $sent = 0;
            $batch = [];
            $last = 0;
            $send = function () use ($client, &$batch, &$sent, &$refused, &$reason): void {
                try {
                    $sent += $client->upsertItems($batch);
                } catch (\Recapi\RejectedItemsError $error) {
                    // The others were stored.
                    $sent += $error->upserted;
                    $refused += count($error->rejected);
                    foreach ($error->rejected as [$index, $why]) {
                        $refusal = sprintf('product %s: %s', $batch[$index]['item_id'] ?? "#$index", $why);
                        $this->logger->log('warning', "recapi: refused $refusal");
                        $reason = $reason === '' ? $refusal : $reason;
                    }
                } catch (\Recapi\RecapiError $error) {
                    if ($error->outage()) {
                        throw $error;
                    }
                    $refused += count($batch);
                    $reason = $reason === '' ? $error->getMessage() : $reason;
                }
                $batch = [];
            };
            do {
                // By id, not by page number: a product saved meanwhile
                // shifts no page.
                $page = $this->collections->create()
                    ->setStoreId($store->getId())
                    ->addAttributeToSelect('*')
                    ->addUrlRewrite()
                    ->addAttributeToFilter('entity_id', ['gt' => $last])
                    ->setOrder('entity_id', 'ASC')
                    ->setPageSize(self::PAGE);
                $read = 0;
                foreach ($page as $product) {
                    $read++;
                    $last = (int) $product->getId();
                    $batch[] = $this->item($product, $store);
                    if (count($batch) === self::BATCH) {
                        $send();
                    }
                }
            } while ($read === self::PAGE);
            if ($batch !== []) {
                $send();
            }
            return $sent;
        });
        if ($refused > 0) {
            throw new \RuntimeException(sprintf('%d products refused, %d stored: %s', $refused, $sent, $reason));
        }
        return $sent;
    }

    /**
     * The complete item (the API replaces it whole): a field left out would
     * go back to its default. $saved: the product was just saved, so the
     * salable index may not know it yet.
     */
    public function item(object $product, object $store, bool $saved = false): array
    {
        $id = (string) $product->getId();
        $name = self::text((string) $product->getName());
        $websites = array_map('intval', (array) $product->getWebsiteIds());
        $item = [
            'item_id' => $id,
            'active' => (int) $product->getStatus() === Status::STATUS_ENABLED
                && in_array((int) $product->getVisibility(), $this->visibility->getVisibleInCatalogIds(), true)
                && in_array((int) $store->getWebsiteId(), $websites, true)
                && ((bool) $product->isSalable() || ($saved && self::inStock($product))),
            'tags' => $this->tags($product, $store),
        ];
        if ($name !== '') {
            $item['title'] = self::cut($name, self::TITLE_BYTES);
        }
        // Else every product never viewed before the module came would
        // count as new on its first sync.
        $published = self::published((string) $product->getCreatedAt());
        if ($published !== null) {
            $item['published_at'] = $published;
        }
        $metadata = ['name' => $name, 'url' => self::web((string) $product->getProductUrl(false))];
        // A product type that can't price or picture itself (a bundle
        // missing its options, say) still goes, without them: one product
        // must not stop the nightly sync.
        try {
            $metadata['picture'] = self::web((string) $this->images->init($product, 'related_products_list')->getUrl());
        } catch (\Throwable) {
        }
        try {
            // The final price as the row shows it, taxes as the store
            // displays them, back in the base currency: the script shows it
            // only to visitors browsing in that one.
            $price = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $rate = (float) $store->getCurrentCurrencyRate();
            if (is_numeric($price) && $rate > 0 && is_finite($price / $rate)) {
                $metadata['price'] = round($price / $rate, 2);
            }
        } catch (\Throwable) {
        }
        $metadata = self::shrink(array_filter($metadata, static fn ($v) => $v !== ''));
        if ($metadata !== []) {
            $item['metadata'] = $metadata;
        }
        return $item;
    }

    /**
     * Whether the product's own stock item lets a shopper buy it, as Magento
     * decides when it saves the item: in stock and, for a product whose
     * quantity is counted, more than the minimum left or backorders allowed.
     * A composite product (configurable, bundle) counts no quantity of its
     * own: Magento keeps its stock status in line with its children's.
     *
     * Only ever turns a product on: the salable index still decides when it
     * says salable, as for a product stocked only in another MSI source,
     * which the stock item (the default source) doesn't see.
     */
    private static function inStock(object $product): bool
    {
        $extension = $product->getExtensionAttributes();
        // Without Magento_CatalogInventory, products have no stock item.
        $stock = is_object($extension) && method_exists($extension, 'getStockItem') ? $extension->getStockItem() : null;
        if (!is_object($stock) || !$stock->getIsInStock()) {
            return false;
        }
        return !$stock->getManageStock()
            || (int) $stock->getBackorders() !== 0
            || $product->isComposite()
            || (float) $stock->getQty() > (float) $stock->getMinQty();
    }

    /**
     * Metadata over the API's 2,048 bytes, shrunk rather than refused: the
     * picture goes first, then the name is cut; a card needs both its name
     * and its link, so a link too long for any name drops both.
     */
    public static function shrink(array $metadata): array
    {
        if (self::bytes($metadata) > self::METADATA_BYTES) {
            unset($metadata['picture']);
        }
        $over = self::bytes($metadata) - self::METADATA_BYTES;
        if ($over > 0 && isset($metadata['name'])) {
            // Cutting $over bytes always fits (an escape takes more than its
            // byte, as \"); the longest name that fits lies between.
            $name = $metadata['name'];
            [$fits, $fails] = [max(0, strlen($name) - $over), strlen($name)];
            while ($fits < $fails - 1) {
                $mid = intdiv($fits + $fails, 2);
                $metadata['name'] = self::cut($name, $mid);
                if (self::bytes($metadata) <= self::METADATA_BYTES) {
                    $fits = $mid;
                } else {
                    $fails = $mid;
                }
            }
            $metadata['name'] = self::cut($name, $fits);
            if ($metadata['name'] === '') {
                unset($metadata['name']);
            }
        }
        if (self::bytes($metadata) > self::METADATA_BYTES || !isset($metadata['name'])) {
            unset($metadata['name'], $metadata['url']);
        }
        return $metadata;
    }

    /**
     * A category name in the API's tag alphabet, [a-z0-9:_-] ("Rain Coats &
     * Jackets" → "rain-coats-jackets", "Été" → "ete"), as the dashboard's
     * Shopify import writes tags.
     */
    public static function tag(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = (string) \Normalizer::normalize($name, \Normalizer::FORM_D);
        }
        $name = (string) preg_replace('/\p{Mn}+/u', '', $name);
        $tag = trim((string) preg_replace('/[^a-z0-9:_]+/', '-', strtolower($name)), '-');
        return rtrim(substr($tag, 0, self::TAG_BYTES), '-');
    }

    /** @return list<string> */
    private function tags(object $product, object $store): array
    {
        if ($this->categoryNames === null) {
            $this->categoryNames = [];
            // Level 0 is the tree's root, 1 a store's root ("Default Category").
            $categories = $this->categories->create()
                ->setStoreId($store->getId())
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('level', ['gt' => 1]);
            foreach ($categories as $category) {
                $this->categoryNames[(int) $category->getId()] = (string) $category->getName();
            }
        }
        $tags = [];
        foreach ((array) $product->getCategoryIds() as $id) {
            $tag = self::tag($this->categoryNames[(int) $id] ?? '');
            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }
        return array_slice(array_keys($tags), 0, self::MAX_TAGS);
    }

    /** Runs $work as a storefront page of $store would: its URLs, theme images and currency. */
    private function inStore(object $store, callable $work): mixed
    {
        $this->emulation->startEnvironmentEmulation($store->getId(), Area::AREA_FRONTEND, true);
        try {
            return $work();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /** One try: the nightly sync catches up whatever this misses. */
    private function call(string $api, string $key, string $method, string $path, array $body): void
    {
        $curl = curl_init($api . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body, self::JSON),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json',
                'User-Agent: recapi-magento/' . Config::VERSION, 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) (self::TIMEOUT * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $text = curl_exec($curl);
        if (!is_string($text)) {
            throw new \RuntimeException(curl_error($curl) ?: 'curl error ' . curl_errno($curl));
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status < 200 || $status >= 300) {
            $error = json_decode($text, true);
            throw new \RuntimeException(sprintf('HTTP %d %s', $status, is_string($error['error']['message'] ?? null) ? $error['error']['message'] : ''));
        }
    }

    /**
     * A creation date as the API's RFC 3339, or null when it is no real
     * date: an old import's 0000-00-00 would read as year -1, which the API
     * refuses along with the whole batch.
     */
    public static function published(string $created): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $created, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $created || (int) $date->format('Y') < 1970) {
            return null;
        }
        return $date->format('Y-m-d\TH:i:s\Z');
    }

    private static function bytes(array $metadata): int
    {
        return strlen(json_encode($metadata, self::JSON));
    }

    /** Only http(s) links: anything else the script would refuse anyway. */
    private static function web(string $url): string
    {
        return preg_match('#^https?://[^\s]+$#i', $url) === 1 ? self::text($url) : '';
    }

    /** Valid UTF-8 without NUL (the API refuses both), trimmed. */
    private static function text(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = (string) json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        return trim(str_replace("\0", '', $value));
    }

    /** At most $bytes bytes, never splitting a character. */
    private static function cut(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }
        while ($bytes > 0 && (ord($value[$bytes]) & 0xC0) === 0x80) {
            $bytes--;
        }
        return rtrim(substr($value, 0, max(0, $bytes)));
    }
}
