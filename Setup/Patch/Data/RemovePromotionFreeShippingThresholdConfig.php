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
 * Moves the promotion copy of the free delivery threshold onto Magento's free shipping carrier, then drops it.
 *
 * The banners read Magento's free shipping carrier now, through Ben\Shipping\Model\FreeShippingThreshold, so
 * what they promise is what the carrier gives. The banner on/off flags stay; only the number moves, and it is
 * carried over to the carrier before it is deleted rather than left in core_config_data unread - a shop that
 * promised free delivery over a number keeps promising it over that number.
 *
 * The carrier wins wherever it already carries a number of its own: an explicitly saved carrier row is the
 * admin's later decision and is never overwritten. Scopes are migrated one for one, so a website that had its
 * own threshold keeps one. The carrier's own on/off flag is left alone; enabling free shipping is a shipping
 * decision, not a data migration (see the pre-upgrade checklist).
 */
class RemovePromotionFreeShippingThresholdConfig implements DataPatchInterface
{
    // The one threshold the shop has now
    private const string CONFIG_XML_PATH_FREE_SHIPPING_SUBTOTAL = 'carriers/freeshipping/free_shipping_subtotal';

    // The path the promotion section used to save its own threshold to
    private const string CONFIG_XML_PATH_FREE_SHIPPING_THRESHOLD = 'promotion/free_shipping/threshold';

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
        if (!$this->gate->hasModule('Ben_Promotion')) {
            return;
        }

        foreach ($this->getScopes() as [$scope, $scopeId]) {
            $this->migrate($scope, $scopeId);
            $this->configWriter->delete(self::CONFIG_XML_PATH_FREE_SHIPPING_THRESHOLD, $scope, $scopeId);
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Promotion\Setup\Patch\Data\RemoveFreeShippingThresholdConfig'];
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
     * Default first, so a website or store that only repeated the default value finds the carrier already set
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

    private function migrate(string $scope, int $scopeId): void
    {
        $threshold = $this->getSavedValue(self::CONFIG_XML_PATH_FREE_SHIPPING_THRESHOLD, $scope, $scopeId);

        if ($threshold === null || (float)$threshold <= 0) {
            return;
        }

        $carrierSubtotal = $this->getSavedValue(self::CONFIG_XML_PATH_FREE_SHIPPING_SUBTOTAL, $scope, $scopeId);

        // A saved carrier value is the admin's later decision, and a saved 0 means free delivery for every order,
        // so anything already there outranks the copy being retired
        if ($carrierSubtotal !== null) {
            return;
        }

        $this->configWriter->save(self::CONFIG_XML_PATH_FREE_SHIPPING_SUBTOTAL, $threshold, $scope, $scopeId);
    }
}
