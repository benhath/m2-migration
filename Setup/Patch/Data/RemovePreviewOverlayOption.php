<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolOptionInterface;
use Ben\Designer\Api\Data\ToolInterface;
use Ben\Designer\Api\Data\ToolOptionInterface;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ResourceModel\Tool\CollectionFactory as ToolCollectionFactory;
use Ben\Designer\Model\ResourceModel\ToolOption as ToolOptionResource;
use Ben\Designer\Model\ResourceModel\ToolOption\CollectionFactory as ToolOptionCollectionFactory;
use Ben\Designer\Model\ToolOption;
use Ben\Migration\Model\Gate;
use Exception;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the Preview tool's showOverlay option and the value every giftwrap product carried for it.
 *
 * The option opened a place over the mockup for another tool to draw in, which only the face finder ever used,
 * to show the customer's photograph being read. That picture belongs with the face buttons it turns into, where
 * the customer is already looking, so it is drawn there now and the place over the mockup has gone with it.
 */
class RemovePreviewOverlayOption implements DataPatchInterface
{
    private const string OPTION_NAME = 'showOverlay';

    private const string TOOL_COMPONENT = 'Preview';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductToolOptionCollectionFactory $productToolOptionCollectionFactory,
        private readonly ToolCollectionFactory $toolCollectionFactory,
        private readonly ToolOptionCollectionFactory $toolOptionCollectionFactory,
        private readonly ToolOptionResource $toolOptionResource,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @throws Exception
     */
    public function apply(): void
    {
        if (!$this->gate->hasTable('ben_designer_tool_option')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $toolIds = $this->getPreviewToolIds();

        if ($toolIds) {
            $toolOptions = $this->getToolOptions($toolIds);

            if ($toolOptions) {
                $this->removeProductToolOptions(array_keys($toolOptions));
                $this->removeToolOptions($toolOptions);
            }
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return int[]
     */
    private function getPreviewToolIds(): array
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, self::TOOL_COMPONENT);

        return array_map('intval', $toolCollection->getAllIds());
    }

    /**
     * @param int[] $toolIds
     *
     * @return ToolOption[] keyed by id
     */
    private function getToolOptions(array $toolIds): array
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::TOOL_ID, ['in' => $toolIds]);
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::NAME, self::OPTION_NAME);

        return $toolOptionCollection->getItems();
    }

    /**
     * @param int[] $toolOptionIds
     */
    private function removeProductToolOptions(array $toolOptionIds): void
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::TOOL_OPTION_ID, ['in' => $toolOptionIds]);
        $productToolOptionCollection->walk('delete');
    }

    /**
     * @param ToolOption[] $toolOptions
     *
     * @throws Exception
     */
    private function removeToolOptions(array $toolOptions): void
    {
        foreach ($toolOptions as $toolOption) {
            $this->toolOptionResource->delete($toolOption);
        }
    }
}
