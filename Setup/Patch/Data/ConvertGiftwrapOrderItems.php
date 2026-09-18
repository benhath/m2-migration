<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Designer\Api\ActiveProductData\PurchaseInterface;
use Ben\Designer\Controller\Api\AddToCart;
use Ben\Designer\Model\ProductToolOptions;
use Ben\DesignerGiftwrap\Api\ActiveProductData\GiftwrapColorInterface;
use Ben\DesignerGiftwrap\Api\ActiveProductData\GiftwrapDesignInterface;
use Ben\DesignerGiftwrap\Api\ActiveProductData\GiftwrapFaceInterface;
use Ben\DesignerGiftwrap\Api\ActiveProductData\GiftwrapFontInterface;
use Ben\DesignerGiftwrap\Api\ActiveProductData\GiftwrapSizeInterface;
use Ben\DesignerGiftwrap\Api\ActiveProductData\GiftwrapTextInterface;
use Ben\DesignerGiftwrap\Setup\Patch\Data\InstallGiftwrapDesigner;
use Ben\Giftwrap\Api\Data\DesignInterface;
use Ben\Migration\Model\Gate;
use Exception;
use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Rewrites every giftwrap order item the old checkout took into the shape the designer writes.
 *
 * The old checkout kept what the customer chose under a "giftwrap" key of its own - a design id, a font, a
 * colour, a message, a roll length and sometimes a face - and a second set of classes existed only to read it
 * back when the print room asked for the order again. From 3.0 there is one reader, the designer's, so the old
 * items are converted rather than read: each one is given the designer_active_data and designer_type an item
 * bought through the designer carries, and its original options are kept beside them under giftwrap_legacy so
 * the row still says exactly what was ordered.
 *
 * The roll length is matched to the configured row of the same length so the item names it by hash, the way a
 * new one does. The old checkout sold lengths the picker no longer offers, and those items carry the length
 * alone; the populator prints what the order says when no configured row answers to the hash.
 *
 * An item already carrying designer_active_data is left alone, so this runs again over a table it has already
 * been through and touches nothing. An item that cannot be read is logged by id and skipped: one unreadable row
 * out of eighty thousand is not a reason to fail an upgrade, and its options are still there to look at.
 */
class ConvertGiftwrapOrderItems implements DataPatchInterface
{
    // Rows read per pass, so a table of order items is a series of small ones
    private const int BATCH_SIZE = 500;

    // The designer type the giftwrap products are sold under (Ben_DesignerGiftwrap's DesignerType virtualType)
    private const string DESIGNER_TYPE = 'giftwrap';

    // What the old checkout wrote its choices under, and where those choices are kept once converted
    private const string LEGACY_KEY = 'giftwrap';

    private const string LEGACY_KEPT_KEY = 'giftwrap_legacy';

    private const string OPTION_ROLL_LENGTH = 'rollLength';

    private const string TABLE = 'sales_order_item';

    private const string TOOL_SIZE = 'GiftwrapSize';

    /** @var array<int, array<string, string>> configured roll length rows by product id, length => hash */
    private array $rollLengthHashes = [];

    public function __construct(
        private readonly Gate $gate,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ProductToolOptions $productToolOptions,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public static function getDependencies(): array
    {
        return [
            InstallGiftwrapDesigner::class,
            KeyPersonalDesigns::class,
            RenameDesignTypes::class,
            SetGiftwrapDesignerType::class,
        ];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE) || !$this->gate->hasTable('ben_designer_product_tool')) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $designKeys = $this->getDesignKeys();

        $converted = 0;
        $skipped = 0;
        $malformed = 0;
        $lastItemId = 0;

