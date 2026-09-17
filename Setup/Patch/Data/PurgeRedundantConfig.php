<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Clean\Model\Config\SettingsAudit;
use Ben\Clean\Model\Config\SettingsPurge;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes the saved settings 3.0 no longer reads and the ones that only repeat what their scope inherits.
 *
 * Years of renamed fields and settings saved at their default leave core_config_data full of rows an admin cannot
 * see or that stop a new default from reaching the store. This is bin/magento ben:config:tidy --purge, run once.
 * It waits for every patch here that moves or retires config, because a path those still have to carry across
 * must not be removed first. Rows under sections no enabled module declares are never touched. Each removed path
 * and scope is logged; the value never is, since some of these rows may once have held a key.
 */
class PurgeRedundantConfig implements DataPatchInterface
{
    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly SettingsAudit $settingsAudit,
        private readonly SettingsPurge $settingsPurge,
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            MigrateDeviceActiveConfig::class,
            MigrateExpiryRoleConfig::class,
            MigrateGalleryEndpointConfig::class,
            MigrateQualityScoreConfig::class,
            MoveFaceV2Config::class,
            MoveRoyalMailConfigToCarrierGroup::class,
            RemoveDeliveryMessageConfig::class,
            RemoveGiftwrapFreeShippingThresholdConfig::class,
            RemoveGiftwrapSizeFreeShippingThresholdOption::class,
            RemoveGlobalGalleryToolOptions::class,
            RemovePromotionFreeShippingThresholdConfig::class,
            RepointDesignsToActiveFonts::class,
            RetireStripPromoConfig::class,
            SetPromotionPasswordSecret::class,
            SplitCopyrightYear::class,
        ];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Clean')) {
            return;
        }

        foreach ($this->settingsPurge->purge($this->settingsAudit->getFindings()) as $setting) {
            $this->logger->info(sprintf('Ben_Migration removed saved setting %s: %s', $setting->getLabel(), $setting->getReason()));
        }
    }

    public function getAliases(): array
    {
        return [];
    }
}
