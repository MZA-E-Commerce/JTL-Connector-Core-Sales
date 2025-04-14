<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\ProductStockLevel;

class ProductController implements PushInterface
{
    /**
     * Insert or update
     *
     * @param AbstractModel ...$model
     *
     * @return AbstractModel[]
     */
    public function push(AbstractModel ...$model): array
    {
        # Host-ID => Die ID aus dem JTL-Wawi-System
        # Endpoint (ID) => Die ID im externen System (Pimcore, Shopware, Magento, etc.)

        // toDo: itarate over all models!
        // make valid json and send to pimcore

        /**
         * @var ProductStockLevel
         */
        if (isset($model[0])) {
            /**
             * @var Product $product
             */
            $product = $model[0];

            $currentIdentity = $product->getId();
            $hostId = $currentIdentity->getHost();

            $pimCoreId = 'abcdef'; // get from PIM!

            $identity = new Identity($pimCoreId, $hostId);
            $product->setId($identity);
        }

        return $model;
    }
}