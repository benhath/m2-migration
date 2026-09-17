<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Drops saved feed wording that still spells out roll lengths and paper width by hand.
 *
 * The feed fills those in from the size tool and the width setting now, so a saved sentence that says "1 to
 * 5 metres" or "700 mm" is not a choice an admin made but a copy of the old default that has since drifted from
 * what the shop sells. Only a saved value carrying one of those phrases goes; wording somebody wrote themselves
 * is left alone, and the module's own defaults take over where a row is removed.
 */
class RetireOldFeedWording implements DataPatchInterface
{
    // The feed texts that used to carry the numbers themselves
    private const array CONFIG_XML_PATHS = [
        'giftwrap/google/description',
        'giftwrap/google/ai_description_line',
        'giftwrap/google/description_extra',
        'giftwrap/google/highlights',
    ];

    // What the old wording said, and no placeholder-filled wording ever will
    private const array OLD_PHRASES = ['1 to 5 metres', '700 mm', '1, 2, 3, 4 or 5 metres'];

    public function __construct(
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
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

        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', ['in' => self::CONFIG_XML_PATHS]);
        $removed = 0;

        foreach ($collection as $configData) {
            $value = (string)$configData->getValue();

            if (!$this->isOldWording($value)) {
                continue;
            }

            $this->configWriter->delete($configData->getPath(), $configData->getScope(), (int)$configData->getScopeId());
            $removed++;
        }

        $this->logger->info(sprintf('Feed wording: %d saved texts with the old numbers removed', $removed));
    }

    public function getAliases(): array
    {
        return [];
    }

    private function isOldWording(string $value): bool
    {
        foreach (self::OLD_PHRASES as $phrase) {
            if (str_contains($value, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
