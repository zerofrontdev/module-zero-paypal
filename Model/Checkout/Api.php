<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Model\Checkout;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zero\Core\Model\Http\JsonClient;

/**
 * PayPal's REST API (Orders v2, Payments v2) for zero_paypal_checkout: an
 * OAuth 2 access token from the app's client ID and secret (cached until
 * shortly before it expires), JSON both ways; the sandbox or live host by the
 * Sandbox setting.
 */
class Api
{
    private const LIVE = 'https://api-m.paypal.com';
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly JsonClient $client,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 20
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function createOrder(array $order, ?int $storeId): array
    {
        return $this->send('POST', '/v2/checkout/orders', $order, $storeId);
    }

    /**
     * @throws LocalizedException
     */
    public function getOrder(string $id, ?int $storeId): array
    {
        return $this->send('GET', '/v2/checkout/orders/' . rawurlencode($id), null, $storeId);
    }

    /**
     * Takes the money the buyer approved. PayPal's answer, or its error
     * (`name`, `details[].issue`) when it refuses.
     *
     * @throws LocalizedException When PayPal can't be reached
     */
    public function captureOrder(string $id, ?int $storeId): array
    {
        return $this->send('POST', '/v2/checkout/orders/' . rawurlencode($id) . '/capture', null, $storeId, true);
    }

    /**
     * @throws LocalizedException
     */
    public function refundCapture(string $captureId, array $amount, ?int $storeId): array
    {
        return $this->send('POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', ['amount' => $amount], $storeId);
    }

    public function isConfigured(?int $storeId): bool
    {
        return $this->clientId($storeId) !== '' && $this->secret($storeId) !== '';
    }

    /**
     * PayPal's amount value: two decimals (no decimals for HUF, JPY, TWD).
     */
    public static function value(float $amount, string $currency): string
    {
        return number_format($amount, in_array($currency, ['HUF', 'JPY', 'TWD'], true) ? 0 : 2, '.', '');
    }

    /**
     * @throws LocalizedException
     */
    private function send(string $method, string $path, ?array $body, ?int $storeId, bool $acceptRefusal = false): array
    {
        $token = $this->accessToken($storeId);
        try {
            // Retried requests aren't done twice (PayPal-Request-Id makes them idempotent).
            $headers = ['Authorization' => 'Bearer ' . $token];
            if ($method === 'POST') {
                $headers['PayPal-Request-Id'] = sha1($path . json_encode($body));
            }
            ['status' => $status, 'body' => $answer] = $this->client->request(
                $method,
                $this->host($storeId) . $path,
                $body,
                $headers,
                $this->timeout,
                true
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Zero PayPal Checkout: ' . $path . ': ' . $e->getMessage());
            throw new LocalizedException(__('PayPal is not answering. Please try again in a minute.'), $e);
        }
        if ($answer === null || ($status >= 300 && !($acceptRefusal && $status >= 400 && $status < 500))) {
            $name = is_array($answer) ? (string) ($answer['name'] ?? '') : '';
            $this->logger->warning(sprintf('Zero PayPal Checkout: %s: HTTP %d %s', $path, $status, $name));
            throw new LocalizedException(__('PayPal refused the request: %1', $name !== '' ? $name : 'HTTP ' . $status));
        }

        return $answer;
    }

    /**
     * @throws LocalizedException
     */
    private function accessToken(?int $storeId): string
    {
        if (!$this->isConfigured($storeId)) {
            throw new LocalizedException(__('PayPal Checkout is not set up: the client ID or secret is missing.'));
        }
        $key = 'zero_paypal_checkout_token_' . sha1($this->host($storeId) . $this->clientId($storeId));
        $cached = $this->cache->load($key);
        if ($cached !== false && $cached !== '') {
            return (string) $this->encryptor->decrypt((string) $cached);
        }
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($this->timeout);
            $curl->setCredentials($this->clientId($storeId), $this->secret($storeId));
            $curl->addHeader('Accept', 'application/json');
            $curl->post($this->host($storeId) . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            $answer = json_decode((string) $curl->getBody(), true);
            $status = $curl->getStatus();
        } catch (\Throwable $e) {
            $this->logger->warning('Zero PayPal Checkout: token: ' . $e->getMessage());
            throw new LocalizedException(__('PayPal is not answering. Please try again in a minute.'), $e);
        }
        if ($status !== 200 || empty($answer['access_token'])) {
            $this->logger->warning(sprintf('Zero PayPal Checkout: token: HTTP %d %s', $status, $answer['error'] ?? ''));
            throw new LocalizedException(__('PayPal refused the shop\'s client ID and secret.'));
        }
        $lifetime = max(60, (int) ($answer['expires_in'] ?? 3600) - 300);
        $this->cache->save($this->encryptor->encrypt((string) $answer['access_token']), $key, [], $lifetime);

        return (string) $answer['access_token'];
    }

    private function host(?int $storeId): string
    {
        return $this->scopeConfig->isSetFlag('payment/zero_paypal_checkout/sandbox', ScopeInterface::SCOPE_STORE, $storeId)
            ? self::SANDBOX
            : self::LIVE;
    }

    private function clientId(?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue('payment/zero_paypal_checkout/client_id', ScopeInterface::SCOPE_STORE, $storeId));
    }

    private function secret(?int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/zero_paypal_checkout/client_secret', ScopeInterface::SCOPE_STORE, $storeId));

        // Read through the Encrypted backend model its config.xml default names, the
        // value comes decrypted; one stored some other way may still be encrypted.
        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
