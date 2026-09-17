<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Puts the two asset backfills over the rows they never saw
 *
 * A patch is recorded as applied the moment it runs, which is right for a migration and wrong for a backfill of a
 * table that is imported. Both backfills ran on dev while ben_asset held a dozen rows, the live import that
 * followed brought in twelve thousand made long before the kind and role columns existed, and neither patch was
 * ever going to look at them again: the purge page and the kind registry cannot see a row with no kind, so those
 * twelve thousand were invisible. Live is the same table, so the same thing would happen there on the 2 to 3
 * upgrade if the schema step and the import fell either side of it.
 *
 * A new name is all it takes to have the work done once more. Both backfills only ever touch rows with nothing in
 * the column yet, so a run over a table that is already coloured changes nothing and a second run of this patch
 * does nothing either. Kinds go first because a role is now taken from the kind wherever nothing points at the
 * asset, and the kind has to be there to be read.
 */
class RecolourAssetKinds implements DataPatchInterface
{
    public function __construct(
        private readonly BackfillAssetKinds $assetKinds,
        private readonly BackfillExpiryRoles $expiryRoles,
    ) {
    }

    public static function getDependencies(): array
    {
        return [BackfillAssetKinds::class, BackfillExpiryRoles::class];
    }

    public function apply(): void
    {
        $this->assetKinds->apply();
        $this->expiryRoles->apply();
    }

    public function getAliases(): array
    {
        return [];
    }
}
