<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Moves the saved face service settings to Stores > Configuration > AI > Face V2, then drops what is left.
 *
 * The face service is a model call like any other - it is recorded in the same generation log - so its settings
 * now live beside the rest of them, with a timeout, a size limit and a send size the giftwrap section never had.
 * A shop that had been pointed at a service keeps pointing at it.
 *
 * Two sets of old paths are read. `giftwrap/faceout/*` is what live saves today. `ai/faceout/*` only ever existed
 * on a development machine, between the settings moving into the AI section and the service being renamed, so it
 * is read for that machine's sake and is nothing live will have.
 *
 * The API key goes altogether: the service is only ever reached over the internal network, nothing sends a key
 * any more, and an encrypted secret nobody reads is worth deleting rather than leaving in core_config_data. The
 * queue wait goes with it: how long a scan waits for a free slot is the service's own setting now, and Magento
 * only reads the 503 it answers with. A value already saved at the new path is the admin's later decision and is
 * never overwritten, so running this twice moves nothing the second time.
 */
class MoveFaceV2Config implements DataPatchInterface
{
    // Where each setting was saved before, and the path it is read from now. The AI paths come first: where a
    // development machine has both, its AI row is the later of the two
    private const MOVED_PATHS
        = [
            'ai/faceout/url' => 'ai/face_v2/url',
            'ai/faceout/timeout_seconds' => 'ai/face_v2/timeout_seconds',
            'ai/faceout/max_upload_mb' => 'ai/face_v2/max_upload_mb',
            'ai/faceout/max_long_edge_px' => 'ai/face_v2/max_long_edge_px',
            'giftwrap/faceout/url' => 'ai/face_v2/url',
        ];

    // Settings that are not moved anywhere: nothing reads them any more
    private const RETIRED_PATHS
        = [
            'ai/faceout/queue_wait_seconds',
            'giftwrap/faceout/api_key',
        ];

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
        if (!$this->gate->hasModule('Ben_Giftwrap')) {
            return;
        }

        foreach ($this->getScopes() as [$scope, $scopeId]) {
            foreach (self::MOVED_PATHS as $oldPath => $newPath) {
                $this->migrate($oldPath, $newPath, $scope, $scopeId);
                $this->configWriter->delete($oldPath, $scope, $scopeId);
            }

            foreach (self::RETIRED_PATHS as $retiredPath) {
                $this->configWriter->delete($retiredPath, $scope, $scopeId);
            }
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Giftwrap\Setup\Patch\Data\MoveFaceV2Config'];
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
     * Default first, so a website or store that only repeated the default value finds the new path already set
     * and adds no row of its own
     *
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

    private function migrate(string $oldPath, string $newPath, string $scope, int $scopeId): void
    {
        $value = $this->getSavedValue($oldPath, $scope, $scopeId);

        if ($value === null || trim($value) === '') {
            return;
        }

        // Whatever the new path already holds is newer than the field being retired
        if ($this->getSavedValue($newPath, $scope, $scopeId) !== null) {
            return;
        }

        $this->configWriter->save($newPath, $value, $scope, $scopeId);
    }
}
