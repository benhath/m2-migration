<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolInterface;
use Ben\Designer\Model\DesignerTypeResolver;
use Ben\Designer\Model\ProductTool;
use Ben\Designer\Model\ResourceModel\ProductTool\CollectionFactory as ProductToolCollectionFactory;
use Ben\Designer\Setup\Patch\Data\AddDesignerTypeAttribute;
use Ben\Migration\Model\Gate;
use Magento\Catalog\Model\Product\ActionFactory as ProductActionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * A product carrying giftwrap tools that predates designer types is a giftwrap product
 */
class SetGiftwrapDesignerType implements DataPatchInterface
{
    private const string COMPONENT_PREFIX = 'Giftwrap';

    private const string TYPE_CODE = 'giftwrap';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductActionFactory $productActionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductToolCollectionFactory $productToolCollectionFactory,
    ) {
    }

    public static function getDependencies(): array
    {
        return [AddDesignerTypeAttribute::class];
    }

    /**
     * @throws LocalizedException
     */
    public function apply(): void
    {
        if (!$this->gate->hasTable('ben_designer_product_tool')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $giftwrapProductIds = [];

        /** @var ProductTool $productTool */
        foreach ($this->productToolCollectionFactory->create()->getItems() as $productTool) {
            if (str_starts_with((string)$productTool->getTool()->getComponent(), self::COMPONENT_PREFIX)) {
                $giftwrapProductIds[] = (int)$productTool->getData(ProductToolInterface::PRODUCT_ID);
            }
        }

        if ($giftwrapProductIds) {
            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addAttributeToSelect(DesignerTypeResolver::ATTRIBUTE_CODE);
            $productCollection->addFieldToFilter('entity_id', ['in' => array_unique($giftwrapProductIds)]);

            $untypedProductIds = [];

            foreach ($productCollection->getItems() as $product) {
                if (!$product->getData(DesignerTypeResolver::ATTRIBUTE_CODE)) {
                    $untypedProductIds[] = (int)$product->getId();
                }
            }

            if ($untypedProductIds) {
                $this->productActionFactory->create()->updateAttributes(
                    $untypedProductIds,
                    [DesignerTypeResolver::ATTRIBUTE_CODE => self::TYPE_CODE],
                    Store::DEFAULT_STORE_ID
                );
            }
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\DesignerGiftwrap\Setup\Patch\Data\SetGiftwrapDesignerType'];
    }
}
