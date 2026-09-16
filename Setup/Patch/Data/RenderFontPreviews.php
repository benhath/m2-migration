<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Asset\Api\Data\AssetInterface;
use Ben\Asset\Api\FileExtensionInterface;
use Ben\Font\Api\Data\FontInterface;
use Ben\Font\Model\Preview\Generator as PreviewGenerator;
use Ben\Font\Model\ResourceModel\Font\CollectionFactory as FontCollectionFactory;
use Ben\Migration\Model\Gate;
use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * The font samples stop being artwork somebody drew in Illustrator and become pictures the shop draws from the
 * font file itself.
 *
 * Every sample used to be the font's name expanded to outlines, uploaded by hand beside the font, and a font
 * could not go live until that had been done. The shop now draws the same picture - the first word of the
 * font's name, trimmed to the ink and scaled to one height - whenever a font is saved, so the sample can never
 * be of a different font from the file it sits beside. This gives every font already in the catalogue one of
 * the new pictures and takes the uploaded SVG it replaces away with it.
 *
 * Two names are corrected first, because the sample is drawn from the name and these two would read wrongly:
 * "Frauncess" carries a letter the typeface's own name does not have, and the sample drawn in Illustrator never
 * had it either, so the name is put right rather than the drawing. It is matched on its id and the name
 * that id holds together, so a site whose id 9 is some other font is left alone rather than renamed into
 * something it is not.
 *
 * Safe to run again: the renames find nothing the second time, and a font whose sample is already one of the
 * drawn PNGs is left as it is rather than redrawn.
 */
class RenderFontPreviews implements DataPatchInterface
{
    // The name each id holds today, and what it should read, so an id alone is never enough to rename on
    private const array CORRECTED_NAMES_BY_ID = [
        9 => ['Frauncess', 'Fraunces'],
    ];

    private const string TABLE_ASSET = 'ben_asset';

    private const string TABLE_FONT = 'ben_font';

    public function __construct(
        private readonly FontCollectionFactory $fontCollectionFactory,
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly PreviewGenerator $previewGenerator,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * The catalogue has to have been copied across and cut back before there is anything here worth drawing
     */
    public static function getDependencies(): array
    {
        return [KeepTopTwentyFonts::class];
    }

    public function apply(): void
    {
        if (!$this->gate->hasTable(self::TABLE_FONT) || !$this->gate->hasTable(self::TABLE_ASSET)) {
            return;
        }

        $this->correctNames();
        $this->renderPreviews();
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * The two names the drawn sample would otherwise read wrongly
     */
    private function correctNames(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE_FONT);

        foreach (self::CORRECTED_NAMES_BY_ID as $fontId => [$currentName, $correctedName]) {
            $renamed = $connection->update(
                $table,
                [FontInterface::NAME => $correctedName],
                [
                    FontInterface::FONT_ID . ' = ?' => $fontId,
                    FontInterface::NAME . ' = ?' => $currentName,
                ],
            );

            if ($renamed) {
                $this->logger->info(sprintf('Font %d renamed from %s to %s', $fontId, $currentName, $correctedName));
            }
        }
    }

    /**
     * Every font id whose sample is already one of the drawn pictures, so a second run redraws nothing
     *
     * @return array<int, true>
     */
    private function getFontIdsAlreadyDrawn(): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['font' => $this->resourceConnection->getTableName(self::TABLE_FONT)], FontInterface::FONT_ID)
            ->join(
                ['asset' => $this->resourceConnection->getTableName(self::TABLE_ASSET)],
                'asset.' . AssetInterface::ASSET_ID . ' = font.' . FontInterface::PREVIEW_ASSET_ID,
                [],
            )
            ->where('asset.' . AssetInterface::FILE_PATH . ' LIKE ?', '%.' . FileExtensionInterface::PNG);

        return array_fill_keys(array_map('intval', $connection->fetchCol($select)), true);
    }

    /**
     * One drawn sample per font. A font whose file has gone is named in the log and the rest carry on, because
     * an upgrade that stops half way through the catalogue is worse than a catalogue with one sample missing
     */
    private function renderPreviews(): void
    {
        $alreadyDrawn = $this->getFontIdsAlreadyDrawn();
        $drawn = 0;
        $skipped = 0;

        /** @var FontInterface $font */
        foreach ($this->fontCollectionFactory->create()->getItems() as $font) {
            if (isset($alreadyDrawn[(int)$font->getId()])) {
                $skipped++;
                continue;
            }

            try {
                $this->previewGenerator->generate($font);
                $drawn++;
            } catch (Exception $exception) {
                $this->logger->warning(sprintf(
                    'No sample could be drawn for font %s (%s): %s',
                    $font->getId(),
                    $font->getName(),
                    $exception->getMessage(),
                ));
            }
        }

        $this->logger->info(sprintf('Font samples drawn: %d, already drawn: %d', $drawn, $skipped));
    }
}