        while (true) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['item_id', 'product_id', 'product_options'])
                    ->where('item_id > ?', $lastItemId)
                    ->where('product_options LIKE ?', '%"' . self::LEGACY_KEY . '"%')
                    ->order('item_id ASC')
                    ->limit(self::BATCH_SIZE)
            );

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $itemId = (int)$row['item_id'];
                $lastItemId = $itemId;

                try {
                    $productOptions = $this->convert($row, $designKeys);
                } catch (Exception $exception) {
                    // One row nobody can read is a row to look at afterwards, not an upgrade to stop
                    $this->logger->warning(sprintf(
                        'Giftwrap order item %d could not be converted: %s',
                        $itemId,
                        $exception->getMessage()
                    ));
                    $malformed++;
                    continue;
                }

                if ($productOptions === null) {
                    $skipped++;
                    continue;
                }

                $connection->update(
                    $table,
                    ['product_options' => $this->json->serialize($productOptions)],
                    ['item_id = ?' => $itemId]
                );
                $converted++;
            }
        }

        $this->logger->info(sprintf(
            'Giftwrap order items converted to the designer shape: %d converted, %d already in the new shape, %d unreadable',
            $converted,
            $skipped,
            $malformed
        ));
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * The designer's saved state as the old choices make it. Only the tools the old checkout could set are
     * written, since a tool with nothing under it reads as nothing chosen, which is what was chosen
     *
     * @param array<string, mixed> $legacy
     *
     * @return array<string, mixed>
     */
    private function buildActiveProductData(array $legacy, int $productId, array $designKeys): array
    {
        $designId = (int)$legacy['designId'];
        $lengthM = (float)$legacy['lengthM'];
        $faceAssetHash = $legacy['faceAssetHash'] ?? null;

        $design = [GiftwrapDesignInterface::ID => $designId];

        // A design made personal since the order was placed is only ever reached by its key
        if (isset($designKeys[$designId])) {
            $design[GiftwrapDesignInterface::KEY] = $designKeys[$designId];
        }

        $activeProductData = [
            'giftwrapDesign' => ['design' => $design],
            'giftwrapText' => [GiftwrapTextInterface::TEXT => (string)($legacy['text'] ?? '')],
            'giftwrapFont' => ['font' => [GiftwrapFontInterface::FONT_ID => $this->toId($legacy['fontId'] ?? null)]],
            'giftwrapColor' => ['color' => [GiftwrapColorInterface::COLOR_ID => $this->toId($legacy['colorId'] ?? null)]],
            'giftwrapSize' => [
                'size' => [
                    GiftwrapSizeInterface::HASH => $this->getRollLengthHash($productId, $lengthM),
                    GiftwrapSizeInterface::LENGTH_M => $lengthM,
                ],
            ],
            // The old checkout sold one roll per line and counted copies as quantity ordered, which is what the
            // populator already numbers rolls by, so the line itself is for a single roll
            'purchase' => [PurchaseInterface::QUANTITY => 1],
        ];

        if (is_string($faceAssetHash) && $faceAssetHash !== '') {
            $activeProductData['giftwrapFace'] = ['face' => [GiftwrapFaceInterface::HASH => $faceAssetHash]];
        }

        return $activeProductData;
    }

    /**
     * The converted options of one row, or null when the row is already in the new shape and there is nothing
     * to do
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    private function convert(array $row, array $designKeys): ?array
    {
        $productOptions = $this->json->unserialize((string)$row['product_options']);

        if (!is_array($productOptions)) {
            throw new InvalidArgumentException('product_options is not a set of options');
        }

        if (isset($productOptions[AddToCart::DESIGNER_ACTIVE_DATA_KEY])) {
            return null;
        }

        $legacy = $productOptions[self::LEGACY_KEY] ?? null;

        if (!is_array($legacy)) {
            // The row only mentions the word; it is not an item the old checkout wrote
            return null;
        }

        if (!is_numeric($legacy['designId'] ?? null) || !is_numeric($legacy['lengthM'] ?? null)) {
            throw new InvalidArgumentException('the old options name no design or no roll length');
        }

        $productOptions[AddToCart::DESIGNER_ACTIVE_DATA_KEY] = $this->buildActiveProductData(
            $legacy,
            (int)$row['product_id'],
            $designKeys
        );
        $productOptions[AddToCart::DESIGNER_TYPE_KEY] = self::DESIGNER_TYPE;

        // What was ordered, kept word for word beside what the designer now reads
        $productOptions[self::LEGACY_KEPT_KEY] = $legacy;
        unset($productOptions[self::LEGACY_KEY]);

        return $productOptions;
    }

    /**
     * The key of every design that has one, so an order naming a design that has since become personal names
     * it the way a personal design is fetched
     *
     * @return array<int, string>
     */
    private function getDesignKeys(): array
    {
        if (!$this->gate->hasColumn('ben_giftwrap_design', DesignInterface::KEY)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $keys = $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('ben_giftwrap_design'),
                    [DesignInterface::DESIGN_ID, DesignInterface::KEY]
                )
                ->where($connection->quoteIdentifier(DesignInterface::KEY) . ' IS NOT NULL')
        );

        return array_map('strval', $keys);
    }

    /**
     * The hash of the product's configured roll length of that many metres, or null when the shop no longer
     * sells it
     */
    private function getRollLengthHash(int $productId, float $lengthM): ?string
    {
        if (!isset($this->rollLengthHashes[$productId])) {
            $hashes = [];

            foreach ($this->productToolOptions->getRows($productId, self::TOOL_SIZE, self::OPTION_ROLL_LENGTH) as $row) {
                if (is_array($row) && is_numeric($row['lengthM'] ?? null) && !empty($row['hash'])) {
                    $hashes[$this->lengthKey((float)$row['lengthM'])] = (string)$row['hash'];
                }
            }

            $this->rollLengthHashes[$productId] = $hashes;
        }

        return $this->rollLengthHashes[$productId][$this->lengthKey($lengthM)] ?? null;
    }

    /**
     * Metres as a string to match rows by, so 2 and "2.0" are the same length
     */
    private function lengthKey(float $lengthM): string
    {
        return number_format($lengthM, 3, '.', '');
    }

    /**
     * An id the designer can read back, or null where the old options carried none and the design's own
     * default stands instead
     */
    private function toId(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
