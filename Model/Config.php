<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The keys pasted in Stores → Configuration → Sales → recapi, per store view:
 * two store views can be two recapi shops.
 */
class Config
{
    /**
     * The module's version, composer.json's (a test holds them equal): the
     * module names itself by it on its calls, so recapi words its setup hints
     * for Magento.
     */
    public const VERSION = '0.4.29';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function apiUrl(int|string|null $store = null): string
    {
        return rtrim($this->value('recapi/general/api_url', $store), '/');
    }

    public function publicKey(int|string|null $store = null): string
    {
        return $this->value('recapi/general/public_key', $store);
    }

    /**
     * Stored encrypted, read decrypted: etc/config.xml names the field's
     * Encrypted backend, which Magento's config reader applies to each value
     * it loads, so a second decrypt here would garble the key.
     */
    public function secretKey(int|string|null $store = null): string
    {
        return $this->value('recapi/general/secret_key', $store);
    }

    /**
     * Magento's own consent switch (Stores → Configuration → General → Web →
     * Default Cookie Settings): on, a visitor's device keeps nothing until
     * they accept the store's cookie notice.
     */
    public function cookieRestriction(int|string|null $store = null): bool
    {
        return $this->scopeConfig->isSetFlag('web/cookie/cookie_restriction', ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * The second row on product pages, "Often bought together" (Stores →
     * Configuration → Sales → recapi → Rows). On by default (etc/config.xml).
     */
    public function boughtTogether(int|string|null $store = null): bool
    {
        return $this->scopeConfig->isSetFlag('recapi/rows/bought_together', ScopeInterface::SCOPE_STORE, $store);
    }

    private function value(string $path, int|string|null $store): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store));
    }
}
