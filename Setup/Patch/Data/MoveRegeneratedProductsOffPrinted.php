<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Ben\Product\Api\Data\ProductInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Regenerating an order's print files used to mark them printed, with no date, only so the print room would not
 * take them for new work. Regeneration now has its own flag and date, so those rows are moved onto it and printed
 * goes back to meaning the print room took the file.
 *
 * The print room always writes printed_at alongside has_printed, so a printed row with no date can only have come
 * from a regeneration. The regeneration date is the moment its file was made, or the row's last change when the
 * file never rendered. A moved row no longer matches, so running it again does nothing.
 */
class MoveRegeneratedProductsOffPrinted implements DataPatchInterface
{
    private const string TABLE = 'ben_product';

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
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        // The column was added moments ago by the schema upgrade, and the cached description still predates it
        $connection->resetDdlCache($table);

        if (!$this->gate->hasColumn(self::TABLE, ProductInterface::HAS_REGENERATED)) {
            return;
        }

        $connection->startSetup();

        $moved = $connection->update(
            $table,
            [
                ProductInterface::HAS_PRINTED => 0,
                ProductInterface::HAS_REGENERATED => 1,
                ProductInterface::REGENERATED_AT => new Expression(
                    sprintf('COALESCE(%s, %s)', ProductInterface::RENDERED_AT, ProductInterface::UPDATED_AT)
                ),
                // Left as it was, since the table would otherwise stamp every moved row with today
                ProductInterface::UPDATED_AT => new Expression(ProductInterface::UPDATED_AT),
            ],
            [
                ProductInterface::HAS_PRINTED . ' = ?' => 1,
                ProductInterface::PRINTED_AT . ' IS NULL',
            ],
        );

        $connection->endSetup();

        $this->logger->info(sprintf('Ben_Migration moved %d regenerated products off printed', $moved));
    }

    public function getAliases(): array
    {
        return [];
    }
}
