<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Font\Api\Data\FontInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * The font range is cut to the twenty fonts customers actually order, everything else switched off.
 *
 * A long list nobody picks from is a slower page and a harder choice, so the range is the twenty the orders
 * name and no more. The earlier retirement stays where it is: Gron and LifeSavers were already off and are not
 * on this list either.
 *
 * The ids came from one site's list, so each is matched together with the name that id carries there, and the
 * whole patch only runs when the table is recognisably that list - at least fifteen of the twenty present under
 * their own name. A fresh install, or a shop that built its own font range, is left alone with a warning rather
 * than having its range cut to twenty fonts it does not have.
 *
 * What the switched off fonts leave behind is each module's own to answer for: Ben_Giftwrap moves the designs
 * that named one, in its own patch, because the catalogue does not know what letters from it.
 */
class KeepTopTwentyFonts implements DataPatchInterface
{
    // The twenty fonts the orders name, by the id they hold, so an id alone is never enough
    private const array KEPT_FONT_NAMES_BY_ID = [
        2 => 'Big-Snow',
        3 => 'Bungee',
        9 => 'Frauncess',
        10 => 'Hamish',
        13 => 'Launica',
        15 => 'Leckerli',
        17 => 'Londrina',
        18 => 'Lunarie',
        23 => 'Teen',
        33 => 'Blauer',
        36 => 'Bunny-Hop',
        65 => 'Marmaris',
        73 => 'Pincher-Brothers',
        80 => 'Sanremo',
        82 => 'Selatine',
        88 => 'Sugar-Cake',
        90 => 'Buchen',
        94 => 'Wild-Nebraska',
        95 => 'Wild Nebraska 2',
        98 => 'Baskerville',
    ];

    // How many of the twenty have to be there under their own name before the table is taken as the known one
    private const int MINIMUM_RECOGNISED = 15;

    private const string TABLE_FONT = 'ben_font';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [DisableRetiredFonts::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE_FONT)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $keptFontIds = $this->getKeptFontIds();

        if (count($keptFontIds) < self::MINIMUM_RECOGNISED) {
            $this->logger->warning(sprintf(
                'Font range left alone: only %d of the %d expected fonts are in %s under their own name',
                count($keptFontIds),
                count(self::KEPT_FONT_NAMES_BY_ID),
                self::TABLE_FONT,
            ));

            $this->moduleDataSetup->endSetup();

            return;
        }

        $enabled = $this->setActive($keptFontIds, true);
        $disabled = $this->setActive($keptFontIds, false);

        $this->logger->info(sprintf(
            'Font range cut to %d fonts: %d switched on, %d switched off',
            count($keptFontIds),
            $enabled,
            $disabled,
        ));

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Font\Setup\Patch\Data\KeepTopTwentyFonts'];
    }

    /**
     * The ids from the list that really are in the table under the name the list gives them
     *
     * @return int[]
     */
    private function getKeptFontIds(): array
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable(self::TABLE_FONT), [FontInterface::FONT_ID, FontInterface::NAME])
            ->where(FontInterface::FONT_ID . ' IN (?)', array_keys(self::KEPT_FONT_NAMES_BY_ID));

        $keptFontIds = [];

        foreach ($connection->fetchPairs($select) as $fontId => $name) {
            if ((self::KEPT_FONT_NAMES_BY_ID[(int)$fontId] ?? null) === $name) {
                $keptFontIds[] = (int)$fontId;
            }
        }

        return $keptFontIds;
    }

    /**
     * Switches the kept fonts on, or everything else off, counting only the rows that were not already that way
     *
     * @param int[] $keptFontIds
     */
    private function setActive(array $keptFontIds, bool $isActive): int
    {
        $connection = $this->moduleDataSetup->getConnection();

        return $connection->update(
            $this->moduleDataSetup->getTable(self::TABLE_FONT),
            [FontInterface::IS_ACTIVE => $isActive ? 1 : 0],
            [
                FontInterface::IS_ACTIVE . ' = ?' => $isActive ? 0 : 1,
                $connection->quoteInto(
                    FontInterface::FONT_ID . ($isActive ? ' IN (?)' : ' NOT IN (?)'),
                    $keptFontIds,
                ),
            ],
        );
    }
}
