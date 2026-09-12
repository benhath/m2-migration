<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolInterface;
use Ben\Designer\Api\Data\ToolInterface;
use Ben\Designer\Model\ResourceModel\ProductTool\CollectionFactory as ProductToolCollectionFactory;
use Ben\Designer\Model\ResourceModel\Tool\CollectionFactory as ToolCollectionFactory;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Takes the QualityScore tool off the products that were given it while the scoring was being tried out.
 *
 * The rebuilt scoring is the Quality tool itself, so there is no QualityScore component in the designer to
 * render and a product that names one shows nothing where it sits. The product's option values go with the
 * row; the tool and its options are left in place, so an admin can see what was set up and delete it, or point
 * it at something else. A second run finds nothing to remove.
 */
class RemoveQualityScoreProductTools implements DataPatchInterface
{
    private const TOOL_COMPONENT = 'QualityScore';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductToolCollectionFactory $productToolCollectionFactory,
        private readonly ToolCollectionFactory $toolCollectionFactory,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable('ben_designer_product_tool')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $toolIds = $this->getToolIds();

        if ($toolIds) {
            $productToolCollection = $this->productToolCollectionFactory->create();
            $productToolCollection->addFieldToFilter(ProductToolInterface::TOOL_ID, ['in' => $toolIds]);
            $productToolCollection->walk('delete');
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\RemoveQualityScoreProductTools'];
    }

    /**
     * A store may have more than one tool on the component, so every one of them is cleaned
     */
    private function getToolIds(): array
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, self::TOOL_COMPONENT);

        return array_map('intval', $toolCollection->getAllIds());
    }
}
