<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Clean\Model\Config\SavedSetting;
use Ben\Clean\Model\Config\SettingsAudit;
use Ben\Clean\Model\Config\SettingsPurge;
use Ben\Migration\Model\Gate;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes the saved settings 3.0 no longer reads, those of modules that are no longer installed, and the ones
 * that only repeat what their scope inherits.
 *
 * Years of renamed fields, removed extensions and settings saved at their default leave core_config_data full of
 * rows an admin cannot see or that stop a new default from reaching the store. This is bin/magento
 * config:remove-defaults and config:remove-unused, both run once. It waits for every patch here that moves or
 * retires config, because a path those still have to carry across must not be removed first. Rows belonging to a
 * module that is only switched off are never touched, and nothing outside core_config_data is: the setup_module
 * rows and tables a removed module left behind are for config:remove-unused to report and a person to drop.
 *
 * This runs unattended inside setup:upgrade, so two things are true of it. The whole list of what is about to go
 * is written to the log before a single row is deleted, which is the only record there will be of a row nobody
 * chose to lose. And three groups of paths are pinned and never removed whatever the audit says about them:
 * payment methods, carriers and the secure base URLs. A payment or carrier setting the audit calls redundant is
 * still a shop that stops taking money, and a secure URL removed in the release window is a shop served over
 * plain HTTP; the few stale rows those prefixes keep are cheaper than either. They are reported instead, and a
 * person removes them afterwards if they really are dead.
 *
 * Each removed path and scope is logged; the value never is, since some of these rows may once have held a key.
 */
class PurgeRedundantConfig implements DataPatchInterface
{
    /**
     * The path prefixes nothing here removes. Payments and carriers keep a shop trading and web/secure keeps it
     * served over HTTPS, and none of the three is worth a tidy core_config_data
     */
    private const array PINNED_PREFIXES = ['carriers/', 'payment/', 'web/secure/'];

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
            RemoveOffshorePostcodesConfig::class,
            RemovePromotionFreeShippingThresholdConfig::class,
            RepointDesignsToActiveFonts::class,
            RetireStripPromoConfig::class,
            SetPromotionPasswordSecret::class,
            SplitCopyrightYear::class,
        ];
    }

    /**
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Clean')) {
            return;
        }

        $offered = [];
        $pinned = [];

        foreach ($this->settingsAudit->getFindings() as $setting) {
            if ($this->isPinned($setting)) {
                $pinned[] = $setting;

                continue;
            }

            $offered[] = $setting;
        }

        $this->announce($offered, $pinned);

        foreach ($this->settingsPurge->purge($offered) as $setting) {
            $this->logger->info(
                sprintf('Ben_Migration removed saved setting %s: %s', $setting->getLabel(), $setting->getReason())
            );
        }
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * Everything that is about to go, and everything held back, written out before the first row is deleted. A
     * row removed unattended has no other record, so the list goes in the log whether anyone reads it or not
     *
     * @param SavedSetting[] $offered
     * @param SavedSetting[] $pinned
     */
    private function announce(array $offered, array $pinned): void
    {
        $isPurgeable = static fn (SavedSetting $setting): bool => $setting->isPurgeable();
        $removing = array_values(array_filter($offered, $isPurgeable));
        $kept = array_values(array_filter($pinned, $isPurgeable));

        $this->logger->info(sprintf(
            'Ben_Migration is about to remove %d saved setting(s): %s',
            count($removing),
            $this->getList($removing),
        ));

        if ($kept !== []) {
            $this->logger->info(sprintf(
                'Ben_Migration kept %d saved setting(s) the audit would have removed, because payment, carrier and'
                . ' secure URL settings are pinned through the release: %s',
                count($kept),
                $this->getList($kept),
            ));
        }
    }

    /**
     * @param SavedSetting[] $settings
     */
    private function getList(array $settings): string
    {
        if ($settings === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (SavedSetting $setting): string => $setting->getLabel() . ' - ' . $setting->getReason(),
            $settings,
        ));
    }

    private function isPinned(SavedSetting $setting): bool
    {
        foreach (self::PINNED_PREFIXES as $prefix) {
            if (str_starts_with($setting->getPath(), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
