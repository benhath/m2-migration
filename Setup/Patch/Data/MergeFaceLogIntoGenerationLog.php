<?php
declare(strict_types=1);

namespace Ben\Migration\Setup\Patch\Data;

use Ben\Ai\Api\Data\GenerationInterface;
use Ben\Giftwrap\Model\FaceV2\FaceLogger;
use Ben\Migration\Model\Gate;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Math\Random;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Expr;

/**
 * Brings the face service's own log into the generation log and takes the second table away.
 *
 * Every call to the face service was written down twice: once as a generation, the way a model call is, and once
 * as a row of `ben_giftwrap_face_log` holding the things a generation had no column for. The generation log now
 * has those columns, so a scan is one row again and the knocks and probes that never had a generation get one.
 *
 * Matching is by the photograph and the moment. A scan's generation names the copy that was actually sent, which
 * for a HEIC or a TIFF is a normalised file standing in for the upload, so the upload is reached through that
 * copy's original; the kinds have to agree, the two have to be within five minutes of each other, and a
 * generation is claimed once. A face log row that matches nothing is inserted as a generation of its own: on a
 * shop where uploads have expired or old generations have been cleaned off there is nothing left to match to,
 * and the row is still the record of a call that was made.
 *
 * The two keep-warm flags go with it. They said when the service was last knocked on and when a customer's photo
 * last went through, which the log now answers by itself, and a second copy of a fact is a fact that can disagree
 * with itself.
 */
class MergeFaceLogIntoGenerationLog implements DataPatchInterface
{
    // Which endpoint each kind was, for the model column
    private const array ENDPOINTS
        = [
            'detect' => '/extract',
            'extract' => '/extract',
            'health' => '/health',
            'keep_warm_ping' => '/ready',
            'ready' => '/ready',
            'warm' => '/ready',
        ];

    // Seconds either side of a face log row a generation may sit and still be the same call, where the photo
    // itself identifies the pair
    private const int MATCH_WINDOW_SECONDS = 300;

    // The same, where the upload has expired and only the moment and the kind are left to go on
    private const int MATCH_WINDOW_SECONDS_BY_TIME = 30;

    // The old table's status words, as the generation log says the same things
    private const array STATUSES
        = [
            'ok' => GenerationInterface::STATUS_COMPLETE,
            'busy' => GenerationInterface::STATUS_REFUSED,
            'rejected' => GenerationInterface::STATUS_REFUSED,
            'warming' => GenerationInterface::STATUS_REFUSED,
            'failed' => GenerationInterface::STATUS_FAILED,
        ];

    private const string TABLE = 'ben_giftwrap_face_log';

    private const array WARM_FLAGS = ['ben_giftwrap_face_last_ping', 'ben_giftwrap_face_last_upload'];

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly Gate $gate,
        private readonly LoggerInterface $logger,
        private readonly Random $random,
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

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $matched = 0;
        $inserted = 0;
        $claimed = [];
        $candidates = $this->getCandidates();
        $uploads = $this->getUploadAssetIds();

        foreach ($connection->fetchAll($connection->select()->from($table)->order('created_at ASC')->order('face_log_id ASC')) as $row) {
            $generationId = $this->getMatch($row, $candidates, $uploads, $claimed);

            if ($generationId === null) {
                $this->insert($row);
                $inserted++;

                continue;
            }

            $claimed[$generationId] = true;
            $this->describe($generationId, $row);
            $matched++;
        }

        foreach (self::WARM_FLAGS as $flag) {
            $this->flagManager->deleteFlag($flag);
        }

        $connection->dropTable($table);
        $this->logger->info(sprintf(
            'Ben_Migration merged the face service log into the generation log: %d row(s) matched onto an existing'
            . ' generation, %d inserted as new generations, %s dropped, %d keep-warm flag(s) removed',
            $matched,
            $inserted,
            self::TABLE,
            count(self::WARM_FLAGS)
        ));
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * What the face log row knew, onto the generation it belongs to. The upload's hash goes into the request
     * beside the copy that was sent, which is where the client writes it now
     */
    private function describe(string $generationId, array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $generationTable = $this->resourceConnection->getTableName('ben_ai_generation');

        $connection->update(
            $generationTable,
            $this->getColumns($row) + ['request' => $this->getRequest($this->getStoredRequest($generationId), $row)],
            [GenerationInterface::GENERATION_ID . ' = ?' => $generationId]
        );
    }

    /**
     * Every face service generation that has not been described yet, with the upload it was about resolved
     * through the copy that was sent. A row this patch has already written to has a kind, which is what keeps a
     * second run from claiming it again
     *
     * @return array<int, array{generation_id: string, created_at: string, detect: bool, upload_asset_id: string|null}>
     */
    private function getCandidates(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $generationTable = $this->resourceConnection->getTableName('ben_ai_generation');
        $assetTable = $this->resourceConnection->getTableName('ben_asset');

        $select = $connection->select()
            ->from(['g' => $generationTable], [
                GenerationInterface::GENERATION_ID,
                GenerationInterface::CREATED_AT,
                'detect_only' => new Zend_Db_Expr("JSON_UNQUOTE(JSON_EXTRACT(g.request, '$.detect_only'))"),
                'upload_asset_id' => new Zend_Db_Expr('COALESCE(s.original_asset_id, s.asset_id)'),
            ])
            ->joinLeft(
                ['s' => $assetTable],
                new Zend_Db_Expr("s.hash = JSON_UNQUOTE(JSON_EXTRACT(g.request, '$.source.hash'))"),
                []
            )
            ->where('g.' . GenerationInterface::PROVIDER . ' = ?', GenerationInterface::PROVIDER_FACE_V2)
            ->where('g.' . GenerationInterface::KIND . ' IS NULL');

        $candidates = [];

        foreach ($connection->fetchAll($select) as $row) {
            $candidates[] = [
                'generation_id' => (string)$row[GenerationInterface::GENERATION_ID],
                'created_at' => (string)$row[GenerationInterface::CREATED_AT],
                'detect' => ($row['detect_only'] ?? null) === 'true',
                'upload_asset_id' => $row['upload_asset_id'] === null ? null : (string)$row['upload_asset_id'],
            ];
        }

        return $candidates;
    }

