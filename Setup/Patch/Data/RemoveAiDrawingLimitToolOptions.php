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
 * Drops the prompt length and redraw limits a product carried, now that they are shop settings.
 *
 * How long a description may be and how many further takes a customer is offered are the shop's rules, set
 * once under Stores > Configuration > Designer > Drawing and read by both the browser and the server. Every
 * product held the same numbers as those settings, so nothing is carried over: the rows are only removed, and
 * the tools keep the options that really do differ between products.
 *
 * The text boxes a customer types into - the message on the paper, the words on a print, the note and the
 * inside of a card - keep their own maxCharacters. That is how long a printed message may be, which is a
 * property of the product being printed, not a rule about what may be asked of the model.
 */
class RemoveAiDrawingLimitToolOptions implements DataPatchInterface
{
    /**
     * The option each tool no longer sets, by the component it belongs to
     */
    private const array OPTION_NAME_BY_TOOL_COMPONENT = [
        'AiPortrait' => 'maxVariations',
        'Create' => 'maxCharacters',
        'StickerPrompt' => 'maxVariations',
    ];

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

        foreach (self::OPTION_NAME_BY_TOOL_COMPONENT as $component => $optionName) {
            $toolIds = $this->getToolIds($component);

            if (!$toolIds) {
                continue;
            }

            $toolOptions = $this->getToolOptions($toolIds, $optionName);

            if (!$toolOptions) {
                continue;
            }

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
    private function getToolIds(string $component): array
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, $component);

        return array_map('intval', $toolCollection->getAllIds());
    }

    /**
     * @param int[] $toolIds
     *
     * @return ToolOption[] keyed by id
     */
    private function getToolOptions(array $toolIds, string $optionName): array
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::TOOL_ID, ['in' => $toolIds]);
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::NAME, $optionName);

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
