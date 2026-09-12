<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Takes the Gallery group's leftovers out of Stores > Configuration > Designer > Gallery.
 *
 * Where uploads are posted to is an endpoint like every other one now, so it moves to the Endpoints group with
 * whatever path a store had set, in every scope it was set in; a path an admin has already answered there is
 * never overwritten. Allow Multiple Uploads has gone with the tool option it came from, since a design is one
 * photo, so its saved rows are dropped and nothing is left behind in core_config_data. A second run has nothing
 * left to do.
 */
class MigrateGalleryEndpointConfig implements DataPatchInterface
{
    private const CONFIG_XML_PATH_ENDPOINT_UPLOAD = 'designer/endpoints/upload';

    private const CONFIG_XML_PATH_GALLERY_UPLOAD_ENDPOINT = 'designer/gallery/upload_endpoint';

    // The path that has gone entirely, saved values and all
    private const REMOVED_PATH = 'designer/gallery/allow_multiple_uploads';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly WriterInterface $configWriter,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Designer')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        foreach ($this->getSavedRows(self::CONFIG_XML_PATH_GALLERY_UPLOAD_ENDPOINT) as $row) {
            $scope = (string)$row['scope'];
            $scopeId = (int)$row['scope_id'];

            // An admin who has already answered the endpoint field keeps their answer
            if (!$this->getSavedRows(self::CONFIG_XML_PATH_ENDPOINT_UPLOAD, $scope, $scopeId)) {
                $this->configWriter->save(self::CONFIG_XML_PATH_ENDPOINT_UPLOAD, (string)$row['value'], $scope, $scopeId);
            }

            $this->configWriter->delete(self::CONFIG_XML_PATH_GALLERY_UPLOAD_ENDPOINT, $scope, $scopeId);
        }

        foreach ($this->getSavedRows(self::REMOVED_PATH) as $row) {
            $this->configWriter->delete(self::REMOVED_PATH, (string)$row['scope'], (int)$row['scope_id']);
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\MigrateGalleryEndpointConfig'];
    }

    /**
     * The rows explicitly saved for a path, ignoring what config.xml declares, in every scope or in one
     *
     * @return array[] each with scope, scope_id and value
     */
    private function getSavedRows(string $path, ?string $scope = null, ?int $scopeId = null): array
    {
        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', $path);

        if ($scope !== null) {
            $collection->addFieldToFilter('scope', $scope);
            $collection->addFieldToFilter('scope_id', $scopeId);
        }

        $rows = [];

        foreach ($collection->getItems() as $configData) {
            $rows[] = [
                'scope' => (string)$configData->getScope(),
                'scope_id' => (int)$configData->getScopeId(),
                'value' => $configData->getValue(),
            ];
        }

        return $rows;
    }
}
