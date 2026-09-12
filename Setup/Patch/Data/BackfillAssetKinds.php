<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Api\AssetKindInterface;
use Ben\Asset\Api\Data\AssetInterface;
use Ben\Asset\Api\ExpiryRoleInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives every asset already in the table the kind whatever made it would name today
 *
 * A creator knows what it is making, so from now on it says so. Everything already stored has to be worked out
 * again, and there are only two honest ways to do it: what points at the row, and which directory the file was
 * written into, because every creator writes into its own. References are read first because a row another table
 * names is beyond doubt; directories fill in the rest; an upload with nothing above it is the last resort.
 *
 * Only rows with no kind yet are touched, so running it again does nothing. What still cannot be placed is left
 * NULL rather than guessed at, since a wrong kind is worse than none: a grid filtering on kind would show it.
 */
class BackfillAssetKinds implements DataPatchInterface
{
    // Rows read, or ids updated, per statement, so a big table is a series of small passes
    private const BATCH_SIZE = 500;

    // Where each creator writes, as the file_path prefix it produces: prefix => kind
    private const DIRECTORIES = [
        'asset/cart-thumbnail/email/' => AssetKindInterface::EMAIL_THUMBNAIL,
        'asset/designer/base/' => AssetKindInterface::CART_THUMBNAIL,
        'asset/designer/feed/stock/' => AssetKindInterface::FEED_STOCK,
        'asset/designer/frames/corners/' => AssetKindInterface::FRAME_CORNER,
        'asset/designer/frames/swatches/' => AssetKindInterface::FRAME_SAMPLE,
        'asset/designer/google/feed/' => AssetKindInterface::FEED_IMAGE,
        'asset/designer/image/upload/' => AssetKindInterface::UPLOAD,
        'asset/designer/mockup/' => AssetKindInterface::PREVIEW,
        'asset/designer/personalise/' => AssetKindInterface::PREVIEW,
        'asset/designer/thumbnail/' => AssetKindInterface::UPLOAD_THUMBNAIL,
        'asset/giftwrap/artwork/' => AssetKindInterface::GIFTWRAP_PREVIEW,
        'asset/giftwrap/blank/' => AssetKindInterface::PLACEHOLDER,
        'asset/giftwrap/category/icon/' => AssetKindInterface::CATEGORY_ICON,
        'asset/giftwrap/design/tile/' => AssetKindInterface::DESIGN_TILE,
        'asset/giftwrap/face/extracted/' => AssetKindInterface::FACE_CROP,
        'asset/giftwrap/face/pattern/' => AssetKindInterface::GIFTWRAP_PREVIEW,
        'asset/giftwrap/face/source/' => AssetKindInterface::FACE_SOURCE,
        'asset/giftwrap/font/file/' => AssetKindInterface::FONT,
        'asset/giftwrap/font/preview/' => AssetKindInterface::FONT_PREVIEW,
        'asset/giftwrap/generated/' => AssetKindInterface::DESIGN_TILE,
        'asset/giftwrap/google/feed/' => AssetKindInterface::FEED_IMAGE,
        'asset/giftwrap/overlay/file/' => AssetKindInterface::OVERLAY,
        'asset/personalise/' => AssetKindInterface::PRINT_FILE,
        'asset/product/zip/' => AssetKindInterface::DOWNLOAD,
        'asset/slip/' => AssetKindInterface::DOWNLOAD,
        'asset/zip/' => AssetKindInterface::DOWNLOAD,
    ];

    // The provider whose generated asset is a customer's own face rather than something a model drew
    private const FACE_V2_PROVIDER = 'face_v2';

