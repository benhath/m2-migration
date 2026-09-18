<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Color\Api\Data\ColorInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Black and white go into the colour catalogue.
 *
 * The giftwrap range is all colours and never needed either, but words on a card and words over a photograph are
 * black far more often than anything else and white wherever the picture behind them is dark, and both were
 * written into the app rather than held anywhere a shop could see them. They are rows now, so the same two
 * colours are named the same on a card, on a print and on a roll of paper.
 *
 * A shop whose catalogue already holds either shade keeps what it has: the hex is what makes a colour, not the
 * name, so a second run and a shop that added its own black both come out with one row rather than two.
 */
class AddPlainTextColors implements DataPatchInterface
{
    private const array COLORS
        = [
            ['name' => 'Black', 'hex_code' => '#111111', 'sort_order' => 1],
            ['name' => 'White', 'hex_code' => '#ffffff', 'sort_order' => 2],
        ];

    private const string TABLE = 'ben_color';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    /**
     * The giftwrap rows keep the ids they hold, so they are copied before anything new is given one
     */
    public static function getDependencies(): array
    {
        return [CopyGiftwrapColors::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        $added = 0;

        foreach (self::COLORS as $color) {
            $select = $connection->select()
                ->from($table, ColorInterface::COLOR_ID)
                ->where(ColorInterface::HEX_CODE . ' = ?', $color[ColorInterface::HEX_CODE]);

            if (!$connection->fetchOne($select)) {
                $connection->insert($table, $color);
                $added++;
            }
        }

        $this->logger->info(sprintf('Colour catalogue: %d of black and white added', $added));

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }
}
