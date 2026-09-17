<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Api\Data\AssetInterface;
use Ben\Asset\Api\ExpiryRoleInterface;
use Ben\Asset\Model\AssetExpiry;
use Ben\Migration\Model\Gate;
use Ben\Utils\Model\DateShift;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives every asset already in the table the role it would have been created with
 *
 * Expiry used to be a date and nothing else, so an asset carried no record of what it was for and a sweep had to
 * work it out again from whatever still pointed at it. The role column says it outright, and this fills it in for
 * everything that was there before the column existed, working from the strongest role down so an asset that is
 * both an order file and an upload ends up an order file.
 *
 * Only rows with no role yet are touched, so running it again does nothing. Expiries are left exactly as they are
 * apart from the roles that never expire, which have theirs cleared: an image a model drew and an asset the admin
 * manages are not for a timer to delete.
 */
class BackfillExpiryRoles implements DataPatchInterface
{
    // Rows read, or ids updated, per statement, so a big table is a series of small passes
    private const BATCH_SIZE = 500;

    // The option a designer writes its whole saved state into, on a cart item and on an order item alike
    private const DESIGNER_OPTION_CODE = 'designer_active_data';

    // A hash is 32 hex characters, which is enough to pick the assets out of a saved option blob
    private const HASH_PATTERN = '/[a-f0-9]{32}/i';

    // Everything a model drew: table => columns holding the asset id
    private const TABLES_AI = [
        'ben_ai_generation' => ['asset_id', 'thumbnail_asset_id'],
    ];

    // What the print room made and what it made it from: table => columns holding the asset id
    private const TABLES_ORDER = [
        'ben_product' => ['asset_id', 'original_asset_id', 'preview_asset_id'],
    ];

    // Assets the admin manages, kept for as long as the shop runs: table => columns holding the asset id
    private const TABLES_PERMANENT = [
        'ben_designer_feed_item' => ['stock_asset_id', 'preview_asset_id'],
        'ben_designer_frame_finish' => ['swatch_asset_id'],
        'ben_designer_frame_profile' => ['corner_asset_id'],
        'ben_font' => ['asset_id', 'preview_asset_id'],
        'ben_giftwrap_category' => ['icon_asset_id'],
        'ben_giftwrap_design' => ['tile_asset_id', 'tile_compressed_asset_id', 'tile_print_asset_id', 'preview_asset_id'],
        'ben_giftwrap_overlay' => ['overlay_asset_id'],
    ];

    // The other option a cart item carries an asset on, the thumbnail the mini cart shows
    private const THUMBNAIL_OPTION_CODE = 'cart_thumbnail';

