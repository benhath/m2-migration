<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Runs the two asset backfills a second time. **This patch is not tidying up: without it the roles a kind
 * settles are never written at all, on any site.**
 *
 * Roles are backfilled before kinds are - BackfillExpiryRoles is the earlier patch of the two - so when it looks
 * at its KIND_ROLES table every row's kind is still empty and that whole branch decides nothing. The roles it
 * writes on the first pass are only the ones something still points at. Every asset nothing points at, which is
 * what the kind is there to speak for, is left with no role until the backfills are run again in this order,
 * kinds first: that is the pass this patch exists to make, and it is the one that fills them in.
 *
 * It also catches a table that arrives after the patches did. A patch is recorded as applied the moment it runs,
 * which is right for a migration and wrong for a backfill of a table that is imported: both backfills ran on dev
 * while ben_asset held a dozen rows, the live import that followed brought in twelve thousand made long before
 * the kind and role columns existed, and neither patch was ever going to look at them again. The purge page and
 * the kind registry cannot see a row with no kind, so those twelve thousand were invisible. Live is the same
 * table, so the same thing would happen there if the schema step and an import fell either side of it.
 *
 * Both backfills only ever touch rows with nothing in the column yet, so a run over a table that is already
 * coloured changes nothing and a second run of this patch does nothing either.
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
