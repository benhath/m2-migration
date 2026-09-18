<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings the priority access gate's settings over from Ben_Promotion, where they used to live, and makes sure
 * the shop ends up with a password.
 *
 * The form belongs to the holding page, so it moved to Ben_ComingSoon with it, and the word itself used to be a
 * constant in the controller, which meant it sat in git and could only be changed by a deploy. It is a Stores >
 * Configuration > Coming Soon > Priority Access field now, kept encrypted like any other credential. Whatever a
 * shop had saved under the old Promotion path is copied exactly as it sits in the table - still encrypted,
 * never decrypted on the way past - along with the two messages and the switch, in every scope it was set in,
 * and only where nothing has been set here already, so anything typed in the new section stands.
 *
 * A shop that had nothing to carry over is given a random word rather than one written down here, and told in
 * the log that it has one: a gate with no password turns every visitor away, and a password in the repository
 * is no password at all. The word itself is never logged - it is read and replaced at Priority Access.
 *
 * It runs before the redundant configuration purge, which is what declares the order: the purge names this
 * patch, so the old Promotion rows are still there to be read when this runs
 */
class SetPromotionPasswordSecret implements DataPatchInterface
{
    private const CONFIG_XML_PATH_PASSWORD_SECRET = 'coming_soon/password/secret';

    private const PASSWORD_LENGTH = 20;

    // The old path each setting was saved under, and where the same setting is read from now
    private const PATHS = [
        'promotion/password/enabled' => 'coming_soon/password/enabled',
        'promotion/password/granted_message' => 'coming_soon/password/granted_message',
        'promotion/password/incorrect_message' => 'coming_soon/password/incorrect_message',
        'promotion/password/secret' => self::CONFIG_XML_PATH_PASSWORD_SECRET,
    ];

    public function __construct(
        private readonly Gate $gate,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly Random $random,
        private readonly WriterInterface $configWriter,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @throws LocalizedException
     */
    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_ComingSoon')) {
            return;
        }

        foreach (self::PATHS as $from => $to) {
            $this->copy($from, $to);
        }

        if ($this->isAlreadySet(self::CONFIG_XML_PATH_PASSWORD_SECRET)) {
            return;
        }

        $this->configWriter->save(
            self::CONFIG_XML_PATH_PASSWORD_SECRET,
            $this->encryptor->encrypt($this->random->getRandomString(self::PASSWORD_LENGTH))
        );

        $this->logger->info(
            'SetPromotionPasswordSecret: no priority access password was carried over, so a random one was set. '
            . 'Read and replace it at Stores > Configuration > Coming Soon > Priority Access.'
        );
    }

    public function getAliases(): array
    {
        return ['Ben\ComingSoon\Setup\Patch\Data\CopyPromotionPassword'];
    }

    /**
     * Every scope the old value was set in, copied to the same scope here
     */
    private function copy(string $from, string $to): void
    {
        if ($this->isAlreadySet($to)) {
            return;
        }

        $configDataCollection = $this->configDataCollectionFactory->create();
        $configDataCollection->addFieldToFilter('path', $from);

        foreach ($configDataCollection as $configData) {
            $this->configWriter->save(
                $to,
                (string)$configData->getData('value'),
                (string)$configData->getData('scope'),
                (int)$configData->getData('scope_id')
            );
        }
    }

    /**
     * Whether any scope has already saved a value of its own here, which is the admin's decision and stands
     */
    private function isAlreadySet(string $path): bool
    {
        $configDataCollection = $this->configDataCollectionFactory->create();
        $configDataCollection->addFieldToFilter('path', $path);
        $configDataCollection->setPageSize(1);

        return (bool)$configDataCollection->getFirstItem()->getId();
    }
}
