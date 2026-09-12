<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Font\Api\Data\FontInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * The font catalogue moves out of Ben_Giftwrap and into its own table, so the art range, the AI artwork and
 * the stickers can letter from it without every one of them depending on the giftwrap module.
 *
 * Each row keeps the id it already holds, because that id is what a design's default font, the store font
 * setting and the art range's typography setting all name; a copy that renumbered would silently repoint every
 * one of them. A row already in the new table is left exactly as it is, so the patch can be run again safely.
 *
 * The old table is not dropped here. It stays until every site is on 3.0 and its rows have been seen in the
 * new one, and is then dropped by hand.
 */
class CopyGiftwrapFonts implements DataPatchInterface
{
    private const string TABLE_NEW = 'ben_font';

    private const string TABLE_OLD = 'ben_giftwrap_font';

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
            FontInterface::FONT_ID,
            FontInterface::NAME,
            FontInterface::ASSET_ID,
            FontInterface::PREVIEW_ASSET_ID,
            FontInterface::IS_ACTIVE,
        ];

        $select = $connection->select()->from($oldTable, $columns);

        $copied = $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->moduleDataSetup->getTable(self::TABLE_NEW),
                $columns,
                $connection::INSERT_IGNORE,
            )
        )->rowCount();

        $this->logger->info(sprintf('Font catalogue: %d rows copied out of %s', $copied, self::TABLE_OLD));

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Font\Setup\Patch\Data\CopyGiftwrapFonts'];
    }
}
