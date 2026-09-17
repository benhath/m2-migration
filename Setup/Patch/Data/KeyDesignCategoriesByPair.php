<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Migration\Model\Gate;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts the unique key on a design's categories, once the pairs it needs are actually unique.
 *
 * A design is filed under a category once, and the admin save has always cleared the rows and written them again
 * as though that were true. The key saying so could not simply be declared in db_schema.xml: declarative schema
 * runs before any data patch, so one duplicate pair left by an interrupted save on a live database would abort
 * setup:upgrade with the schema half applied and nothing able to clean up after it. The declaration is therefore
 * out of Ben_Giftwrap for 3.0 and the pairs are made unique here first, with the key added afterwards under the
 * name declarative schema itself would have generated, so the declaration can go back into db_schema.xml in the
 * next release without the key being dropped and rebuilt.
 *
 * The lowest id of each duplicated pair is the one kept, because it is the row every other table would have been
 * pointing at; the rest are counted and their pairs named in the log. Every step asks whether it has already been
 * done, so a second run has nothing to do.
 */
class KeyDesignCategoriesByPair implements DataPatchInterface
{
    private const string COLUMN_CATEGORY = 'category_id';

    private const string COLUMN_DESIGN = 'design_id';

    private const string COLUMN_ID = 'design_category_id';

    // How many duplicated pairs are named in the log before it says how many more there were
    private const int LOGGED_PAIRS = 50;

    private const string TABLE = 'ben_giftwrap_design_category';

    public function __construct(
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Nothing else here writes a design's categories, so there is nothing to wait for; the key goes on after the
     * table is clean and that is entirely this patch's own doing
     */
    public static function getDependencies(): array
    {
        return [];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE)) {
            return;
        }

        $this->removeDuplicatePairs();
        $this->addUniqueKey();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * Puts the key on, unless it is already there.
     *
     * The name is asked of Magento rather than written out, because it is the same call declarative schema makes
     * when it names a generated index: table, columns and index type joined and uppercased, hashed down when that
     * runs past what MySQL will take
     */
    private function addUniqueKey(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $keyName = $this->getKeyName();

        if (isset($connection->getIndexList($table)[$keyName])) {
            $this->logger->info(sprintf('Design categories: %s is already on %s', $keyName, self::TABLE));

            return;
        }

        $connection->addIndex(
            $table,
            $keyName,
            [self::COLUMN_DESIGN, self::COLUMN_CATEGORY],
            AdapterInterface::INDEX_TYPE_UNIQUE,
        );

        $this->logger->info(sprintf('Design categories: %s added to %s', $keyName, self::TABLE));
    }

    /**
     * The index name declarative schema would give the same constraint, so the declaration can return next
     * release and find its key already standing
     */
    private function getKeyName(): string
    {
        return $this->resourceConnection->getIdxName(
            self::TABLE,
            [self::COLUMN_DESIGN, self::COLUMN_CATEGORY],
            AdapterInterface::INDEX_TYPE_UNIQUE,
        );
    }

    /**
     * Every row of a pair that appears more than once, except the lowest id of each, which is the one the rest of
     * the shop would have been pointing at
     */
    private function removeDuplicatePairs(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, [
                self::COLUMN_DESIGN,
                self::COLUMN_CATEGORY,
                'rows' => 'COUNT(*)',
                'keep' => 'MIN(' . self::COLUMN_ID . ')',
            ])
            ->group([self::COLUMN_DESIGN, self::COLUMN_CATEGORY])
            ->having('COUNT(*) > 1');

        $pairs = $connection->fetchAll($select);

        if ($pairs === []) {
            $this->logger->info(sprintf('Design categories: every pair in %s is already unique', self::TABLE));

            return;
        }

        $removed = 0;
        $named = [];

        foreach ($pairs as $pair) {
            $removed += $connection->delete($table, [
                self::COLUMN_DESIGN . ' = ?' => (int)$pair[self::COLUMN_DESIGN],
                self::COLUMN_CATEGORY . ' = ?' => (int)$pair[self::COLUMN_CATEGORY],
                self::COLUMN_ID . ' > ?' => (int)$pair['keep'],
            ]);

            if (count($named) < self::LOGGED_PAIRS) {
                $named[] = sprintf(
                    'design %d in category %d (%d rows, kept %d)',
                    (int)$pair[self::COLUMN_DESIGN],
                    (int)$pair[self::COLUMN_CATEGORY],
                    (int)$pair['rows'],
                    (int)$pair['keep'],
                );
            }
        }

        $this->logger->warning(sprintf(
            'Design categories: %d duplicate row(s) removed from %s across %d pair(s); %s%s',
            $removed,
            self::TABLE,
            count($pairs),
            implode('; ', $named),
            count($pairs) > self::LOGGED_PAIRS
                ? sprintf(' and %d more pair(s)', count($pairs) - self::LOGGED_PAIRS)
                : '',
        ));
    }
}
