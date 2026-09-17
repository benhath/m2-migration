<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Clean\Model\LeftoverRemoval;
use Ben\Clean\Model\ModuleRemoval;
use Ben\Migration\Model\Gate;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drops the tables and setup_module rows left by extensions this shop no longer runs.
 *
 * Amazon, Dotdigital, Klarna, Vertex, Yotpo and a handful of Magento modules were taken out with composer, which
 * never runs an extension's own uninstall, so their tables and version rows have been carried through every
 * upgrade since. Ben_Clean works out which they are, conservatively: a table has to belong to a module with no
 * code left anywhere and be declared by no installed module. This is bin/magento config:remove-unused
 * --drop-leftovers, run once, and it is here rather than in a hand script because the migration module is what
 * carries the switchover and will meet the same leftovers on the other sites.
 *
 * It runs unattended inside setup:upgrade on a live database and these tables held customer names, addresses and
 * order history, so it is deliberately timid. The whole list is written to the log first, as a dry run, so there
 * is a record of what was about to go before anything went. Then the directory the dumps are written to is tried
 * for real: if it cannot be written to, nothing is dropped at all and the upgrade carries on, because a drop with
 * no dump behind it is the one thing here that cannot be undone. Ben_Clean leaves any individual table standing
 * whose own dump fails, for the same reason.
 *
 * A refusal is a log line and a return, never an exception: a shop that will not upgrade because a dead Yotpo
 * table could not be dumped is a worse outcome than a dead Yotpo table, and the patch is written so a person can
 * run the command by hand afterwards. Only the names and how many rows they held are logged, never what was in
 * them.
 *
 * It drops tables, and Magento refuses DDL inside the transaction it wraps a data patch in; run inside one the
 * drop also waited forever on a metadata lock the same transaction held. The patch is therefore non-transactionable.
 */
class DropRemovedModuleLeftovers implements DataPatchInterface, NonTransactionableInterface
{
    // What is written into the dump directory to find out whether it can actually be written to
    private const string WRITE_TEST_FILE = '.ben-migration-write-test';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly ModuleRemoval $moduleRemoval,
    ) {
    }

    public static function getDependencies(): array
    {
        // The config purge reads the same removed modules to decide which saved settings are theirs, so it has to
        // have had its turn before their setup_module rows are gone
        return [PurgeRedundantConfig::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasModule('Ben_Clean')) {
            return;
        }

        $planned = $this->announce();

        if ($planned === []) {
            return;
        }

        $refusal = $this->getDumpDirectoryRefusal();

        if ($refusal !== '') {
            $this->logger->warning(sprintf(
                'Ben_Migration dropped none of the %d leftover(s) listed above: %s. Nothing is lost and the upgrade'
                . ' carries on; run bin/magento config:remove-unused --drop-leftovers by hand once it can be',
                count($planned),
                $refusal,
            ));

            return;
        }

        foreach ($this->moduleRemoval->remove(false) as $removal) {
            $this->logger->info($this->getLine($removal));
        }
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * Everything that is about to go, written out before anything goes. The dry run touches nothing; it only asks
     * Ben_Clean what its answer would be, and a listing that itself fails is a reason to do nothing at all
     *
     * @return LeftoverRemoval[] empty when there is nothing to do, or nothing that can safely be done
     */
    private function announce(): array
    {
        try {
            $planned = $this->moduleRemoval->remove(true);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf(
                'Ben_Migration dropped nothing: the leftovers could not even be listed (%s)',
                $exception->getMessage(),
            ));

            return [];
        }

        if ($planned === []) {
            $this->logger->info('Ben_Migration found no removed module leftovers to drop');

            return [];
        }

        foreach ($planned as $removal) {
            $this->logger->info('Ben_Migration is about to ' . $this->getPlannedLine($removal));
        }

        return $planned;
    }

    /**
     * Why the dumps cannot be written, or an empty string when they can. The directory is written into for real
     * rather than asked about, because a directory that exists is not the same as one this process may write to
     */
    private function getDumpDirectoryRefusal(): string
    {
        $root = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $directory = $this->moduleRemoval->getDumpDirectory();
        $relative = $root->getRelativePath($directory);

        try {
            $root->create($relative);
            $root->writeFile(
                $relative . '/' . self::WRITE_TEST_FILE,
                "Written by Ben_Migration to check the removed module dumps can be written here\n",
            );
            $root->delete($relative . '/' . self::WRITE_TEST_FILE);
        } catch (Throwable $exception) {
            return sprintf('%s cannot be written to (%s)', $directory, $exception->getMessage());
        }

        return '';
    }

    private function getLine(LeftoverRemoval $removal): string
    {
        if ($removal->isSkipped()) {
            return sprintf('Ben_Migration left %s %s alone: %s', $removal->getKind(), $removal->getName(), $removal->getSkippedReason());
        }

        if ($removal->getKind() === LeftoverRemoval::KIND_MODULE_ROW) {
            return sprintf('Ben_Migration removed the setup_module row for %s', $removal->getName());
        }

        return sprintf(
            'Ben_Migration dropped table %s (%s), %d row(s), saved to %s',
            $removal->getName(),
            $removal->getModules(),
            $removal->getRows(),
            $removal->getDumpFile(),
        );
    }

    /**
     * The same fact in the tense of something not done yet, for the list written before anything is
     */
    private function getPlannedLine(LeftoverRemoval $removal): string
    {
        if ($removal->isSkipped()) {
            return sprintf(
                'leave %s %s alone: %s',
                $removal->getKind(),
                $removal->getName(),
                $removal->getSkippedReason(),
            );
        }

        if ($removal->getKind() === LeftoverRemoval::KIND_MODULE_ROW) {
            return sprintf('remove the setup_module row for %s', $removal->getName());
        }

        return sprintf(
            'drop table %s (%s), %d row(s), saving it to %s',
            $removal->getName(),
            $removal->getModules(),
            $removal->getRows(),
            $removal->getDumpFile(),
        );
    }
}
