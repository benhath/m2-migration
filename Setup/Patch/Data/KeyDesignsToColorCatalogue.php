<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Color\Api\Data\ColorInterface;
use Ben\Giftwrap\Api\Data\DesignInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves a design's default colour off the old giftwrap colour table and onto the colour catalogue for good.
 *
 * The key could not be declared in db_schema.xml and left at that: declarative schema runs before any data
 * patch, and on a live site coming up to 3.0 ben_color is still empty at that moment, so a key pointing at it
 * would refuse every design that already names a colour. The rows therefore have to be copied first and the key
 * added afterwards, which is here, by hand, with the name declarative schema would have given it so that the
 * declaration can go into db_schema.xml in the next release without the key being dropped and rebuilt.
 *
 * The old table is dropped at the end, once its rows are known to be in the catalogue. Every step asks whether
 * it has already been done, so the patch can be run again on a site that stopped halfway.
 *
 * It alters a table, and Magento refuses DDL inside the transaction it wraps a data patch in, so the patch is
 * marked non-transactionable and runs on its own.
 */
class KeyDesignsToColorCatalogue implements DataPatchInterface, NonTransactionableInterface
{
    private const string TABLE_COLOR = 'ben_color';

    private const string TABLE_COLOR_OLD = 'ben_giftwrap_color';

    private const string TABLE_DESIGN = 'ben_giftwrap_design';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * The catalogue has to hold the copied rows before a key can be put on them, so that patch is named rather
     * than assumed
     */
    public static function getDependencies(): array
    {
        return [CopyGiftwrapColors::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE_DESIGN) || !$this->gate->hasTable(self::TABLE_COLOR)) {
            return;
        }

        $this->clearDesignsNamingAMissingColor();
        $this->addForeignKey();
        $this->dropOldColorTable();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * Puts the key on, unless it is already there.
     *
     * The name is asked of Magento rather than written out, because it is the same call declarative schema makes
     * when it names a generated key: table, column, reference table and reference column joined and uppercased,
     * hashed down when that runs past what MySQL will take
     */
    private function addForeignKey(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $designTable = $this->resourceConnection->getTableName(self::TABLE_DESIGN);

        $keyName = $this->resourceConnection->getFkName(
            self::TABLE_DESIGN,
            DesignInterface::DEFAULT_COLOR_ID,
            self::TABLE_COLOR,
            ColorInterface::COLOR_ID,
        );

        if (isset($connection->getForeignKeys($designTable)[$keyName])) {
            $this->logger->info(sprintf('Colour catalogue: %s is already on %s', $keyName, self::TABLE_DESIGN));

            return;
        }

        $connection->addForeignKey(
            $keyName,
            $designTable,
            DesignInterface::DEFAULT_COLOR_ID,
            $this->resourceConnection->getTableName(self::TABLE_COLOR),
            ColorInterface::COLOR_ID,
            AdapterInterface::FK_ACTION_SET_NULL,
        );

        $this->logger->info(sprintf('Colour catalogue: %s added to %s', $keyName, self::TABLE_DESIGN));
    }

    /**
     * A design naming a colour the catalogue does not hold is left with no default rather than stopping the
     * upgrade, because an unopenable design is a job for the admin and a failed upgrade is a job for nobody
     */
    private function clearDesignsNamingAMissingColor(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $designTable = $this->resourceConnection->getTableName(self::TABLE_DESIGN);
        $colorTable = $this->resourceConnection->getTableName(self::TABLE_COLOR);

        $onTheColor = sprintf(
            'c.%s = d.%s',
            ColorInterface::COLOR_ID,
            DesignInterface::DEFAULT_COLOR_ID,
        );

        $select = $connection->select()
            ->from(['d' => $designTable], [DesignInterface::DESIGN_ID])
            ->joinLeft(['c' => $colorTable], $onTheColor, [])
            ->where('d.' . DesignInterface::DEFAULT_COLOR_ID . ' IS NOT NULL')
            ->where('c.' . ColorInterface::COLOR_ID . ' IS NULL');

        $designIds = array_map('intval', $connection->fetchCol($select));

        if ($designIds === []) {
            $this->logger->info(sprintf('Colour catalogue: every giftwrap design resolves against %s', self::TABLE_COLOR));

            return;
        }

        $cleared = $connection->update(
            $designTable,
            [DesignInterface::DEFAULT_COLOR_ID => null],
            [DesignInterface::DESIGN_ID . ' IN (?)' => $designIds],
        );

        $this->logger->warning(sprintf(
            'Colour catalogue: %d giftwrap designs named a colour %s does not hold and were left with no default; '
            . 'design ids %s',
            $cleared,
            self::TABLE_COLOR,
            implode(', ', $designIds),
        ));
    }

    /**
     * The old table goes only when the catalogue is holding at least as many rows as it is, so a site whose copy
     * never ran keeps the only list of colours it has
     */
    private function dropOldColorTable(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $oldTable = $this->resourceConnection->getTableName(self::TABLE_COLOR_OLD);

        if (!$connection->isTableExists($oldTable)) {
            $this->logger->info(sprintf('Colour catalogue: %s has already gone', self::TABLE_COLOR_OLD));

            return;
        }

        $oldRows = (int)$connection->fetchOne($connection->select()->from($oldTable, 'COUNT(*)'));
        $newRows = (int)$connection->fetchOne(
            $connection->select()->from($this->resourceConnection->getTableName(self::TABLE_COLOR), 'COUNT(*)')
        );

        if ($newRows < $oldRows) {
            $this->logger->warning(sprintf(
                'Colour catalogue: %s kept, it holds %d rows and %s only %d',
                self::TABLE_COLOR_OLD,
                $oldRows,
                self::TABLE_COLOR,
                $newRows,
            ));

            return;
        }

        $connection->dropTable($oldTable);

        $this->logger->info(sprintf(
            'Colour catalogue: %s dropped, its %d rows are all in %s which holds %d',
            self::TABLE_COLOR_OLD,
            $oldRows,
            self::TABLE_COLOR,
            $newRows,
        ));
    }
}
