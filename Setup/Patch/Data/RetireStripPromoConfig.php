<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Drops the four settings the print strip promotion used to be written into.
 *
 * The strip now prints a Ben_Marketing scan link, which carries the same wording but sends the scan through the
 * store so it can be counted and redirected. Nothing is carried over: the old fields held a destination that was
 * printed straight into the QR code, which is exactly what the scan link replaces, and the three links the shop
 * wants are seeded ready to fill in by Ben\Marketing\Setup\Patch\Data\SeedScanLinks. The rows are deleted at
 * every scope rather than left unread in core_config_data.
 */
class RetireStripPromoConfig implements DataPatchInterface
{
    /**
     * The paths the giftwrap section used to save the print strip promotion to
     */
    private const CONFIG_XML_PATHS = [
        'giftwrap/settings/promo_line_one',
        'giftwrap/settings/promo_line_two',
        'giftwrap/settings/promo_url',
        'giftwrap/settings/promo_qr_label',
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
        if (!$this->gate->hasModule('Ben_Giftwrap')) {
            return;
        }

        foreach ($this->getScopes() as [$scope, $scopeId]) {
            foreach (self::CONFIG_XML_PATHS as $path) {
                $this->configWriter->delete($path, $scope, $scopeId);
            }
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Giftwrap\Setup\Patch\Data\RetireStripPromoConfig'];
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
}
