<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Ben\Product\Model\Source\PrintWorkflow;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns the old Device Enabled yes/no into the print workflow it always meant: yes was the print room app pulling
 * files through the API, no was rendering them on order for download. Every scope that had its own answer keeps
 * it under the new name, then the old row goes. A workflow already saved is the admin's later decision and is
 * never overwritten, so running this twice moves nothing the second time
 */
class MigrateDeviceActiveConfig implements DataPatchInterface
{
    private const NEW_PATH = 'product/settings/print_workflow';

    private const OLD_PATH = 'product/settings/device_active';

    public function __construct(
        private readonly Gate $gate,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Product')) {
            return;
        }

        foreach ($this->getScopes() as [$scope, $scopeId]) {
            $this->migrate($scope, $scopeId);
            $this->configWriter->delete(self::OLD_PATH, $scope, $scopeId);
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Product\Setup\Patch\Data\MigrateDeviceActiveConfig'];
    }

    /**
     * The value explicitly saved at one scope, ignoring what the scope inherits; null when the scope has no
     * row of its own
     */
    private function getSavedValue(string $path, string $scope, int $scopeId): ?string
    {
        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', $path);
        $collection->addFieldToFilter('scope', $scope);
        $collection->addFieldToFilter('scope_id', $scopeId);
        $collection->setPageSize(1);

        $configData = $collection->getFirstItem();

        return $configData->getId() ? (string)$configData->getValue() : null;
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function getScopes(): array
    {
        $scopes = [[ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]];

        foreach ($this->storeManager->getWebsites() as $website) {
            $scopes[] = [ScopeInterface::SCOPE_WEBSITES, (int)$website->getId()];
        }

        foreach ($this->storeManager->getStores() as $store) {
            $scopes[] = [ScopeInterface::SCOPE_STORES, (int)$store->getId()];
        }

        return $scopes;
    }

    private function migrate(string $scope, int $scopeId): void
    {
        $deviceActive = $this->getSavedValue(self::OLD_PATH, $scope, $scopeId);

        if ($deviceActive === null || $this->getSavedValue(self::NEW_PATH, $scope, $scopeId) !== null) {
            return;
        }

        $workflow = $deviceActive === '1' ? PrintWorkflow::WORKFLOW_API : PrintWorkflow::WORKFLOW_DOWNLOAD;

        $this->configWriter->save(self::NEW_PATH, $workflow, $scope, $scopeId);
    }
}
