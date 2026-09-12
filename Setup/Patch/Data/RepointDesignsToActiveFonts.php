<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Font\Api\Data\FontInterface;
use Ben\Giftwrap\Api\Data\DesignInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * The font range was cut to the twenty fonts orders actually name, so the designs still opening in one of the
 * fonts that went are moved to Pincher-Brothers, the one most of them already use.
 *
 * Ben_Font cut the range but does not know what letters from it, so each module moves its own rows. A design
 * left naming a font nobody can pick would open in nothing. The store font is a setting rather than data, so
 * a shop pointed at a font that has just gone is told rather than moved.
 */
class RepointDesignsToActiveFonts implements DataPatchInterface
{
    // The font a design falls back to when the one it named has gone
    private const int FALLBACK_FONT_ID = 73;

    // The one font the whole shop writes its own name in, warned about rather than moved
    private const string STORE_FONT_CONFIG_PATH = 'giftwrap/settings/store_font_id';

    private const string TABLE_CONFIG = 'core_config_data';

    private const string TABLE_DESIGN = 'ben_giftwrap_design';

    private const string TABLE_FONT = 'ben_font';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    /**
     * The range has to have been copied into ben_font and cut to twenty before there is anything here to read:
     * the module sequence already puts Ben_Font first, but the order a design's font depends on is said out loud
     */
    public static function getDependencies(): array
    {
        return [KeepTopTwentyFonts::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE_DESIGN)
            || !$this->gate->hasTable(self::TABLE_FONT)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $activeFontIds = $this->getActiveFontIds();

        if (!in_array(self::FALLBACK_FONT_ID, $activeFontIds, true)) {
            $this->logger->warning(sprintf(
                'Giftwrap designs left alone: the fallback font %d is not active in %s',
                self::FALLBACK_FONT_ID,
                self::TABLE_FONT,
            ));

            $this->moduleDataSetup->endSetup();

            return;
        }

        $connection = $this->moduleDataSetup->getConnection();

        $repointed = $connection->update(
            $this->moduleDataSetup->getTable(self::TABLE_DESIGN),
            [DesignInterface::DEFAULT_FONT_ID => self::FALLBACK_FONT_ID],
            [
                $connection->quoteInto(DesignInterface::DEFAULT_FONT_ID . ' NOT IN (?)', $activeFontIds),
            ],
        );

        $this->logger->info(sprintf(
            'Giftwrap designs moved to font %d: %d',
            self::FALLBACK_FONT_ID,
            $repointed,
        ));

        $this->warnAboutStoreFont($activeFontIds);

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Giftwrap\Setup\Patch\Data\RepointDesignsToActiveFonts'];
    }

    /**
     * Every font a customer can still be shown
     *
     * @return int[]
     */
    private function getActiveFontIds(): array
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable(self::TABLE_FONT), FontInterface::FONT_ID)
            ->where(FontInterface::IS_ACTIVE . ' = ?', 1);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * The store font is a setting, not data, so a shop pointed at a font that has gone is told rather than moved
     *
     * @param int[] $activeFontIds
     */
    private function warnAboutStoreFont(array $activeFontIds): void
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable(self::TABLE_CONFIG), ['scope', 'scope_id', 'value'])
            ->where('path = ?', self::STORE_FONT_CONFIG_PATH);

        foreach ($connection->fetchAll($select) as $row) {
            $fontId = (int)$row['value'];

            if ($fontId === 0 || in_array($fontId, $activeFontIds, true)) {
                continue;
            }

            $this->logger->warning(sprintf(
                'Giftwrap store font %d is switched off but is still saved at %s for %s %s',
                $fontId,
                self::STORE_FONT_CONFIG_PATH,
                $row['scope'],
                $row['scope_id'],
            ));
        }
    }
}