    /**
     * The columns a face log row fills in on a generation, whether it is joining one or becoming one
     */
    private function getColumns(array $row): array
    {
        return [
            GenerationInterface::KIND => $row['kind'],
            GenerationInterface::TRIGGERED_BY => $row['triggered_by'],
            GenerationInterface::INSTANCE_COLD => (int)$row['instance_cold'],
            GenerationInterface::RESULT_COUNT => $row['faces_found'] === null ? null : (int)$row['faces_found'],
            GenerationInterface::SERVICE_URL => $row['url'],
        ];
    }

    /**
     * The generation this face log row is a second copy of, or null when there is nothing left to match to
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, string> $uploads
     * @param array<string, true> $claimed
     */
    private function getMatch(array $row, array $candidates, array $uploads, array $claimed): ?string
    {
        // A knock or a probe never had a generation of its own
        if ($row['upload_hash'] === null) {
            return null;
        }

        $uploadAssetId = $uploads[$row['upload_hash']] ?? null;
        $window = $uploadAssetId === null ? self::MATCH_WINDOW_SECONDS_BY_TIME : self::MATCH_WINDOW_SECONDS;
        $wantDetect = $row['kind'] === 'detect';
        $best = null;
        $bestGap = null;

        foreach ($candidates as $candidate) {
            if (isset($claimed[$candidate['generation_id']]) || $candidate['detect'] !== $wantDetect) {
                continue;
            }

            // The photograph it was about, where the upload is still on file; where it has expired, the moment
            // and the kind are all there is to go on
            if ($uploadAssetId !== null && $candidate['upload_asset_id'] !== $uploadAssetId) {
                continue;
            }

            $gap = abs(strtotime($candidate['created_at']) - strtotime((string)$row['created_at']));

            if ($gap <= $window && ($bestGap === null || $gap < $bestGap)) {
                $best = $candidate['generation_id'];
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /**
     * The request document for a face log row: what it knew about the photograph and the accessory, kept beside
     * whatever the generation already held
     */
    private function getRequest(array $request, array $row): string
    {
        if ($row['upload_hash'] !== null) {
            $request['upload'] = ['hash' => $row['upload_hash']];
        }

        if ($row['accessory_key'] !== null) {
            $request['accessory'] = $row['accessory_key'];
        }

        return json_encode($request, JSON_THROW_ON_ERROR);
    }

    private function getStoredRequest(string $generationId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $request = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('ben_ai_generation'), GenerationInterface::REQUEST)
                ->where(GenerationInterface::GENERATION_ID . ' = ?', $generationId)
        );

        $decoded = is_string($request) && $request !== '' ? json_decode($request, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Every asset hash on file, so a face log row's photograph can be found without a query apiece
     *
     * @return array<string, string>
     */
    private function getUploadAssetIds(): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('ben_asset'), ['hash', 'asset_id'])
                ->where('hash IS NOT NULL')
        );
    }

    /**
     * A face log row that matched nothing, as a generation of its own. It is still the record of a call that was
     * made, and the log is the one place calls are recorded
     */
    private function insert(array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $error = $row['error'];

        if ($error === null && $row['status'] === 'warming') {
            $error = FaceLogger::REASON_WARMING;
        }

        $connection->insert(
            $this->resourceConnection->getTableName('ben_ai_generation'),
            $this->getColumns($row) + [
                GenerationInterface::HASH => $this->random->getUniqueHash(),
                GenerationInterface::PROVIDER => GenerationInterface::PROVIDER_FACE_V2,
                GenerationInterface::PURPOSE => FaceLogger::PURPOSE,
                GenerationInterface::STATUS => self::STATUSES[$row['status']] ?? GenerationInterface::STATUS_FAILED,
                GenerationInterface::MODEL => 'face-v2' . (self::ENDPOINTS[$row['kind']] ?? ''),
                GenerationInterface::REQUEST => $this->getRequest([], $row),
                GenerationInterface::ERROR_MESSAGE => $error,
                GenerationInterface::HTTP_STATUS => $row['http_code'] === null ? null : (int)$row['http_code'],
                GenerationInterface::DURATION_MS => $row['duration_ms'] === null ? null : (int)$row['duration_ms'],
                GenerationInterface::STORE_ID => $row['store_id'] === null ? null : (int)$row['store_id'],
                GenerationInterface::CREATED_AT => $row['created_at'],
                GenerationInterface::UPDATED_AT => $row['created_at'],
            ]
        );
    }
}
