<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\CustomerOrder;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\QueryFilter;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class CustomerOrderController extends AbstractController implements PullInterface
{
    public function pull(QueryFilter $queryFilter): array
    {
        file_put_contents('/var/www/html/var/log/orderPull.log', json_encode($queryFilter) . PHP_EOL . PHP_EOL);

        $endpointUrl = $this->getEndpointUrl('customerOrder');
        $client = $this->getHttpClient();

        try {
            $response = $client->request('GET', $endpointUrl, ['json' => $queryFilter]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray();

            if ($statusCode !== 200 || !isset($data['success']) || $data['success'] !== true) {
                $this->logger->error('Pimcore getOrders error!');
                return [];
            }

            $orders = [];
            foreach ($data['orders'] as $orderData) {
                $identity = new Identity($orderData['id'], 0);
                $order = new CustomerOrder();
                $order->setId($identity);
                $order->setOrderNumber($orderData['orderNumber']);
                $order->setLanguageIso('de');

                $orders[] = $order;
            }

        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        return $orders;
    }

    protected function updateModel(Product $model): void
    {
        // nothing to-do here
    }
}