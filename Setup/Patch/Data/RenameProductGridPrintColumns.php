<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use InvalidArgumentException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The order products grid's printed columns were renamed, so the admins who had arranged that grid keep it.
 *
 * "Printed At" and "Device" became "Last printed" and "Last printed on", because a print that was regenerated
 * afterwards is still the last print that happened even though the line is waiting to print again. A saved
 * grid layout names its columns, so without this every admin who had ever moved, hidden, sorted or filtered
 * those two columns would find their arrangement talking about columns that no longer exist.
 *
 * Only the two names are rewritten, wherever they appear in the saved layout: as column keys, as the sorted
 * field and as filter keys. A layout that has already been rewritten holds neither old name, so running this
 * again does nothing.
 */
class RenameProductGridPrintColumns implements DataPatchInterface
{
    // The saved grid layouts this is about, one namespace per grid
    private const string GRID_NAMESPACE = 'sales_order_view_product_grid';

    // Old column name => new column name
    private const array RENAMED_COLUMNS = [
        'device_name' => 'last_print_device',
        'printed_at' => 'last_printed_at',
    ];

    private const string TABLE = 'ui_bookmark';

    public function __construct(
        private readonly Gate $gate,
        private readonly Json $json,
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

        $bookmarks = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['bookmark_id', 'config'])
                ->where('namespace = ?', self::GRID_NAMESPACE)
        );

        $connection->startSetup();

        foreach ($bookmarks as $bookmarkId => $config) {
            try {
                $decoded = $this->json->unserialize((string)$config);
            } catch (InvalidArgumentException) {
                // A layout that is not readable is left exactly as it is; the grid falls back to its own defaults
                continue;
            }

            $renamed = $this->rename($decoded);

            if ($renamed === $decoded) {
                continue;
            }

            $connection->update(
                $table,
                ['config' => $this->json->serialize($renamed)],
                ['bookmark_id = ?' => $bookmarkId],
            );
        }

        $connection->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * The saved layout with both names replaced wherever they are used, at any depth
     */
    private function rename(mixed $config): mixed
    {
        if (is_string($config)) {
            return self::RENAMED_COLUMNS[$config] ?? $config;
        }

        if (!is_array($config)) {
            return $config;
        }

        $renamed = [];

        foreach ($config as $key => $value) {
            $renamed[is_string($key) ? (self::RENAMED_COLUMNS[$key] ?? $key) : $key] = $this->rename($value);
        }

        return $renamed;
    }
}
