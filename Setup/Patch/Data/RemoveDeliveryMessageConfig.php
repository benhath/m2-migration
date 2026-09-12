<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Drops the three fixed delivery sentences the banner used to pick between.
 *
 * The banner works the answer out now - dispatch day and a delivery window from the cut off, the working days
 * and the carrier's transit - so wording that only ever said "dispatch tomorrow" has nothing left to say and
 * would otherwise sit unread in core_config_data. Nothing is migrated: the new lines are written by the module.
 */
class RemoveDeliveryMessageConfig implements DataPatchInterface
{
    // The paths the delivery group used to save its fixed messages to
    private const CONFIG_XML_PATHS = [
        'promotion/delivery/ship_after_weekend_message',
        'promotion/delivery/ship_same_day_message',
        'promotion/delivery/ship_tomorrow_message',
    ];

    public function __construct(
        private readonly Gate $gate,
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

        foreach (self::CONFIG_XML_PATHS as $path) {
            $this->configWriter->delete($path);

            foreach ($this->storeManager->getWebsites() as $website) {
                $this->configWriter->delete($path, ScopeInterface::SCOPE_WEBSITES, (int)$website->getId());
            }

            foreach ($this->storeManager->getStores() as $store) {
                $this->configWriter->delete($path, ScopeInterface::SCOPE_STORES, (int)$store->getId());
            }
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Promotion\Setup\Patch\Data\RemoveDeliveryMessageConfig'];
    }
}
