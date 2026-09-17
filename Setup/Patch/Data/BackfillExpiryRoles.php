<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Api\AssetKindInterface;
use Ben\Asset\Api\Data\AssetInterface;
use Ben\Asset\Api\ExpiryRoleInterface;
use Ben\Asset\Model\AssetExpiry;
use Ben\Migration\Model\Gate;
use Ben\Utils\Model\DateShift;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Expr;

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
 * manages are not for a timer to delete. A row nothing points at and whose kind says nothing certain keeps no role
 * at all, because a role decides how long a file is kept and a guess there deletes somebody's photo.
 */
class BackfillExpiryRoles implements DataPatchInterface
{
    // Rows read, or ids updated, per statement, so a big table is a series of small passes
    private const int BATCH_SIZE = 500;

    // The option a designer writes its whole saved state into, on a cart item and on an order item alike
    private const string DESIGNER_OPTION_CODE = 'designer_active_data';

    // A hash is 32 hex characters, which is enough to pick the assets out of a saved option blob
    private const string HASH_PATTERN = '/[a-f0-9]{32}/i';

    // What a kind settles on its own, for rows nothing points at: kind => role. Only the kinds whose creator
    // always passes the same role are here. A kind that is kept because the admin manages it is left out on
    // purpose: permanence is granted by the row pointing at the asset, never by the directory it sits in, so a
    // superseded tile or preview nothing references keeps no role instead of being promised forever
    private const array KIND_ROLES = [
        AssetKindInterface::AI_IMAGE => ExpiryRoleInterface::AI,
        AssetKindInterface::AI_PORTRAIT => ExpiryRoleInterface::AI,
        AssetKindInterface::AI_THUMBNAIL => ExpiryRoleInterface::AI,
        AssetKindInterface::CART_THUMBNAIL => ExpiryRoleInterface::CART,
        AssetKindInterface::DOWNLOAD => ExpiryRoleInterface::DOWNLOAD,
        AssetKindInterface::EMAIL_THUMBNAIL => ExpiryRoleInterface::EMAIL,
        AssetKindInterface::FACE_CROP => ExpiryRoleInterface::UPLOAD,
        AssetKindInterface::FACE_SOURCE => ExpiryRoleInterface::PREVIEW,
        AssetKindInterface::FEED_IMAGE => ExpiryRoleInterface::FEED,
        AssetKindInterface::FEED_THUMBNAIL => ExpiryRoleInterface::FEED,
        AssetKindInterface::GIFTWRAP_PREVIEW => ExpiryRoleInterface::GIFTWRAP_PREVIEW,
        AssetKindInterface::PREVIEW => ExpiryRoleInterface::PREVIEW,
        AssetKindInterface::SHEET => ExpiryRoleInterface::AI,
        AssetKindInterface::UPLOAD => ExpiryRoleInterface::UPLOAD,
        AssetKindInterface::UPLOAD_THUMBNAIL => ExpiryRoleInterface::UPLOAD_THUMBNAIL,
    ];

    // Everything a model drew: table => columns holding the asset id
    private const array TABLES_AI = [
        'ben_ai_generation' => ['asset_id', 'thumbnail_asset_id'],
    ];

    // What the print room made and what it made it from: table => columns holding the asset id
    private const array TABLES_ORDER = [
        'ben_product' => ['asset_id', 'original_asset_id', 'preview_asset_id'],
    ];

    // Assets the admin manages, kept for as long as the shop runs: table => columns holding the asset id
    private const array TABLES_PERMANENT = [
        'ben_designer_feed_item' => ['stock_asset_id', 'preview_asset_id'],
        'ben_designer_frame_finish' => ['swatch_asset_id'],
        'ben_designer_frame_profile' => ['corner_asset_id'],
        'ben_font' => ['asset_id', 'preview_asset_id'],
        'ben_giftwrap_category' => ['icon_asset_id'],
        'ben_giftwrap_design' => ['tile_asset_id', 'tile_compressed_asset_id', 'tile_print_asset_id', 'preview_asset_id'],
        'ben_giftwrap_overlay' => ['overlay_asset_id'],
    ];

    // The other option a cart item carries an asset on, the thumbnail the mini cart shows
    private const string THUMBNAIL_OPTION_CODE = 'cart_thumbnail';

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
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getTable('ben_asset');

        // The schema step that adds the column runs in this same process and the table description was cached
        // before it did, so the cache goes before anything asks whether the column is there: the gate reads that
        // same description, and a stale answer would have it report the column missing and pass as applied
        $connection->resetDdlCache($table);

        if (!$this->gate->hasColumn('ben_asset', AssetInterface::EXPIRY_ROLE)) {
            return;
        }

        $counts = [
            ExpiryRoleInterface::AI => $this->applyByReference(self::TABLES_AI, ExpiryRoleInterface::AI),
            ExpiryRoleInterface::PERMANENT => $this->applyByReference(self::TABLES_PERMANENT, ExpiryRoleInterface::PERMANENT),
            ExpiryRoleInterface::ORDER => $this->applyByReference(self::TABLES_ORDER, ExpiryRoleInterface::ORDER)
                + $this->applyByHashes($this->getOrderHashes(), ExpiryRoleInterface::ORDER),
            ExpiryRoleInterface::CART => $this->applyByHashes($this->getCartHashes(), ExpiryRoleInterface::CART),
        ];

        // Whatever nothing points at is asked what it is instead, which only the kinds backfill can answer, and
        // a site whose assets were never given kinds simply has nothing to ask
        if ($connection->tableColumnExists($table, AssetInterface::KIND)) {
            foreach (self::KIND_ROLES as $kind => $role) {
                $counts[$role] = ($counts[$role] ?? 0) + $this->applyByKind($kind, $role);
            }
        }

        foreach ($counts as $role => $count) {
            $this->logger->info(sprintf('Asset expiry backfill: %d rows given the %s role', $count, $role));
        }

        $this->reportLeftovers();
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
     * Assets the kinds backfill has already named, where the kind decides the role on its own
     */
    private function applyByKind(string $kind, string $role): int
    {
        return $this->update($role, AssetInterface::KIND, [$kind], $this->assetExpiry->neverExpires($role));
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
     * How many rows nothing could say anything certain about
     *
     * A row with no role is never given an expiry and never swept, which is the safe end to leave it on, but it
     * is also a row no expiry setting reaches, so the count belongs in the log where somebody will see it.
     */
    private function reportLeftovers(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $left = (int)$connection->fetchOne(
            $connection->select()
                ->from($this->getTable('ben_asset'), new Zend_Db_Expr('COUNT(*)'))
                ->where(AssetInterface::EXPIRY_ROLE . ' IS NULL')
        );

        $this->logger->info(sprintf('Asset expiry backfill: %d rows left without a role', $left));
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
