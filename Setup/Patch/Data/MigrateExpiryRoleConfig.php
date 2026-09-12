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
 * Carries the saved expiry numbers over to the one number per role the group has now
 *
 * Asset > Expiry used to hold a field per situation and a second field per keep alive, so the same asset had two
 * numbers depending on which code path last looked at it. There is one number per role now, and where two old
 * fields fed one role the larger of the two wins: a shop that promised to keep cart photos for thirty days keeps
 * promising thirty, not the twenty one the shorter field held.
 *
 * The old rows are deleted afterwards rather than left in core_config_data unread. Nothing here reads config
 * through the scope tree: only a row a scope saved for itself is carried, so a website that overrode one number
 * keeps its override and one that never did stays on the default.
 */
class MigrateExpiryRoleConfig implements DataPatchInterface
{
    // Old paths with nothing to carry: sticker sheets never expire now, and the waiting states are a fixed list
    private const DROPPED_PATHS = [
        'asset/expiry/ai_sheet_days',
        'asset/expiry/order_states',
    ];

    // New path => the old paths that fed it, largest saved number wins
    private const MIGRATED_PATHS = [
        'asset/expiry/cart_days' => ['asset/expiry/cart_days', 'asset/expiry/cart_keep_alive_days'],
        'asset/expiry/order_days' => ['asset/expiry/production_days', 'asset/expiry/order_keep_alive_days'],
        'asset/expiry/giftwrap_preview_hours' => ['asset/expiry/giftwrap_tile_hours'],
        'asset/expiry/feed_days' => ['asset/expiry/feed_image_days'],
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
        if (!$this->gate->hasModule('Ben_Asset')) {
            return;
        }

        foreach ($this->getScopes() as [$scope, $scopeId]) {
            foreach (self::MIGRATED_PATHS as $newPath => $oldPaths) {
                $this->migrate($newPath, $oldPaths, $scope, $scopeId);
            }

            foreach ($this->getRetiredPaths() as $path) {
                $this->configWriter->delete($path, $scope, $scopeId);
            }
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Asset\Setup\Patch\Data\MigrateExpiryRoleConfig'];
    }

    /**
     * Every old path, whether it fed a new one or not
     *
     * @return string[]
     */
    private function getRetiredPaths(): array
    {
        $paths = self::DROPPED_PATHS;

        foreach (self::MIGRATED_PATHS as $newPath => $oldPaths) {
            foreach ($oldPaths as $oldPath) {
                if ($oldPath !== $newPath) {
                    $paths[] = $oldPath;
                }
            }
        }

        return $paths;
    }

    /**
     * The value explicitly saved at one scope, ignoring what the scope inherits; null when the scope has no row
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
     * Default first, so a website or store that only repeated the default finds it already carried over
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

    /**
     * @param string[] $oldPaths
     */
    private function migrate(string $newPath, array $oldPaths, string $scope, int $scopeId): void
    {
        $largest = null;

        foreach ($oldPaths as $oldPath) {
            $saved = $this->getSavedValue($oldPath, $scope, $scopeId);

            if ($saved === null) {
                continue;
            }

            // Zero is the longest life there is, not the shortest, so it wins outright
            if ((int)$saved === 0) {
                $largest = 0;
                break;
            }

            $largest = $largest === null ? (int)$saved : max($largest, (int)$saved);
        }

        // Nothing was ever saved at this scope, so the scope keeps inheriting and needs no row of its own
        if ($largest === null) {
            return;
        }

        $this->configWriter->save($newPath, (string)$largest, $scope, $scopeId);
    }
}
