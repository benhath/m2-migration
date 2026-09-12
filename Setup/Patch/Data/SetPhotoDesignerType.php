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
 * Every product with designer tools that predates designer types is a photo product, unless one of its
 * tools belongs to another type. A product carrying a giftwrap or a personalise tool is left untyped here
 * rather than being called a photo it is not; the module that owns that type sets it
 */
class SetPhotoDesignerType implements DataPatchInterface
{
    // The tool components that belong to another designer type; a product carrying one is not a photo product
    private const array OTHER_TYPE_COMPONENT_PREFIXES = ['Giftwrap', 'Personalise'];

    private const string TYPE_CODE = 'photo';

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

        $componentsByProduct = [];

        /** @var ProductTool $productTool */
        foreach ($this->productToolCollectionFactory->create()->getItems() as $productTool) {
            $componentsByProduct[$productTool->getData(ProductToolInterface::PRODUCT_ID)][] = (string)$productTool->getTool()->getComponent();
        }

        $photoProductIds = array_keys(
            array_filter(
                $componentsByProduct,
                static fn(array $components) => !array_filter(
                    $components,
                    static fn(string $component) => (bool)array_filter(
                        self::OTHER_TYPE_COMPONENT_PREFIXES,
                        static fn(string $prefix) => str_starts_with($component, $prefix)
                    )
                )
            )
        );

        if ($photoProductIds) {
            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addAttributeToSelect(DesignerTypeResolver::ATTRIBUTE_CODE);
            $productCollection->addFieldToFilter('entity_id', ['in' => $photoProductIds]);

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
        return ['Ben\DesignerPhoto\Setup\Patch\Data\SetPhotoDesignerType'];
    }
}
