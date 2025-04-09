<?php

namespace Jtl\Connector\Core\Controller;

use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\ProductStockLevel;

class ProductStockLevelController implements PushInterface
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
        /**
         * @var ProductStockLevel
         */
        $productId = $model->getProductId();
        $stockLevel = $model->getStockLevel();

        var_dump($productId, $stockLevel);

        return [$model];
    }
}