    // What another table names, and so cannot be mistaken: kind => table => columns holding the asset id
    private const REFERENCES = [
        AssetKindInterface::AI_IMAGE => ['ben_ai_generation' => ['asset_id']],
        AssetKindInterface::AI_THUMBNAIL => ['ben_ai_generation' => ['thumbnail_asset_id']],
        AssetKindInterface::CATEGORY_ICON => ['ben_giftwrap_category' => ['icon_asset_id']],
        AssetKindInterface::DESIGN_PREVIEW => ['ben_giftwrap_design' => ['preview_asset_id']],
        AssetKindInterface::DESIGN_TILE => [
            'ben_giftwrap_design' => ['tile_asset_id', 'tile_compressed_asset_id', 'tile_print_asset_id'],
        ],
        AssetKindInterface::FEED_IMAGE => ['ben_designer_feed_item' => ['preview_asset_id']],
        AssetKindInterface::FEED_STOCK => ['ben_designer_feed_item' => ['stock_asset_id']],
        AssetKindInterface::FONT => ['ben_font' => ['asset_id'], 'ben_giftwrap_font' => ['asset_id']],
        AssetKindInterface::FONT_PREVIEW => [
            'ben_font' => ['preview_asset_id'],
            'ben_giftwrap_font' => ['preview_asset_id'],
        ],
        AssetKindInterface::FRAME_CORNER => ['ben_designer_frame_profile' => ['corner_asset_id']],
        AssetKindInterface::FRAME_SAMPLE => ['ben_designer_frame_finish' => ['swatch_asset_id']],
        AssetKindInterface::OVERLAY => ['ben_giftwrap_overlay' => ['overlay_asset_id']],
        AssetKindInterface::PRINT_FILE => ['ben_product' => ['asset_id']],
        AssetKindInterface::PRINT_PREVIEW => ['ben_product' => ['preview_asset_id']],
        AssetKindInterface::SHEET => [
            'ben_giftwrap_design' => ['character_sheet_asset_id', 'accent_sheet_asset_id'],
        ],
        AssetKindInterface::UPLOAD => ['ben_product' => ['original_asset_id']],
    ];

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public static function getDependencies(): array
    {
        return [BackfillExpiryRoles::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasColumn('ben_asset', 'kind')) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->getTable('ben_asset');

        // The column was added moments ago by the schema upgrade, and the cached description still predates it
        $connection->resetDdlCache($table);

        if (!$connection->tableColumnExists($table, AssetInterface::KIND)) {
            return;
        }

        $counts = [];

        // The face service's generations point at a customer's face, not at a drawing, so they are settled first
        $counts[AssetKindInterface::FACE_CROP] = $this->applyToIds(
            AssetKindInterface::FACE_CROP,
            $this->getFaceGenerationAssetIds()
        );

        foreach (self::REFERENCES as $kind => $tables) {
            $counts[$kind] = ($counts[$kind] ?? 0) + $this->applyToIds($kind, $this->getReferencedIds($tables));
        }

        foreach (self::DIRECTORIES as $prefix => $kind) {
            $counts[$kind] = ($counts[$kind] ?? 0) + $this->applyToDirectory($kind, $prefix);
        }

        $counts[AssetKindInterface::UPLOAD] = ($counts[AssetKindInterface::UPLOAD] ?? 0) + $this->applyToUploads();

        foreach ($counts as $kind => $count) {
            $this->logger->info(sprintf('Asset kind backfill: %d rows given the %s kind', $count, $kind));
        }
    }

    public function getAliases(): array
    {
        return ['Ben\Asset\Setup\Patch\Data\BackfillAssetKinds'];
    }

    /**
     * Everything written into one creator's directory, in batches so a big directory is a series of small passes
     */
    private function applyToDirectory(string $kind, string $prefix): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable('ben_asset'), [AssetInterface::ASSET_ID])
            ->where(AssetInterface::KIND . ' IS NULL')
            ->where(AssetInterface::FILE_PATH . ' LIKE ?', $prefix . '%')
            ->order(AssetInterface::ASSET_ID)
            ->limit(self::BATCH_SIZE);
        $updated = 0;

        // Each pass takes the next batch still without a kind, so the loop ends when none are left
        while ($assetIds = $connection->fetchCol($select)) {
            $updated += $this->applyToIds($kind, $assetIds);
        }

        return $updated;
    }

    /**
     * @param array<int|string> $assetIds
     */
    private function applyToIds(string $kind, array $assetIds): int
    {
        if (!$assetIds) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $updated = 0;

        foreach (array_chunk(array_values($assetIds), self::BATCH_SIZE) as $chunk) {
            $updated += $connection->update(
                $this->getTable('ben_asset'),
                [AssetInterface::KIND => $kind],
                [
                    AssetInterface::KIND . ' IS NULL',
                    $connection->quoteInto(AssetInterface::ASSET_ID . ' IN (?)', $chunk),
                ]
            );
        }

        return $updated;
    }

    /**
     * An asset kept as an upload with nothing made from it is the customer's own photo and nothing else
     */
    private function applyToUploads(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable('ben_asset'), [AssetInterface::ASSET_ID])
            ->where(AssetInterface::KIND . ' IS NULL')
            ->where(AssetInterface::EXPIRY_ROLE . ' = ?', ExpiryRoleInterface::UPLOAD)
            ->where(AssetInterface::ORIGINAL_ASSET_ID . ' IS NULL')
            ->order(AssetInterface::ASSET_ID)
            ->limit(self::BATCH_SIZE);
        $updated = 0;

        while ($assetIds = $connection->fetchCol($select)) {
            $updated += $this->applyToIds(AssetKindInterface::UPLOAD, $assetIds);
        }

        return $updated;
    }

    /**
     * The assets and thumbnails the face service's own log entries point at
     *
     * @return int[]
     */
    private function getFaceGenerationAssetIds(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getTable('ben_ai_generation');

        if (!$connection->isTableExists($table)) {
            return [];
        }

        $select = $connection->select()
            ->from($table, ['asset_id'])
            ->where('provider = ?', self::FACE_V2_PROVIDER)
            ->where('asset_id IS NOT NULL');

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Every asset id the given tables and columns hold
     *
     * @param array<string, string[]> $tables
     *
     * @return int[]
     */
    private function getReferencedIds(array $tables): array
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

        return array_values($assetIds);
    }

    private function getTable(string $table): string
    {
        return $this->resourceConnection->getTableName($table);
    }
}
