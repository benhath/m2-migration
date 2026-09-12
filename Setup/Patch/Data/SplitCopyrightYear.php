<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The first legal line used to carry the copyright with a year typed into it, which went stale every January.
 * The holder moves to its own field and the year is printed from the clock; a line that did not end in a sign
 * and a year is left as it is. The retired Trustwave url rows go at the same time
 */
class SplitCopyrightYear implements DataPatchInterface
{
    private const COPYRIGHT_PATH = 'footer/legal/copyright';

    private const LINE_ONE_PATH = 'footer/legal/line_one';

    private const TRUSTWAVE_PATH = 'footer/payment/trustwave_url';

    public function __construct(
        private readonly Gate $gate,
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
        if (!$this->gate->hasModule('Ben_Footer')) {
            return;
        }

        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', ['in' => [self::LINE_ONE_PATH, self::TRUSTWAVE_PATH]]);

        foreach ($collection as $configData) {
            $scope = (string)$configData->getScope();
            $scopeId = (int)$configData->getScopeId();

            if ($configData->getPath() === self::TRUSTWAVE_PATH) {
                $this->configWriter->delete(self::TRUSTWAVE_PATH, $scope, $scopeId);
                continue;
            }

            if (preg_match('/^(.*\S)\s*©\s*\d{4}\s*$/u', (string)$configData->getValue(), $matches)) {
                $this->configWriter->save(self::COPYRIGHT_PATH, $matches[1], $scope, $scopeId);
                $this->configWriter->delete(self::LINE_ONE_PATH, $scope, $scopeId);
            }
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Footer\Setup\Patch\Data\SplitCopyrightYear'];
    }
}
