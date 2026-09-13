<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves the three notes ben_asset was carrying for other modules into the tables that own them.
 *
 * The measurements Ben_Asset takes of an upload, the sentence Ben_Designer writes about it and the sentence
 * Ben_Giftwrap writes about the faces in it were all columns on ben_asset, so every one of the fourteen
 * thousand rows in that table carried the weight of three documents that only an upload ever has. Each one now
 * has a table of its own, keyed by the asset and going with it when it expires.
 *
 * The columns are still on ben_asset for 3.0 and are dropped in the release after it. That is the only safe
 * order: Magento runs db_schema before data patches, so a schema that dropped them would take the values away
 * before this ever ran. The code stopped reading and writing them the moment the tables existed, so nothing
 * puts anything back into them and the drop is a drop of dead columns.
 *
 * Every copy is INSERT IGNORE, so a row already in the new table - written since the upgrade by the code that
 * owns it - is left exactly as it is, and a second run has nothing to do.
 */
class MoveAssetNotesToOwners implements DataPatchInterface
{
    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        $this->moveQuality();
        $this->moveNote(
            'quality_summary',
            'ben_designer_asset_quality',
            'Designer quality notes'
        );
        $this->moveNote(
            'face_summary',
            'ben_giftwrap_face_summary',
            'Giftwrap face notes'
        );
    }

    public function getAliases(): array
    {
        return [];
    }

    private function getTable(string $table): string
    {
        return $this->resourceConnection->getTableName($table);
    }

    /**
     * Whether there is anything to copy and anywhere to copy it to. The tables were made by the schema upgrade
     * moments ago, so the cached description of ben_asset still predates nothing but is reset for the same
     * reason BackfillAssetKinds resets it: a description read before an upgrade is not the table in front of us
     */
    private function isReady(string $column, string $table): bool
    {
        if (!$this->gate->hasColumn('ben_asset', $column) || !$this->gate->hasTable($table)) {
            return false;
        }

        $this->resourceConnection->getConnection()->resetDdlCache($this->getTable($table));

        return true;
    }

    /**
     * One note per upload, with the context it was written for and the generation it came out of, both of
     * which were kept inside the stored document and are columns of their own now
     */
    private function moveNote(string $column, string $table, string $what): void
    {
        if (!$this->isReady($column, $table)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();

        $moved = $connection->query(sprintf(
            'INSERT IGNORE INTO %s (asset_id, context, summary, generation_hash, created_at)'
            . ' SELECT asset_id,'
            . ' JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.context\')),'
            . ' JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.summary\')),'
            . ' JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.generationHash\')),'
            . ' created_at'
            . ' FROM %s WHERE %s IS NOT NULL'
            . ' AND JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.summary\')) IS NOT NULL',
            $this->getTable($table),
            $column,
            $column,
            $column,
            $this->getTable('ben_asset'),
            $column,
            $column
        ))->rowCount();

        $this->logger->info(sprintf('Asset notes moved: %d %s into %s', $moved, $what, $table));
    }

    /**
     * The measurement report, which was the whole of the column and moves across as it stands
     */
    private function moveQuality(): void
    {
        if (!$this->isReady('quality', 'ben_asset_quality')) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();

        $moved = $connection->query(sprintf(
            'INSERT IGNORE INTO %s (asset_id, report, analysed_at)'
            . ' SELECT asset_id, quality, created_at FROM %s WHERE quality IS NOT NULL',
            $this->getTable('ben_asset_quality'),
            $this->getTable('ben_asset')
        ))->rowCount();

        $this->logger->info(sprintf('Asset notes moved: %d quality reports into ben_asset_quality', $moved));
    }
}