    public function __construct(
        private readonly Gate $gate,
        private readonly AssetExpiry $assetExpiry,
        private readonly DateShift $dateShift,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public static function getDependencies(): array
    {
        return [MigrateExpiryRoleConfig::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasColumn('ben_asset', 'expiry_role')) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->getTable('ben_asset');

        // The schema step that adds the column runs in this same process, and the table description was cached
        // before it did: without a reset the guard below would find no column and let the patch pass as applied
        $connection->resetDdlCache($table);

        if (!$connection->tableColumnExists($table, AssetInterface::EXPIRY_ROLE)) {
            return;
        }

        $counts = [
            ExpiryRoleInterface::AI => $this->applyByReference(self::TABLES_AI, ExpiryRoleInterface::AI),
            ExpiryRoleInterface::PERMANENT => $this->applyByReference(self::TABLES_PERMANENT, ExpiryRoleInterface::PERMANENT),
            ExpiryRoleInterface::ORDER => $this->applyByReference(self::TABLES_ORDER, ExpiryRoleInterface::ORDER)
                + $this->applyByHashes($this->getOrderHashes(), ExpiryRoleInterface::ORDER),
            ExpiryRoleInterface::CART => $this->applyByHashes($this->getCartHashes(), ExpiryRoleInterface::CART),
            ExpiryRoleInterface::UPLOAD => $this->applyToRemainder(),
        ];

        foreach ($counts as $role => $count) {
            $this->logger->info(sprintf('Asset expiry backfill: %d rows given the %s role', $count, $role));
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Asset\Setup\Patch\Data\BackfillExpiryRoles'];
    }

    /**
     * @param string[] $hashes
     */
    private function applyByHashes(array $hashes, string $role): int
    {
        return $this->update($role, AssetInterface::HASH, $hashes, $this->assetExpiry->neverExpires($role));
    }

    /**
     * Assets another table points at by id
     *
     * @param array<string, string[]> $tables
     */
    private function applyByReference(array $tables, string $role): int
    {
        $connection = $this->resourceConnection->getConnection();
        $assetIds = [];

        foreach ($tables as $table => $columns) {
            $table = $this->getTable($table);

            if (!$connection->isTableExists($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!$connection->tableColumnExists($table, $column)) {
                    continue;
                }

                $select = $connection->select()
                    ->distinct()
                    ->from($table, [$column])
                    ->where($connection->quoteIdentifier($column) . ' IS NOT NULL');

                foreach ($connection->fetchCol($select) as $assetId) {
                    $assetIds[(int)$assetId] = (int)$assetId;
                }
            }
        }

        return $this->update($role, AssetInterface::ASSET_ID, $assetIds, $this->assetExpiry->neverExpires($role));
    }

    /**
     * Whatever nothing points at is treated as an upload and keeps the expiry it already has
     */
    private function applyToRemainder(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable('ben_asset'), [AssetInterface::ASSET_ID])
            ->where(AssetInterface::EXPIRY_ROLE . ' IS NULL')
            ->order(AssetInterface::ASSET_ID)
            ->limit(self::BATCH_SIZE);
        $updated = 0;

        // Each pass takes the next batch of unassigned rows, so the loop ends when none are left
        while ($assetIds = $connection->fetchCol($select)) {
            $updated += $this->update(ExpiryRoleInterface::UPLOAD, AssetInterface::ASSET_ID, $assetIds, false);
        }

        return $updated;
    }

    /**
     * Every asset an open cart still points at
     *
     * @return string[]
     */
    private function getCartHashes(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getTable('quote_item_option');

        if (!$connection->isTableExists($table)) {
            return [];
        }

        $changedSince = $this->dateShift->daysAgo($this->assetExpiry->getCartActiveQuoteDays());

        $select = $connection->select()
            ->from(['o' => $table], ['value'])
            ->join(['i' => $this->getTable('quote_item')], 'i.item_id = o.item_id', [])
            ->join(['q' => $this->getTable('quote')], 'q.entity_id = i.quote_id', [])
            ->where('o.code IN (?)', [self::DESIGNER_OPTION_CODE, self::THUMBNAIL_OPTION_CODE])
            ->where('q.updated_at >= ?', $changedSince->format('Y-m-d H:i:s'))
            ->order('o.option_id');

        return $this->getHashes($select);
    }

    /**
     * Every hash the given select's one column holds, however it is buried in the value
     *
     * @return string[]
     */
    private function getHashes(Select $select): array
    {
        $connection = $this->resourceConnection->getConnection();
        $hashes = [];
        $offset = 0;

        while (true) {
            $rows = $connection->fetchCol((clone $select)->limit(self::BATCH_SIZE, $offset));

            foreach ($rows as $value) {
                if (preg_match_all(self::HASH_PATTERN, (string)$value, $matches)) {
                    foreach ($matches[0] as $hash) {
                        $hashes[strtolower($hash)] = $hash;
                    }
                }
            }

            if (count($rows) < self::BATCH_SIZE) {
                return array_values($hashes);
            }

            $offset += self::BATCH_SIZE;
        }
    }

    /**
     * Every asset an order item was designed from
     *
     * @return string[]
     */
    private function getOrderHashes(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getTable('sales_order_item');

        if (!$connection->isTableExists($table)) {
            return [];
        }

        $select = $connection->select()
            ->from($table, ['product_options'])
            ->where('product_options LIKE ?', '%' . self::DESIGNER_OPTION_CODE . '%')
            ->order('item_id');

        return $this->getHashes($select);
    }

    private function getTable(string $table): string
    {
        return $this->resourceConnection->getTableName($table);
    }

    /**
     * @param array<int|string> $values
     */
    private function update(string $role, string $field, array $values, bool $clearExpiry): int
    {
        if (!$values) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $bind = [AssetInterface::EXPIRY_ROLE => $role];

        if ($clearExpiry) {
            $bind[AssetInterface::EXPIRES_AT] = null;
        }

        $updated = 0;

        foreach (array_chunk(array_values($values), self::BATCH_SIZE) as $chunk) {
            $updated += $connection->update(
                $this->getTable('ben_asset'),
                $bind,
                [
                    AssetInterface::EXPIRY_ROLE . ' IS NULL',
                    $connection->quoteInto($field . ' IN (?)', $chunk),
                ]
            );
        }

        return $updated;
    }
}
