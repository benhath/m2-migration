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
 * Drops the Sticker Shape tool's defaultOffsetMm option and the value every sticker product carried for it.
 *
 * Where the blade clears the artwork before the customer touches the slider is the shop's decision, set once
 * under Stores > Configuration > Designer > Vinyl Stickers and already read there by the cut file writer.
 * Every product held the same number as that setting, so nothing is carried over: the rows are only removed,
 * and the slider's own ends, the smallest and largest offsets a product allows, are left alone.
 */
class RemoveStickerOffsetToolOption implements DataPatchInterface
{
    private const string OPTION_NAME = 'defaultOffsetMm';

    private const string TOOL_COMPONENT = 'StickerShape';

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

        $toolOptions = $this->getToolOptions($this->getToolIds());

        if ($toolOptions) {
            $this->removeProductToolOptions(array_keys($toolOptions));
            $this->removeToolOptions($toolOptions);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * A store may have more than one tool on the same component, so every one of them is cleaned
     *
     * @return int[]
     */
    private function getToolIds(): array
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
        if (!$toolIds) {
            return [];
        }

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
