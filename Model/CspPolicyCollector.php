<?php

declare(strict_types=1);

namespace Recapi\Recommendations\Model;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;

/**
 * Lets the storefront's script reach recapi under Magento's
 * Content-Security-Policy: it imports the JS library from the API's host and
 * calls it with fetch, and Magento 2.4.7+ enforces the policy on checkout's
 * payment pages (and reports it elsewhere). The host is a per-store-view
 * setting, so a static csp_whitelist.xml can't hold it.
 *
 * Declared for every area (etc/di.xml says why), it adds the host on the
 * storefront alone: only there does the script run.
 */
class CspPolicyCollector implements PolicyCollectorInterface
{
    // import() is checked against script-src, the library's calls against connect-src.
    private const DIRECTIVES = ['script-src', 'connect-src'];

    public function __construct(private readonly Config $config, private readonly State $state)
    {
    }

    /**
     * The policies collected so far, with the current store view's API host
     * added to each directive. Magento's composite collector merges policies
     * of the same id, so the added copy widens the existing one.
     */
    public function collect(array $defaultPolicies = []): array
    {
        $host = $this->storefront() ? $this->host() : null;
        if ($host === null) {
            return $defaultPolicies;
        }
        $byId = [];
        foreach ($defaultPolicies as $policy) {
            $byId[$policy->getId()] ??= $policy;
        }
        $added = [];
        foreach (self::DIRECTIVES as $id) {
            // A directive the store doesn't set falls back to default-src in
            // the browser: one holding only recapi's host would block what
            // default-src allows, so it starts from default-src's sources. With
            // neither, nothing limits the script.
            $base = $byId[$id] ?? $byId['default-src'] ?? null;
            if ($base instanceof FetchPolicy) {
                $added[] = new FetchPolicy(
                    $id,
                    false,
                    [...$base->getHostSources(), $host],
                    $base->getSchemeSources(),
                    $base->isSelfAllowed(),
                    $base->isInlineAllowed(),
                    $base->isEvalAllowed(),
                    $base->getNonceValues(),
                    $base->getHashes(),
                    $base->isDynamicAllowed(),
                    $base->areEventHandlersAllowed(),
                );
            }
        }
        return [...$defaultPolicies, ...$added];
    }

    private function storefront(): bool
    {
        try {
            return $this->state->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The API's origin, as a CSP host source, when the script runs: with the
     * public key set (script.phtml prints nothing without it). The origin, not
     * the address: a host source with a path matches only that path.
     */
    private function host(): ?string
    {
        $api = $this->config->apiUrl();
        if ($api === '' || $this->config->publicKey() === '') {
            return null;
        }
        $url = parse_url($api);
        $scheme = strtolower((string) ($url['scheme'] ?? ''));
        $host = strtolower((string) ($url['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }
        return $scheme . '://' . $host . (isset($url['port']) ? ':' . $url['port'] : '');
    }
}
