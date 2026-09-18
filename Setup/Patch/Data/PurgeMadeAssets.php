<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Api\Data\AssetInterface;
use Ben\Asset\Model\AssetFactory;
use Ben\Asset\Model\Purge\Purger;
use Ben\Asset\Model\Purge\PurgeScope;
use Ben\Asset\Model\ResourceModel\Asset as AssetResource;
use Ben\Asset\Model\ResourceModel\Asset\CollectionFactory as AssetCollectionFactory;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes away every file the shop made for itself, on the way from 2 to 3.
 *
 * A kind is given or made. Given is what somebody handed us or paid for - a customer's photo, a design's tile, a
 * font, a picture a model drew, a face the face service cut - and nothing here can make it again. Made is what
 * the shop built from those on its own machines - previews, tiles at a size, thumbnails, feed pictures, font
 * previews, downloads - and it builds them again the moment anything asks. Years of made files were carried over
 * from 2, most of them for designs, baskets and orders long gone, and the shop is better off starting 3 with none
 * of them than with a store room nobody has sorted. A print file is given, so it stays and keeps its own expiry;
 * nothing is sent to the printer until the upgrade is done.
 *
 * The same purge the admin page offers, over every kind the registry lets it take, and after it the rows the kind
 * backfill could give no kind, where they are safe to take: a resized copy, which is made by definition, and any
 * row with no expiry. A customer's upload and a print file always carry an expiry on a live shop, and everything
 * kept for good - a tile, a font, a frame corner, a stock picture - is named by the row that uses it and so has
 * its kind; a row with no kind and no expiry is one nothing points at and nothing will ever clear. A row with no
 * kind that does carry an expiry is left to it: it may be a customer's own file, and its date will take it.
 *
 * Runs after the backfill's second pass and before the font previews are rendered, so nothing made by the
 * upgrade itself is taken. A second run finds nothing.
 */
class PurgeMadeAssets implements DataPatchInterface
{
    private const int BATCH_SIZE = 500;

    private const string RESIZED_PATH_PREFIX = 'asset/resized/';

    public function __construct(
        private readonly AssetCollectionFactory $assetCollectionFactory,
        private readonly AssetFactory $assetFactory,
        private readonly AssetResource $assetResource,
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly Purger $purger,
        private readonly PurgeScope $purgeScope,
    ) {
    }

    public static function getDependencies(): array
    {
        return [RecolourAssetKinds::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasColumn('ben_asset', AssetInterface::KIND)) {
            return;
        }

        $made = $this->purger->purge($this->purgeScope->getKinds());
        $unplaced = $this->purgeUnplaced();

        $this->logger->info(sprintf(
            'Made assets purged for 3.0: %d deleted (%d MB) and %d could not be; %d with no kind deleted (%d MB) and %d could not be',
            $made['deleted'],
            intdiv($made['sizeKb'], 1024),
            $made['failed'],
            $unplaced['deleted'],
            intdiv($unplaced['sizeKb'], 1024),
            $unplaced['failed'],
        ));
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return array{deleted: int, failed: int, sizeKb: int}
     */
    private function purgeUnplaced(): array
    {
        $deleted = 0;
        $failed = 0;
        $sizeKb = 0;

        while ($ids = $this->getUnplacedIds()) {
            $deletedThisPass = 0;

            foreach ($ids as $id) {
                $asset = $this->assetFactory->create();
                $this->assetResource->load($asset, $id);

                if (!$asset->getId()) {
                    continue;
                }

                try {
                    $sizeKb += (int)$asset->getFileSizeKb();
                    $asset->delete(true);
                    $deleted++;
                    $deletedThisPass++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->logger->warning(sprintf('Asset %d with no kind could not be deleted: %s', $id, $exception->getMessage()));
                }
            }

            // A row that would not delete is still there to be found, so a page of nothing but failures stops
            if ($deletedThisPass === 0) {
                break;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'sizeKb' => $sizeKb];
    }

    /**
     * @return int[]
     */
    private function getUnplacedIds(): array
    {
        $collection = $this->assetCollectionFactory->create();
        $collection->addFieldToFilter(AssetInterface::KIND, ['null' => true]);
        // Either column on its own is enough: a resized copy whatever its date, or anything with no date at all
        $collection->addFieldToFilter(
            [AssetInterface::FILE_PATH, AssetInterface::EXPIRES_AT],
            [['like' => self::RESIZED_PATH_PREFIX . '%'], ['null' => true]],
        );
        $collection->setPageSize(self::BATCH_SIZE);

        return array_map('intval', $collection->getAllIds());
    }
}
