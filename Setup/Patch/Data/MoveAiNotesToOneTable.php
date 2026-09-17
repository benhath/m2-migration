<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Ai\Api\Data\GenerationInterface;
use Ben\Designer\Model\Quality\Summary as QualitySummary;
use Ben\Giftwrap\Model\FaceV2\FaceSummary;
use Ben\Migration\Model\Gate;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings the two tables of AI notes about an upload into one and takes the originals away.
 *
 * Ben_Designer kept what the shop says about a photograph's quality and Ben_Giftwrap kept what it says about the
 * faces in it, in two tables with the same five columns and two classes that were the same class twice. They are
 * now one table owned by Ben_Ai, with the purpose saying which of them wrote a note - the same word the
 * generation log files the request under.
 *
 * The note pointed at its generation by hash, which was a string to look up rather than a key to follow, so it
 * is resolved to the generation's own id on the way across and kept as a foreign key. A note whose generation has
 * since been deleted comes over without one: the sentence a customer saw is the thing worth keeping, and the
 * exchange behind it was already allowed to go.
 *
 * It alters a table, and Magento refuses DDL inside the transaction it wraps a data patch in, so the patch is
 * marked non-transactionable and runs on its own.
 */
class MoveAiNotesToOneTable implements DataPatchInterface, NonTransactionableInterface
{
    private const string TABLE = 'ben_ai_asset_note';

    // The old table each purpose's notes are in, and the purpose they are copied across under
    private const array TABLES = [
        'ben_designer_asset_quality' => QualitySummary::PURPOSE,
        'ben_giftwrap_face_summary' => FaceSummary::PURPOSE,
    ];

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
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        foreach (self::TABLES as $table => $purpose) {
            if (!$this->gate->hasTable($table)) {
                continue;
            }

            $this->copy($table, $purpose);
        }
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * One old table's notes into the new one, then the old table dropped. INSERT IGNORE throughout, so a note the
     * owning module has written since the upgrade is left alone and a second run has nothing to do
     */
    private function copy(string $table, string $purpose): void
    {
        $connection = $this->resourceConnection->getConnection();
        $oldTable = $this->resourceConnection->getTableName($table);
        $generationIds = $this->getGenerationIds();
        $rows = [];
        $withoutGeneration = 0;

        foreach ($connection->fetchAll($connection->select()->from($oldTable)) as $note) {
            $generationId = $generationIds[$note['generation_hash']] ?? null;

            if ($note['generation_hash'] !== null && $generationId === null) {
                $withoutGeneration++;
            }

            $rows[] = [
                (int)$note['asset_id'],
                $purpose,
                $note['context'],
                $note['summary'],
                $generationId,
                $note['created_at'],
            ];
        }

        $copied = $rows === [] ? 0 : $connection->insertArray(
            $this->resourceConnection->getTableName(self::TABLE),
            ['asset_id', 'purpose', 'context', 'summary', 'generation_id', 'created_at'],
            $rows,
            AdapterInterface::INSERT_IGNORE
        );

        $connection->dropTable($oldTable);
        $this->logger->info(sprintf(
            'Ben_Migration copied %d of %d note(s) from %s into %s as purpose %s (%d whose generation had already'
            . ' gone) and dropped the old table',
            $copied,
            count($rows),
            $table,
            self::TABLE,
            $purpose,
            $withoutGeneration
        ));
    }

    /**
     * Every generation's id by the hash the notes named it with, read once rather than per note
     *
     * @return array<string, string>
     */
    private function getGenerationIds(): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('ben_ai_generation'),
                    [GenerationInterface::HASH, GenerationInterface::GENERATION_ID]
                )
                ->where(GenerationInterface::HASH . ' IS NOT NULL')
        );
    }
}
