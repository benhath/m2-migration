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
 * Drops the tool options that are the shop's setting rather than a product's.
 *
 * The endpoints were the same URLs on every product and had to be retyped whenever a site changed domain or
 * turned https on; they are named once in Stores > Configuration > Designer > Endpoints now and read through
 * Ben\Designer\Model\Endpoints, so nothing is carried over for them. The older Quality tool's thresholds and
 * wording are gone with the tool itself, which the points scoring in Ben\Designer\Model\Quality\Scorer replaced,
 * so nothing is carried over for those either. Everything a product genuinely differs on - the Preview's notice
 * and styles, the Position flags, the Create wording - is left alone.
 */
class RemoveGlobalDesignerToolOptions implements DataPatchInterface
{
    /**
     * The option names that are no longer a product's to set, by the tool component that carried them. Keyed
     * by component because a name such as "endpoint" is only global on the tool it belongs to
     */
    private const array OPTION_NAMES_BY_COMPONENT = [
        'Create' => ['endpoint'],
        'Position' => ['imageGenerateEndpoint'],
        'Preview' => ['imageGenerateEndpoint'],
        'Purchase' => ['addToCartEndpoint', 'cartUrl', 'priceFetchEndpoint'],
        'Quality' => [
            'fileSizeBadMb',
            'imageBadMbPerM2',
            'imageGoodMbPerM2',
            'qualityFair',
            'qualityOk',
            'qualityWeak',
        ],
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

        foreach (self::OPTION_NAMES_BY_COMPONENT as $component => $optionNames) {
            $toolIds = $this->getToolIds($component);

            if (!$toolIds) {
                continue;
            }

            $toolOptions = $this->getToolOptions($toolIds, $optionNames);
            $toolOptionIds = array_keys($toolOptions);

            if (!$toolOptionIds) {
                continue;
            }

            $this->removeProductToolOptions($toolOptionIds);
            $this->removeToolOptions($toolOptions);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\RemoveGlobalDesignerToolOptions'];
    }

    /**
     * A store may have more than one tool on the same component, so every one of them is cleaned
     */
    private function getToolIds(string $component): array
    {
        $toolCollection = $this->toolCollectionFactory->create();
        $toolCollection->addFieldToFilter(ToolInterface::COMPONENT, $component);

        return array_map('intval', $toolCollection->getAllIds());
    }

    /**
     * @return ToolOption[] keyed by tool option id
     */
    private function getToolOptions(array $toolIds, array $optionNames): array
    {
        $toolOptionCollection = $this->toolOptionCollectionFactory->create();
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::TOOL_ID, ['in' => $toolIds]);
        $toolOptionCollection->addFieldToFilter(ToolOptionInterface::NAME, ['in' => $optionNames]);

        return $toolOptionCollection->getItems();
    }

    private function removeProductToolOptions(array $toolOptionIds): void
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::TOOL_OPTION_ID, ['in' => $toolOptionIds]);
        $productToolOptionCollection->walk('delete');
    }

    /**
     * @param ToolOption[] $toolOptions
     * @throws Exception
     */
    private function removeToolOptions(array $toolOptions): void
    {
        foreach ($toolOptions as $toolOption) {
            $this->toolOptionResource->delete($toolOption);
        }
    }
}
