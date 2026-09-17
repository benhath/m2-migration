<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Ai\Api\Data\GenerationInterface;
use Ben\Migration\Model\Gate;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The face extraction service was called Faceout until 3.0 and is called Face V2 now, so the rows it wrote
 * carry a name nothing answers to any more: the usage report counts them by provider and the grid filters on it.
 *
 * Only the old name is touched - `faceout` as the provider, and a model of `faceout/<endpoint>` whose endpoint
 * is kept - so a row already renamed, and a row from any other provider, is left exactly as it is
 */
class RenameFaceoutProvider implements DataPatchInterface
{
    /**
     * The name the rows carry until this patch has run. Public because the patches that read the face service's
     * rows have to be able to recognise a database this has not reached yet
     */
    public const string OLD_PROVIDER = 'faceout';

    // The name the service answers to now; whatever followed the old prefix is the endpoint and is kept
    private const string NEW_MODEL_PREFIX = 'face-v2/';

    private const string OLD_MODEL_PREFIX = 'faceout/';

    private const string TABLE = 'ben_ai_generation';

    public function __construct(
        private readonly Gate $gate,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
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

        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);
        $model = $connection->quoteIdentifier(GenerationInterface::MODEL);
        $newModel = $connection->getConcatSql([
            $connection->quote(self::NEW_MODEL_PREFIX),
            sprintf('SUBSTRING(%s, %d)', $model, strlen(self::OLD_MODEL_PREFIX) + 1),
        ]);

        $connection->update(
            $table,
            [GenerationInterface::MODEL => new Expression($newModel)],
            [GenerationInterface::MODEL . ' LIKE ?' => self::OLD_MODEL_PREFIX . '%']
        );
        $connection->update(
            $table,
            [GenerationInterface::PROVIDER => GenerationInterface::PROVIDER_FACE_V2],
            [GenerationInterface::PROVIDER . ' = ?' => self::OLD_PROVIDER]
        );

        $this->moduleDataSetup->endSetup();
    }

    public function getAliases(): array
    {
        return ['Ben\Ai\Setup\Patch\Data\RenameFaceoutProvider'];
    }
}
