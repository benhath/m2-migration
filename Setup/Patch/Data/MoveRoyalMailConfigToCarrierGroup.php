<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Royal Mail now has a carrier group of its own, `shipping_api/carrier_rm`, alongside every other carrier, so the
 * settings saved under the old `shipping_api/royal_mail` group move across at every scope that had its own answer
 * and the old rows go. The client secret was held in the clear and is encrypted on the way over. A value already
 * saved under the new name is the admin's later decision and is never overwritten, so running this twice moves
 * nothing the second time
 */
class MoveRoyalMailConfigToCarrierGroup implements DataPatchInterface
{
    private const FIELDS = [
        'client_id',
        self::SECRET_FIELD,
        'department',
        'shipping_account_id',
        'shipping_location_id',
        'token',
        'token_expiry_time',
    ];

    private const NEW_GROUP = 'shipping_api/carrier_rm/';

    // Rows left behind by the basic-auth credentials the API used before OAuth: nothing reads them, so they go
    private const OBSOLETE_FIELDS = ['password', 'username'];

    private const OLD_GROUP = 'shipping_api/royal_mail/';

    // The field held in the clear before the move and encrypted after it
    private const SECRET_FIELD = 'client_secret';

    public function __construct(
        private readonly Gate $gate,
        private readonly ConfigDataCollectionFactory $configDataCollectionFactory,
        private readonly EncryptorInterface $encryptor,
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
        if (!$this->gate->hasModule('Ben_Shipping')) {
            return;
        }

        foreach ($this->getScopes() as [$scope, $scopeId]) {
            foreach (self::FIELDS as $field) {
                $this->migrate($field, $scope, $scopeId);
                $this->configWriter->delete(self::OLD_GROUP . $field, $scope, $scopeId);
            }

            foreach (self::OBSOLETE_FIELDS as $field) {
                $this->configWriter->delete(self::OLD_GROUP . $field, $scope, $scopeId);
            }
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Shipping\Setup\Patch\Data\MoveRoyalMailConfigToCarrierGroup'];
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

    private function migrate(string $field, string $scope, int $scopeId): void
    {
        $value = $this->getSavedValue(self::OLD_GROUP . $field, $scope, $scopeId);

        if ($value === null || $this->getSavedValue(self::NEW_GROUP . $field, $scope, $scopeId) !== null) {
            return;
        }

        if ($field === self::SECRET_FIELD && $value !== '') {
            $value = $this->encryptor->encrypt($value);
        }

        $this->configWriter->save(self::NEW_GROUP . $field, $value, $scope, $scopeId);
    }
}
