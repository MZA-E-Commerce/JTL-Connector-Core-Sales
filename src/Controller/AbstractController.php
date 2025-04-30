<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\ProductPrice;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractController
{
    /**
     * @var string
     */
    public const CUSTOMER_TYPE_B2B = 'b1d7b4cbe4d846f0b323a9d840800177';

    /**
     * @var string
     */
    public const CUSTOMER_TYPE_B2C = 'c2c6154f05b342d4b2da85e51ec805c9';

    /**
     * @var string
     */
    public const CUSTOMER_TYPE_MAPPINGS = [
        self::CUSTOMER_TYPE_B2B => 'B2B',
        self::CUSTOMER_TYPE_B2C => 'B2C'
    ];

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT = 'setProductData';

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT_STOCK_LEVEL = 'setProductStockLevel';

    /**
     * @var string
     */
    protected const UPDATE_TYPE_PRODUCT_PRICE = 'setProductPrice';

    /**
     * @var string
     */
    protected const CUSTOMER_TYPE_DEFAULT = self::CUSTOMER_TYPE_B2C;

    /**
     * @var CoreConfigInterface
     */
    protected CoreConfigInterface $config;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Using direct dependencies for better testing and easier use with a DI container.
     *
     * AbstractController constructor.
     * @param CoreConfigInterface $config
     * @param LoggerInterface $logger
     */
    public function __construct(CoreConfigInterface $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Template‑Method for all Controllers
     *
     * @param AbstractModel ...$models
     * @return AbstractModel[]
     */
    public function push(AbstractModel ...$models): array
    {
        foreach ($models as $i => $model) {
            // Check type
            if (!$model instanceof Product) {
                $this->logger->error('Invalid model type. Expected Product, got ' . get_class($model));
                continue;
            }

            $identity = $model->getId();
            // Check existing mapping
            if ($identity->getEndpoint()) {
                $this->logger->info(\sprintf(
                    'Product already has identity (host=%d endpoint=%d)',
                    $identity->getHost(),
                    $identity->getEndpoint()
                ));
            } else {
                // Get Pimcore ID
                try {
                    $pimcoreId = $this->getPimcoreId($model->getSku());
                } catch (\Throwable $e) {
                    $this->logger->error('Error fetching Pimcore ID for SKU '.$model->getSku().': '.$e->getMessage());
                    continue;
                }

                $identity = new Identity($pimcoreId, $identity->getHost());
                $model->setId($identity);
            }

            // Hook for the update
            try {
                $this->updateModel($model);
            } catch (\Throwable $e) {
                $this->logger->error('Error in updateModel(): '.$e->getMessage());
            }

            $models[$i] = $model;
        }

        return $models;
    }

    /**
     * @param string $endpointKey
     * @return string
     */
    protected function getEndpointUrl(string $endpointKey): string
    {
        $apiKey = $this->config->get('pimcore.api.key');
        if (empty($apiKey)) {
            throw new \RuntimeException('Pimcore API key is not set');
        }

        $url = $this->config->get('pimcore.api.url');
        return $url . $this->config->get('pimcore.api.endpoints.' . $endpointKey . '.url');
    }

    /**
     * @return HttpClientInterface
     */
    protected function getHttpClient(): HttpClientInterface
    {
        $client = HttpClient::create();
        return $client->withOptions([
            'headers' => [
                'X-API-KEY' => $this->config->get('pimcore.api.key'),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param string $sku
     * @return int
     */
    protected function getPimcoreId(string $sku): int
    {
        if (empty($sku)) {
            throw new \RuntimeException('SKU is empty');
        }

        $url = $this->getEndpointUrl('getId');
        $fullApiUrl = str_replace('{sku}', $sku, $url);
        $client = $this->getHttpClient();

        try {
            $response = $client->request($this->config->get('pimcore.api.endpoints.getId.method'), $fullApiUrl);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode === 200 && isset($data['success']) && $data['success'] === true) {
                return (int)$data['id'];
            }

            throw new \RuntimeException('Pimcore API error: ' . ($data['error'] ?? 'Unknown error'));

        } catch (TransportExceptionInterface | HttpExceptionInterface | DecodingExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param Product $product
     * @param string $type
     * @return void
     */
    protected function updateProductPimcore(Product $product, string $type = self::UPDATE_TYPE_PRODUCT): void
    {
        $httpMethod = $this->config->get('pimcore.api.endpoints.' . $type . '.method');
        $client = $this->getHttpClient();
        $fullApiUrl = $this->getEndpointUrl($type);

        $postData = [];

        switch ($type) {
            case self::UPDATE_TYPE_PRODUCT_STOCK_LEVEL:
                $this->logger->info('Updating product stock level in Pimcore (SKU: ' . $product->getSku() . ')');
                $postData = [
                    'id' => $product->getId()->getEndpoint(),
                    'stockLevel' => $product->getStockLevel(),
                ];

                break;
            case self::UPDATE_TYPE_PRODUCT_PRICE:
                $this->logger->info('Updating product price in Pimcore (SKU: ' . $product->getSku() . ')');

                $prices = $this->parsePrices($product);

                $postData = [
                    'id' => $product->getId()->getEndpoint(),
                    'sku' => $product->getSku(),
                    'vat' => $product->getVat(),
                    'prices' => $prices,
                ];

                break;
            case self::UPDATE_TYPE_PRODUCT:
                $this->logger->info('Updating product in Pimcore (SKU: ' . $product->getSku() . ')');
                break;
        }

        #file_put_contents('/var/www/html/var/log/updateProductPimcore.log', $httpMethod . ' -> ' . $fullApiUrl . ' -> ' . json_encode($postData) . PHP_EOL . PHP_EOL, FILE_APPEND);

        try {
            $response = $client->request($httpMethod, $fullApiUrl, ['json' => $postData]);

            $statusCode = $response->getStatusCode();
            $responseData = $response->toArray();

            if ($statusCode === 200 && isset($responseData['success']) && $responseData['success'] === true) {
                $this->logger->info('Product updated successfully in Pimcore (SKU: ' . $product->getSku() . ')');
                return;
            }

            throw new \RuntimeException('Pimcore API error: ' . ($data['error'] ?? 'Unknown error'));

        } catch (TransportExceptionInterface | HttpExceptionInterface | DecodingExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param Product $model
     * @return void
     */
    abstract protected function updateModel(Product $model): void;

    /**
     * @param Product $product
     * @return array
     */
    private function parsePrices(Product $product): array
    {
        $prices = [];
        $pricesData = $product->getPrices();

        foreach ($pricesData as $productPrice) {
            $items = $productPrice->getItems();
            $endpointId = $productPrice->getProductId()->getEndpoint();
            $customerGroup = $productPrice->getCustomerGroupId()->getEndpoint();
            foreach ($items as $item) {
                $prices[] = [
                    'productId' => $endpointId,
                    'customerGroup' => !empty($customerGroup) ? $customerGroup : 'NOT_SET',
                    'tenant' => self::CUSTOMER_TYPE_MAPPINGS[$customerGroup] ?? self::CUSTOMER_TYPE_MAPPINGS[self::CUSTOMER_TYPE_DEFAULT],
                    'quantity' => $item->getQuantity(),
                    'netPrice' => $item->getNetPrice(),
                ];
            }
        }

        return $prices;
    }
}