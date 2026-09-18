<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Api\Data\AssetInterface;
use Ben\Asset\Model\AssetFactory;
use Ben\Asset\Model\ResourceModel\Asset as AssetResource;
use Ben\Asset\Model\ResourceModel\Asset\CollectionFactory as AssetCollectionFactory;
use Ben\Migration\Model\Gate;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes away the resized copies that nothing names any more.
 *
 * A resized copy is made from another picture whenever a page needs one - a design's print tile, a thumbnail, a
 * feed picture - and the row that asked for it points at it. The kind backfill gives every copy that is pointed
 * at the kind of the thing pointing at it, so what is left under asset/resized with no kind is a copy nothing
 * points at: the tile of a design since replaced, the thumbnail of a photo long gone. The purge page cannot see a
 * row with no kind, so those copies would sit on the disk for good. Each is a file that can be made again from
 * its original the moment anything asks, so they go, files and rows together.
 *
 * Runs after the backfill has had its second pass, so a copy is never taken for unplaced before it has been given
 * every chance to be placed. A second run finds nothing.
 */
class PurgeUnplacedResizedAssets implements DataPatchInterface
{
    private const int BATCH_SIZE = 500;

    private const string RESIZED_PATH_PREFIX = 'asset/resized/';

    public function __construct(
        private readonly AssetCollectionFactory $assetCollectionFactory,
        private readonly AssetFactory $assetFactory,
        private readonly AssetResource $assetResource,
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
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

        $deleted = 0;
        $failed = 0;
        $sizeKb = 0;

        while ($ids = $this->getUnplacedIds()) {
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
                } catch (Throwable $exception) {
                    $failed++;
                    $this->logger->warning(sprintf('Unplaced resized asset %d could not be deleted: %s', $id, $exception->getMessage()));
                }
            }

            // A row that would not delete is still there to be found, so a page of nothing but failures stops
            if ($deleted === 0) {
                break;
            }
        }

        $this->logger->info(sprintf(
            'Unplaced resized assets: %d deleted (%d MB), %d could not be deleted',
            $deleted,
            intdiv($sizeKb, 1024),
            $failed,
        ));
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return int[]
     */
    private function getUnplacedIds(): array
    {
        $collection = $this->assetCollectionFactory->create();
        $collection->addFieldToFilter(AssetInterface::KIND, ['null' => true]);
        $collection->addFieldToFilter(AssetInterface::FILE_PATH, ['like' => self::RESIZED_PATH_PREFIX . '%']);
        $collection->setPageSize(self::BATCH_SIZE);

        return array_map('intval', $collection->getAllIds());
    }
}
