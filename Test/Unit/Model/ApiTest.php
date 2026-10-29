<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Core\Model\Http\JsonClient;
use Zero\Paypal\Model\Checkout\Api;

/**
 * The orders API through the shared JSON client; the OAuth token call (form
 * body, basic auth) is cURL's own and is cached here, so no test reaches it.
 */
class ApiTest extends TestCase
{
    private const HOST = 'https://api-m.paypal.com';

    private JsonClient&MockObject $client;
    private LoggerInterface&MockObject $logger;
    private Api $api;

    protected function setUp(): void
    {
        $this->client = $this->createMock(JsonClient::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('client-or-secret');
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('cached');
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('access-token');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->api = new Api($this->createMock(CurlFactory::class), $this->client, $scopeConfig, $encryptor, $cache, $this->logger, 14);
    }

    public function testAnOrderIsPostedWithTheBearerAndAnIdempotencyKey(): void
    {
        $order = ['intent' => 'CAPTURE'];
        $this->client->expects(self::once())->method('request')
            ->with('POST', self::HOST . '/v2/checkout/orders', $order, [
                'Authorization' => 'Bearer access-token',
                'PayPal-Request-Id' => sha1('/v2/checkout/orders' . json_encode($order)),
            ], 14, true)
            ->willReturn(['status' => 201, 'body' => ['id' => 'O1']]);

        self::assertSame('O1', $this->api->createOrder($order, 1)['id']);
    }

    public function testAnOrderIsReadWithAGetAndTheEncodedId(): void
    {
        $this->client->expects(self::once())->method('request')
            ->with('GET', self::HOST . '/v2/checkout/orders/O%2F1', null, ['Authorization' => 'Bearer access-token'], 14, true)
            ->willReturn(['status' => 200, 'body' => ['status' => 'APPROVED']]);

        self::assertSame('APPROVED', $this->api->getOrder('O/1', 1)['status']);
    }

    public function testACaptureRefusedWithA4xxReturnsPayPalSAnswer(): void
    {
        $this->client->method('request')->willReturn(['status' => 422, 'body' => ['name' => 'UNPROCESSABLE_ENTITY']]);

        self::assertSame('UNPROCESSABLE_ENTITY', $this->api->captureOrder('O1', 1)['name']);
    }

    public function testARefusalIsLoggedAndCarriesPayPalSErrorName(): void
    {
        $this->client->method('request')->willReturn(['status' => 400, 'body' => ['name' => 'INVALID_REQUEST']]);
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('HTTP 400 INVALID_REQUEST'));

        try {
            $this->api->getOrder('O1', 1);
            self::fail('A refusal must throw');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('INVALID_REQUEST', (string) $e->getMessage());
        }
    }

    public function testATransportFailureBecomesAFriendlyError(): void
    {
        $this->client->method('request')->willThrowException(new \RuntimeException('timed out'));
        $this->logger->expects(self::once())->method('warning');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not answering');
        $this->api->getOrder('O1', 1);
    }
}
