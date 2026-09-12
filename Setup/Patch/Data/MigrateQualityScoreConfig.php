<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Brings saved Designer > Quality settings over to the points scoring.
 *
 * The scoring used to interpolate a score between three PPI thresholds per print profile and then take
 * penalties off it; it is a resolution score plus signed adjustments now, sorted into the Score, Detection,
 * Bands and Messages sub-sections, and giftwrap is scored as a photograph like everything else. The fields that
 * mean the same thing under a new name keep whatever an admin saved, in every scope they saved it in, and a
 * penalty carries over as the negative number it always was. The fields that have gone, the older Quality
 * tool's thresholds and wording among them, are dropped so nothing is left behind in core_config_data. A
 * destination an admin has already answered is never overwritten, and a second run has nothing left to do.
 */
class MigrateQualityScoreConfig implements DataPatchInterface
{
    /**
     * Where each old penalty's value belongs now. A penalty was typed as the points it took off, so the value
     * moves across with its sign turned around
     */
    private const NEGATED_PATHS = [
        'designer/quality/penalty_blurry' => 'designer/quality/score/score_blurry',
        'designer/quality/penalty_compressed' => 'designer/quality/score/score_compressed',
        'designer/quality/penalty_screenshot' => 'designer/quality/score/score_screenshot',
        'designer/quality/penalty_sharpness_fair' => 'designer/quality/score/score_soft',
        'designer/quality/penalty_sharpness_weak' => 'designer/quality/score/score_blurry',
        'designer/quality/penalty_soft' => 'designer/quality/score/score_soft',
        'designer/quality/penalty_tiny_file' => 'designer/quality/score/score_tiny_file',
        'designer/quality/penalty_upscaled' => 'designer/quality/score/score_upscaled',
    ];

    // The paths that have gone entirely, saved values and all
    private const REMOVED_PATHS = [
        'designer/quality/giftwrap_fair_ppi',
        'designer/quality/giftwrap_good_ppi',
        'designer/quality/giftwrap_weak_ppi',
        'designer/quality/photo_fair_ppi',
        'designer/quality/screenshot_ppi_multiplier',
        'designer/quality_legacy/file_size_bad_mb',
        'designer/quality_legacy/image_bad_mb_per_m2',
        'designer/quality_legacy/image_good_mb_per_m2',
        'designer/quality_legacy/message_fair',
        'designer/quality_legacy/message_ok',
        'designer/quality_legacy/message_weak',
    ];

    /**
     * Where each old path's value belongs now: the same setting, renamed to read as points rather than bands
     * and moved into the sub-section it belongs to. The four bands have shifted a name along, so the messages
     * are moved in order, top band first, and the order they are listed in is the order they are applied in
     */
    private const RENAMED_PATHS = [
        'designer/quality/message_good' => 'designer/quality/messages/message_excellent',
        'designer/quality/message_ok' => 'designer/quality/messages/message_good',
        'designer/quality/message_weak' => 'designer/quality/messages/message_poor',
        'designer/quality/band_excellent_from' => 'designer/quality/bands/band_excellent_from',
        'designer/quality/band_fair_from' => 'designer/quality/bands/band_fair_from',
        'designer/quality/band_good_from' => 'designer/quality/bands/band_good_from',
        'designer/quality/bonus_camera' => 'designer/quality/score/score_camera',
        'designer/quality/bonus_high_quality' => 'designer/quality/score/score_high_quality',
        'designer/quality/bonus_sharp' => 'designer/quality/score/score_sharp',
        'designer/quality/jpeg_quality_high' => 'designer/quality/detection/jpeg_quality_high',
        'designer/quality/jpeg_quality_low' => 'designer/quality/detection/jpeg_quality_low',
        'designer/quality/jpeg_quality_min' => 'designer/quality/detection/jpeg_quality_low',
        'designer/quality/message_excellent' => 'designer/quality/messages/message_excellent',
        'designer/quality/message_fair' => 'designer/quality/messages/message_fair',
        'designer/quality/message_poor' => 'designer/quality/messages/message_poor',
        'designer/quality/min_bytes' => 'designer/quality/detection/min_bytes',
        'designer/quality/photo_good_ppi' => 'designer/quality/score/ppi_good',
        'designer/quality/photo_weak_ppi' => 'designer/quality/score/ppi_poor',
        'designer/quality/ppi_excellent' => 'designer/quality/score/ppi_excellent',
        'designer/quality/ppi_good' => 'designer/quality/score/ppi_good',
        'designer/quality/ppi_poor' => 'designer/quality/score/ppi_poor',
        'designer/quality/sharpness_fair' => 'designer/quality/detection/sharpness_fair',
        'designer/quality/sharpness_poor' => 'designer/quality/detection/sharpness_poor',
        'designer/quality/sharpness_weak' => 'designer/quality/detection/sharpness_poor',
        'designer/quality/upscale_ratio' => 'designer/quality/detection/upscale_ratio',
        'designer/quality/upscale_ratio_flag' => 'designer/quality/detection/upscale_ratio',
    ];

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
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
        if (!$this->gate->hasModule('Ben_Designer')) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $this->move(self::RENAMED_PATHS, false);
        $this->move(self::NEGATED_PATHS, true);

        foreach (self::REMOVED_PATHS as $path) {
            foreach ($this->getSavedRows($path) as $row) {
                $this->configWriter->delete($path, (string)$row['scope'], (int)$row['scope_id']);
            }
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Designer\Setup\Patch\Data\MigrateQualityScoreConfig'];
    }

    /**
     * The rows explicitly saved for a path, ignoring what config.xml declares, in every scope or in one
     *
     * @return array[] each with scope, scope_id and value
     */
    private function getSavedRows(string $path, ?string $scope = null, ?int $scopeId = null): array
    {
        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', $path);

        if ($scope !== null) {
            $collection->addFieldToFilter('scope', $scope);
            $collection->addFieldToFilter('scope_id', $scopeId);
        }

        $rows = [];

        foreach ($collection->getItems() as $configData) {
            $rows[] = [
                'scope' => (string)$configData->getScope(),
                'scope_id' => (int)$configData->getScopeId(),
                'value' => $configData->getValue(),
            ];
        }

        return $rows;
    }

    /**
     * @param array $paths old path to new path
     * @param bool $isNegated whether the value is a count of points to take off, so it changes sign on the way
     */
    private function move(array $paths, bool $isNegated): void
    {
        foreach ($paths as $oldPath => $newPath) {
            foreach ($this->getSavedRows($oldPath) as $row) {
                $scope = (string)$row['scope'];
                $scopeId = (int)$row['scope_id'];

                // An admin who has already answered the new field keeps their answer
                if (!$this->getSavedRows($newPath, $scope, $scopeId)) {
                    $this->configWriter->save(
                        $newPath,
                        $isNegated ? $this->negate((string)$row['value']) : (string)$row['value'],
                        $scope,
                        $scopeId
                    );
                }

                $this->configWriter->delete($oldPath, $scope, $scopeId);
            }
        }
    }

    /**
     * The same number of points, taken off instead of added on, without a minus sign in front of a zero
     */
    private function negate(string $value): string
    {
        $negated = -(float)$value;

        return $negated === 0.0 ? '0' : (string)$negated;
    }
}
