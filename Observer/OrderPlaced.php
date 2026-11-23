<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;
use Recapi\Recommendations\Model\Config;

/**
 * Sends an order's products to recapi as purchases, from the server: a buyer
 * who blocks scripts or never comes back from the payment page still counts,
 * and each order counts once (Magento fires the event once per order).
 *
 * The storefront's script keeps the visitor's recapi ids in the "recapi"
 * cookie, only with their consent: the purchases then join the views and
 * add-to-carts of the same visitor, and carry the recommendation that brought
 * each product, so the dashboard credits it.
 *
 * A buyer whose browser asks not to be tracked (Do Not Track or Global
 * Privacy Control) is not followed here either: the storefront's script
 * sends nothing about them, and their order sends nothing.
 */
class OrderPlaced implements ObserverInterface
{
    public const COOKIE = 'recapi';

    public function __construct(
        private readonly Config $config,
        private readonly CookieManagerInterface $cookies,
        private readonly State $state,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
    ) {
    }

    public function execute(Observer $observer): void
    {
        // A purchase recapi misses is better than a checkout that fails.
        try {
            $this->send($observer->getEvent()->getOrder());
        } catch (\Throwable $error) {
            $this->logger->error('recapi: order not sent: ' . $error->getMessage());
        }
    }

    private function send(object $order): void
    {
        $store = $order->getStoreId();
        $api = $this->config->apiUrl($store);
        $key = $this->config->secretKey($store);
        if ($api === '' || $key === '' || $this->doNotTrack()) {
            return;
        }
        if (!class_exists(\Recapi\Client::class)) {
            // The PHP library, as recapi serves it (sdk/php/recapi.php).
            require_once dirname(__DIR__) . '/lib/recapi.php';
        }
        $visitor = $this->visitor();
        $customer = $order->getCustomerId();
        $user = $customer ? (string) $customer : null;
        // A guest without the cookie still buys these products together:
        // one id per order keeps the basket a basket for training, and names
        // nobody.
        $anon = $visitor['anon'] ?? ($user === null ? 'magento-order-' . $order->getIncrementId() : null);
        // The library sends once the response is out (Client::flushAtShutdown).
        $client = new \Recapi\Client($key, $api, logger: $this->logger);
        $currency = (string) $order->getOrderCurrencyCode();
        foreach ($order->getAllVisibleItems() as $line) {
            $item = (string) $line->getProductId();
            if (\Recapi\History::isId($item)) {
                [$value, $paidIn] = self::paid($line, $currency);
                $client->track($item, 'purchase', $user, $anon, $visitor['recs'][$item] ?? null, $value, $paidIn);
            }
        }
    }

    /**
     * What the line paid, in the currency the buyer paid in: its row total
     * (without tax: Magento keeps it apart) less its discount. Shipping is
     * the order's, never a line's. [null, null] where Magento gives no
     * number: the library refuses a bad amount, and the purchase must still
     * go.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private static function paid(object $line, string $currency): array
    {
        $row = $line->getRowTotal();
        if (!is_numeric($row) || !preg_match('/^[A-Za-z]{3}$/', $currency)) {
            return [null, null];
        }
        $discount = $line->getDiscountAmount();
        $value = (float) $row - (is_numeric($discount) ? (float) $discount : 0.0);
        return is_finite($value) && $value >= 0 ? [round($value, 4), strtoupper($currency)] : [null, null];
    }

    /**
     * The cookie's ids, those that pass for ids: a visitor can write anything
     * in it. An order placed from the admin is not the admin's visit.
     *
     * @return array{anon?: string, recs: array<string, string>}
     */
    private function visitor(): array
    {
        if (!$this->fromVisitor()) {
            return ['recs' => []];
        }
        $cookie = json_decode((string) $this->cookies->getCookie(self::COOKIE), true);
        $visitor = ['recs' => []];
        if (is_array($cookie) && \Recapi\History::isId($cookie['anon'] ?? null)) {
            $visitor['anon'] = $cookie['anon'];
        }
        foreach (is_array($cookie['recs'] ?? null) ? $cookie['recs'] : [] as $item => $rec) {
            if (\Recapi\History::isId((string) $item) && \Recapi\History::isId($rec)) {
                $visitor['recs'][(string) $item] = $rec;
            }
        }
        return $visitor;
    }

    /**
     * Whether the browser placing the order sends `DNT: 1` or `Sec-GPC: 1`,
     * the signals the JS library reads as navigator.doNotTrack and
     * globalPrivacyControl. Luma's checkout places the order from that
     * browser (REST API), a headless one through GraphQL: the request
     * Magento serves is the visitor's. An admin's browser speaks for the
     * admin, not for the customer they order for.
     */
    private function doNotTrack(): bool
    {
        if (!$this->fromVisitor()) {
            return false;
        }
        foreach (['DNT', 'Sec-GPC'] as $header) {
            $value = $this->request->getHeader($header);
            if (is_string($value) && trim($value) === '1') {
                return true;
            }
        }
        return false;
    }

    /** An order placed in the admin, or by a script (no area), had no visitor. */
    private function fromVisitor(): bool
    {
        try {
            return $this->state->getAreaCode() !== Area::AREA_ADMINHTML;
        } catch (\Throwable) {
            return false;
        }
    }
}
