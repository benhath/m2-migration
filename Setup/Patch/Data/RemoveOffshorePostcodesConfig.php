<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Drops the postcode regions the retired DPD import file used to refuse.
 *
 * DPD was the only carrier that ever refused a postcode of its own, and it left with 3.0. The field was taken
 * out of the shipping section before that, so any row still saved under one of these paths is invisible in the
 * admin and read by nothing - and a list of postcodes that looks like a live restriction is worse than no list
 * at all, because the next person to find it will wonder which orders it is stopping. Every carrier's row goes,
 * at every scope, since the setting has no reader left whichever carrier it names.
 *
 * Nothing is migrated: the shop refuses nothing by postcode now, and a carrier that should is a shipping
 * restriction rule, which is a screen an admin can use.
 */
class RemoveOffshorePostcodesConfig implements DataPatchInterface
{
    // What the shipping section used to save a carrier's refused postcode regions to, one row per carrier
    private const CONFIG_XML_PATH_PATTERN = 'shipping_api/carrier_%/offshore_postcodes';

    public function __construct(
        private readonly Gate $gate,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Shipping')) {
            return;
        }

        $configDataCollection = $this->configDataCollectionFactory->create();
        $configDataCollection->addFieldToFilter('path', ['like' => self::CONFIG_XML_PATH_PATTERN]);

        $removed = 0;

        foreach ($configDataCollection as $configData) {
            $path = (string)$configData->getPath();

            $this->configWriter->delete($path, (string)$configData->getScope(), (int)$configData->getScopeId());
            $removed++;

            // The value is a postcode list rather than anything private, so the log can say what was dropped
            $this->logger->info(sprintf(
                'Ben_Migration removed saved setting %s at %s %d',
                $path,
                (string)$configData->getScope(),
                (int)$configData->getScopeId()
            ));
        }

        $this->logger->info($removed === 1
            ? 'Ben_Migration removed 1 offshore postcode setting'
            : sprintf('Ben_Migration removed %d offshore postcode settings', $removed));
    }

    public function getAliases(): array
    {
        return [];
    }
}
