<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\ComingSoon\Setup\Patch\Data\CopyPromotionPassword;
use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Writes the priority access password a shop was already using into the setting that now holds it.
 *
 * The word used to be a constant in the controller, which meant it sat in git and could only be changed by a
 * deploy. It is a Stores > Configuration > Coming Soon > Priority Access field now, kept encrypted like any
 * other credential. Nothing had ever been saved there, so without this a running shop would wake up with no
 * password at all and turn every visitor away. It runs after Ben_ComingSoon has moved across whatever a shop
 * had saved under the old Promotion path, so an admin's own word always wins and this only fills a gap.
 */
class SetPromotionPasswordSecret implements DataPatchInterface
{
    private const CONFIG_XML_PATH_PASSWORD_SECRET = 'coming_soon/password/secret';

    // What the controller compared against until the field existed
    private const PREVIOUS_PASSWORD = 'magic23';

    public function __construct(
        private readonly Gate $gate,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly EncryptorInterface $encryptor,
        private readonly WriterInterface $configWriter,
    ) {
    }

    public static function getDependencies(): array
    {
        return [CopyPromotionPassword::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_ComingSoon')) {
            return;
        }

        if ($this->isAlreadySet()) {
            return;
        }

        $this->configWriter->save(
            self::CONFIG_XML_PATH_PASSWORD_SECRET,
            $this->encryptor->encrypt(self::PREVIOUS_PASSWORD)
        );
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * Whether any scope has already saved a password of its own, which is the admin's decision and stands
     */
    private function isAlreadySet(): bool
    {
        $configDataCollection = $this->configDataCollectionFactory->create();
        $configDataCollection->addFieldToFilter('path', self::CONFIG_XML_PATH_PASSWORD_SECRET);
        $configDataCollection->setPageSize(1);

        return (bool)$configDataCollection->getFirstItem()->getId();
    }
}
