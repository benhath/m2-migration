<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Color\Api\Data\ColorInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * The colour catalogue moves out of Ben_Giftwrap and into its own table, so the cards, the art range and the
 * photo text can be printed from it without every one of them depending on the giftwrap module.
 *
 * Each row keeps the id it already holds, because that id is what a design's default colour names and what
 * every giftwrap order already placed recorded as the colour its message is printed in; the print is drawn by
 * loading that id, so a copy that renumbered would reprint old orders in the wrong colour.
 *
 * A row already in the new table is left exactly as it is, so the patch can be run again safely. The old table
 * is not dropped here, because this patch is already recorded on the databases that ran it and would never run
 * again. KeyDesignsToColorCatalogue drops it, once it has checked the rows are in the new one.
 */
class CopyGiftwrapColors implements DataPatchInterface
{
    private const string TABLE_NEW = 'ben_color';

    private const string TABLE_OLD = 'ben_giftwrap_color';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE_NEW)) {
            return;
        }

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $oldTable = $this->moduleDataSetup->getTable(self::TABLE_OLD);

        if (!$connection->isTableExists($oldTable)) {
            $this->moduleDataSetup->endSetup();

            return;
        }

        $columns = [
            ColorInterface::COLOR_ID,
            ColorInterface::NAME,
            ColorInterface::HEX_CODE,
            ColorInterface::SORT_ORDER,
        ];

        // The giftwrap table never had an Enabled flag; the new table's default (enabled) stands in for it
        if ($this->gate->hasColumn(self::TABLE_OLD, ColorInterface::IS_ACTIVE)) {
            $columns[] = ColorInterface::IS_ACTIVE;
        }

        $select = $connection->select()->from($oldTable, $columns);

        $copied = $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->moduleDataSetup->getTable(self::TABLE_NEW),
                $columns,
                $connection::INSERT_IGNORE,
            )
        )->rowCount();

        $this->logger->info(sprintf('Colour catalogue: %d rows copied out of %s', $copied, self::TABLE_OLD));

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }
}
