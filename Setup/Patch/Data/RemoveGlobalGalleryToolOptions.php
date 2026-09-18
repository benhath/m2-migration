<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\Data\ProductToolOptionInterface;
use Ben\Designer\Model\ResourceModel\ProductToolOption\CollectionFactory as ProductToolOptionCollectionFactory;
use Ben\Designer\Model\ToolOption;
use Ben\Migration\Model\Gate;
use Ben\Migration\Model\ToolOptions;
use Exception;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Moves the Gallery tool options that became shop settings into Stores > Configuration > Designer > Gallery,
 * then drops them.
 *
 * Every product carried the same value for these, so they are the shop's setting now. The value a store had is
 * carried over first - the most common one where products disagree - so a store keeps the ceilings it was
 * running on; a config row an admin has already saved is never overwritten. The upload endpoint is not carried
 * over: it was a whole URL per product and the config field holds a path that the store's own base URL is put
 * in front of, so the value would be wrong at its new home. Neither is the multiple-uploads flag, which has
 * gone entirely: a design is one photo. The options a product genuinely differs on - the file types it accepts
 * and its size and pixel ceilings - are left alone.
 */
class RemoveGlobalGalleryToolOptions implements DataPatchInterface
{
    private const string TOOL_COMPONENT = 'Gallery';

    /**
     * Where each retired option's value belongs now, by option name. The endpoint is absent on purpose: it is
     * one of the endpoints in config now and holds a path rather than the whole URL a product carried. So is
     * the multiple-uploads flag, which has gone entirely: a design is one photo
     */
    private const array CONFIG_PATHS_BY_OPTION_NAME = [
        'minUploadFileSizeMb' => 'designer/gallery/min_file_size_mb',
        'minUploadHeightPx' => 'designer/gallery/min_height_px',
        'minUploadWidthPx' => 'designer/gallery/min_width_px',
    ];

    /**
     * The option names that are no longer a product's to set
     */
    private const array OPTION_NAMES = [
        'allowMultipleUploads',
        'imageUploadEndpoint',
        'minUploadFileSizeMb',
        'minUploadHeightPx',
        'minUploadWidthPx',
    ];

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly ProductToolOptionCollectionFactory $productToolOptionCollectionFactory,
        private readonly ToolOptions $toolOptions,
        private readonly WriterInterface $configWriter,
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

        $toolOptions = $this->toolOptions->find(self::TOOL_COMPONENT, self::OPTION_NAMES);
        $this->migrateToConfig($toolOptions);
        $this->toolOptions->remove($toolOptions);

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\RemoveGlobalGalleryToolOptions'];
    }

    /**
     * The value explicitly saved at the default scope, ignoring what config.xml declares; null when there is
     * no row of its own
     */
    private function getSavedValue(string $path): ?string
    {
        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', $path);
        $collection->addFieldToFilter('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $collection->addFieldToFilter('scope_id', 0);
        $collection->setPageSize(1);

        $configData = $collection->getFirstItem();

        return $configData->getId() ? (string)$configData->getValue() : null;
    }

    /**
     * The value the products carried for one option, the most common where they disagree; null when none of
     * them held anything
     */
    private function getSharedValue(int $toolOptionId): ?string
    {
        $productToolOptionCollection = $this->productToolOptionCollectionFactory->create();
        $productToolOptionCollection->addFieldToFilter(ProductToolOptionInterface::TOOL_OPTION_ID, $toolOptionId);

        $values = [];

        foreach ($productToolOptionCollection->getItems() as $productToolOption) {
            $value = trim((string)$productToolOption->getValue());

            if ($value !== '') {
                $values[] = $value;
            }
        }

        if (!$values) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);

        return (string)array_key_first($counts);
    }

    /**
     * @param ToolOption[] $toolOptions
     */
    private function migrateToConfig(array $toolOptions): void
    {
        foreach ($toolOptions as $toolOptionId => $toolOption) {
            $path = self::CONFIG_PATHS_BY_OPTION_NAME[(string)$toolOption->getName()] ?? null;

            // An option with no new home, or one an admin has already answered in config, is only deleted
            if ($path === null || $this->getSavedValue($path) !== null) {
                continue;
            }

            $value = $this->getSharedValue((int)$toolOptionId);

            if ($value !== null) {
                $this->configWriter->save($path, $value, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
            }
        }
    }
}
