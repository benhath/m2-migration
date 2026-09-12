<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Font\Api\Data\FontInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The fonts the designer used to hide with a hard coded exclusion list are switched off with the new Enabled
 * flag. Every other font keeps the schema default of enabled, and nothing else about a row is touched.
 *
 * The ids came from one site's list, so each is matched together with the name that id carries there: a store
 * whose id 16 is a different font, or which never had these fonts at all, is left exactly as it is rather than
 * losing a font its customers can still pick.
 */
class DisableRetiredFonts implements DataPatchInterface
{
    // The fonts the frontend source used to exclude, by the id they hold, so an id alone is never enough
    private const array RETIRED_FONT_NAMES_BY_ID = [
        16 => 'LifeSavers',
        29 => 'Armstrong',
        37 => 'Capella',
        41 => 'Cherston',
        43 => 'Clarins 2',
        46 => 'Enigma',
        48 => 'Faberge',
        53 => 'Gron',
        59 => 'Lestina',
        81 => 'Santa-Monica',
        83 => 'Selna',
        84 => 'Senar',
        85 => 'Sensal',
    ];

    private const string TABLE = 'ben_font';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [CopyGiftwrapFonts::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        foreach (self::RETIRED_FONT_NAMES_BY_ID as $fontId => $name) {
            $connection->update(
                $table,
                [FontInterface::IS_ACTIVE => 0],
                [
                    FontInterface::FONT_ID . ' = ?' => $fontId,
                    FontInterface::NAME . ' = ?' => $name,
                ],
            );
        }

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Font\Setup\Patch\Data\DisableRetiredFonts'];
    }
}
