<?php

declare(strict_types=1);

namespace BoardSim\Infrastructure;

use BoardSim\Content\CanonicalJson;
use BoardSim\Content\EvidenceJson;
use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Run\RunStore;
use BoardSim\Domain\Run\StoreDiagnostics;
use PDO;

/**
 * MySQL implementation of RunStore.
 *
 * Two details matter more than the SQL:
 *
 *  1. JSON payloads are stored as LONGTEXT, never as MySQL JSON. MySQL JSON
 *     reorders object keys, which would change a stored canonical hash and
 *     silently break replay. The columns use utf8mb4_bin, and every hash column
 *     uses ascii_bin, because the default ai_ci collation is case- and
 *     accent-insensitive and could treat two different digests as equal.
 *
 *  2. Events are read back with their JSON columns decoded, so callers see the
 *     same shapes as the in-memory store and the hash chain verifies identically
 *     against either adapter.
 */
final class MysqlRunStore implements RunStore, StoreDiagnostics
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function findForUpdate(string $runId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM runs WHERE run_id = :run_id FOR UPDATE');
        $statement->execute(['run_id' => $runId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateRun($row) : null;
    }

    /**
     * Deployment status for `/v1/health`, not for the API contract.
     *
     * A schema that is absent, partial or unknown is a deployment fact the
     * operator must be able to read from a browser, because on a host without
     * shell access there is no other channel. Everything here is wrapped: a probe
     * that throws would take down the one endpoint that explains why.
     */
    public function describe(): array
    {
        $required = [
            'content_bundles',
            'runs',
            'run_events',
            'run_board',
            'run_board_prose',
            'share_tokens',
            'rate_limits',
            'schema_migrations',
        ];
        $requiredMigrations = [
            '001_init.sql',
            '002_events.sql',
            '003_share_tokens.sql',
            '004_rate_limits.sql',
            '005_release_integrity.sql',
        ];
        $requiredColumns = [
            'runs' => ['difficulty_views_version', 'difficulty_views_sha256', 'board_sha256'],
            'run_events' => ['request_sha256', 'option_label', 'effects_json', 'content_sha256'],
        ];
        $result = [
            'reachable' => true,
            'schema' => 'unknown',
            'applied' => [],
            'missing_migrations' => [],
            'missing_columns' => [],
            'error' => null,
        ];

        try {
            $present = array_map(
                'strval',
                $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
            );

            $missing = array_values(array_diff($required, $present));

            if (count($missing) === count($required)) {
                $result['schema'] = 'missing';
            } elseif ($missing !== []) {
                $result['schema'] = 'partial';
            }

            if (in_array('schema_migrations', $present, true)) {
                $result['applied'] = array_map(
                    'strval',
                    $this->pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')
                        ->fetchAll(PDO::FETCH_COLUMN)
                );

                $result['missing_migrations'] = array_values(
                    array_diff($requiredMigrations, $result['applied'])
                );
            }

            foreach ($requiredColumns as $table => $columns) {
                if (!in_array($table, $present, true)) {
                    continue;
                }

                $found = array_map(
                    'strval',
                    $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN)
                );

                foreach (array_diff($columns, $found) as $column) {
                    $result['missing_columns'][] = $table . '.' . $column;
                }
            }

            if ($missing === []
                && $result['missing_migrations'] === []
                && $result['missing_columns'] === []
            ) {
                $result['schema'] = 'ok';
            } elseif ($result['schema'] !== 'missing') {
                $result['schema'] = 'partial';
            }
        } catch (\Throwable $e) {
            error_log('BoardSim schema probe: ' . $e->getMessage());
            $result['schema'] = 'unknown';
            $result['error'] = 'probe_failed';
        }

        return $result;
    }

    public function missingBundles(array $sha256): array
    {
        $hashes = array_values($sha256);

        if ($hashes === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $statement = $this->pdo->prepare(
                "SELECT bundle_sha256 FROM content_bundles WHERE bundle_sha256 IN ({$placeholders})"
            );
            $statement->execute($hashes);
            $found = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            error_log('BoardSim bundle probe: ' . $e->getMessage());

            return $hashes;
        }

        return array_values(array_diff($hashes, $found));
    }

    public function create(array $run): void
    {
        $sql = 'INSERT INTO runs (run_id, scenario_id, scenario_version, ruleset_version,
                    policy_sha256, regulatory_bundle_sha256, content_version, content_sha256,
                    difficulty_views_version, difficulty_views_sha256,
                    difficulty, risk_appetite, board_persona, date_of_simulation,
                    state, current_stage, state_sha256, score, dimensions, board_sha256, created_at, finalized_at)
                VALUES (:run_id, :scenario_id, :scenario_version, :ruleset_version,
                    :policy_sha256, :regulatory_bundle_sha256, :content_version, :content_sha256,
                    :difficulty_views_version, :difficulty_views_sha256,
                    :difficulty, :risk_appetite, :board_persona, :date_of_simulation,
                    :state, :current_stage, :state_sha256, :score, :dimensions, :board_sha256, :created_at, :finalized_at)';

        $this->pdo->prepare($sql)->execute([
            'run_id' => $run['run_id'],
            'scenario_id' => $run['scenario_id'],
            'scenario_version' => $run['scenario_version'],
            'ruleset_version' => $run['ruleset_version'],
            'policy_sha256' => $run['policy_sha256'],
            'regulatory_bundle_sha256' => $run['regulatory_bundle_sha256'],
            'content_version' => $run['content_version'],
            'content_sha256' => $run['content_sha256'],
            'difficulty_views_version' => $run['difficulty_views_version'],
            'difficulty_views_sha256' => $run['difficulty_views_sha256'],
            'difficulty' => $run['difficulty'],
            'risk_appetite' => $run['risk_appetite'],
            'board_persona' => $run['board_persona'],
            'date_of_simulation' => $run['date_of_simulation'],
            'state' => $run['state'],
            'current_stage' => $run['current_stage'],
            'state_sha256' => $run['state_sha256'],
            'score' => $run['score'],
            'dimensions' => CanonicalJson::encode((array) $run['dimensions']),
            'board_sha256' => $run['board_sha256'],
            'created_at' => gmdate('Y-m-d H:i:s', strtotime((string) $run['created_at']) ?: time()),
            'finalized_at' => $run['finalized_at'] === null
                ? null
                : gmdate('Y-m-d H:i:s', strtotime((string) $run['finalized_at']) ?: time()),
        ]);
    }

    public function find(string $runId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM runs WHERE run_id = :run_id');
        $statement->execute(['run_id' => $runId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateRun($row);
    }

    public function update(array $run): void
    {
        $sql = 'UPDATE runs SET state = :state, current_stage = :current_stage,
                    state_sha256 = :state_sha256, score = :score, dimensions = :dimensions,
                    board_sha256 = :board_sha256, finalized_at = :finalized_at
                WHERE run_id = :run_id';

        $this->pdo->prepare($sql)->execute([
            'state' => $run['state'],
            'current_stage' => $run['current_stage'],
            'state_sha256' => $run['state_sha256'],
            'score' => $run['score'],
            'dimensions' => CanonicalJson::encode((array) $run['dimensions']),
            'board_sha256' => $run['board_sha256'] ?? null,
            'finalized_at' => $run['finalized_at'] === null
                ? null
                : gmdate('Y-m-d H:i:s', strtotime((string) $run['finalized_at']) ?: time()),
            'run_id' => $run['run_id'],
        ]);
    }

    public function appendEvent(array $event): void
    {
        $sql = 'INSERT INTO run_events (run_id, sequence, idempotency_key, request_sha256, stage_id, option_id,
                    option_label, effects_json, content_sha256,
                    rule_ids, metric_delta, suppressed_json, visible_fact_ids, unknown_fact_ids,
                    regulatory_status, pre_state_sha256, post_state_sha256,
                    prev_event_sha256, event_sha256, recorded_at)
                VALUES (:run_id, :sequence, :idempotency_key, :request_sha256, :stage_id, :option_id,
                    :option_label, :effects_json, :content_sha256,
                    :rule_ids, :metric_delta, :suppressed_json, :visible_fact_ids, :unknown_fact_ids,
                    :regulatory_status, :pre_state_sha256, :post_state_sha256,
                    :prev_event_sha256, :event_sha256, :recorded_at)';

        try {
            $this->pdo->prepare($sql)->execute([
                'run_id' => $event['run_id'],
                'sequence' => $event['sequence'],
                'idempotency_key' => $event['idempotency_key'],
                'request_sha256' => $event['request_sha256'],
                'stage_id' => $event['stage_id'],
                'option_id' => $event['option_id'],
                'option_label' => $event['option_label'],
                'effects_json' => EvidenceJson::encode((array) $event['effects']),
                'content_sha256' => $event['content_sha256'],
                'rule_ids' => CanonicalJson::encode((array) $event['rule_ids']),
                'metric_delta' => CanonicalJson::encode((array) $event['metric_delta']),
                'suppressed_json' => CanonicalJson::encode((array) $event['suppressed']),
                'visible_fact_ids' => CanonicalJson::encode((array) $event['visible_fact_ids']),
                'unknown_fact_ids' => CanonicalJson::encode((array) $event['unknown_fact_ids']),
                'regulatory_status' => CanonicalJson::encode((array) $event['regulatory_status']),
                'pre_state_sha256' => $event['pre_state_sha256'],
                'post_state_sha256' => $event['post_state_sha256'],
                'prev_event_sha256' => $event['prev_event_sha256'],
                'event_sha256' => $event['event_sha256'],
                'recorded_at' => $event['recorded_at'],
            ]);
        } catch (\PDOException $e) {
            // 23000 = integrity constraint violation: the unique idempotency key
            // fired, so a concurrent submission already recorded this choice.
            if ((string) $e->getCode() === '23000') {
                throw DomainError::conflict('This idempotency key has already been used for this run');
            }

            throw $e;
        }
    }

    public function events(string $runId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM run_events WHERE run_id = :run_id ORDER BY sequence ASC'
        );
        $statement->execute(['run_id' => $runId]);

        $events = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = $this->hydrateEvent($row);
        }

        return $events;
    }

    public function eventByIdempotencyKey(string $runId, string $key): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM run_events WHERE run_id = :run_id AND idempotency_key = :idempotency_key'
        );
        $statement->execute(['run_id' => $runId, 'idempotency_key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateEvent($row) : null;
    }

    /**
     * @return list<string>
     */
    public function purgeBefore(string $cutoffIso): array
    {
        // COALESCE covers a run abandoned before it was finalised: an unfinished
        // run is still personal data and must not be immortal.
        $statement = $this->pdo->prepare(
            'SELECT run_id FROM runs WHERE COALESCE(finalized_at, created_at) < :cutoff'
        );
        $statement->execute(['cutoff' => $cutoffIso]);

        $purged = [];

        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $runId) {
            $this->delete((string) $runId);
            $purged[] = (string) $runId;
        }

        return $purged;
    }

    /**
     * Physical erasure, in one transaction, children first.
     *
     * Events and board selections are removed before the run row so the delete
     * works whether or not the foreign keys cascade. There is no soft-delete flag:
     * a row that survives a "deletion" is exactly the failure erasure exists to
     * prevent.
     */
    public function delete(string $runId): int
    {
        $events = $this->pdo
            ->prepare('SELECT COUNT(*) FROM run_events WHERE run_id = :run_id');
        $events->execute(['run_id' => $runId]);
        $removed = (int) $events->fetchColumn();

        $this->transaction(function () use ($runId): void {
            $this->pdo
                ->prepare('DELETE FROM run_board WHERE run_id = :run_id')
                ->execute(['run_id' => $runId]);
            $this->pdo
                ->prepare('DELETE FROM run_events WHERE run_id = :run_id')
                ->execute(['run_id' => $runId]);
            $this->pdo
                ->prepare('DELETE FROM runs WHERE run_id = :run_id')
                ->execute(['run_id' => $runId]);
        });

        return $removed;
    }

    public function saveBoard(string $runId, array $selections): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO run_board (run_id, field_key, option_id, substantiated)
             VALUES (:run_id, :field_key, :option_id, :substantiated)
             ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)'
        );

        foreach ($selections as $field => $optionId) {
            $statement->execute([
                'run_id' => $runId,
                'field_key' => (string) $field,
                'option_id' => (string) $optionId,
                'substantiated' => 0,
            ]);
        }
    }

    public function boardSelections(string $runId): array
    {
        $statement = $this->pdo->prepare('SELECT field_key, option_id FROM run_board WHERE run_id = :run_id');
        $statement->execute(['run_id' => $runId]);

        $selections = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $selections[(string) $row['field_key']] = (string) $row['option_id'];
        }

        return $selections;
    }

    public function noteBundle(string $sha256, string $kind, string $version, string $payload): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO content_bundles (bundle_sha256, bundle_kind, version, canonical_payload)
             VALUES (:sha, :kind, :version, :payload)'
        );
        $statement->execute([
            'sha' => $sha256,
            'kind' => $kind,
            'version' => $version,
            'payload' => $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateRun(array $row): array
    {
        return [
            'run_id' => (string) $row['run_id'],
            'scenario_id' => (string) $row['scenario_id'],
            'scenario_version' => (string) $row['scenario_version'],
            'ruleset_version' => (string) $row['ruleset_version'],
            'policy_sha256' => (string) $row['policy_sha256'],
            'regulatory_bundle_sha256' => (string) $row['regulatory_bundle_sha256'],
            'content_version' => (string) $row['content_version'],
            'content_sha256' => (string) $row['content_sha256'],
            'difficulty_views_version' => isset($row['difficulty_views_version'])
                ? (string) $row['difficulty_views_version'] : '',
            'difficulty_views_sha256' => isset($row['difficulty_views_sha256'])
                ? (string) $row['difficulty_views_sha256'] : '',
            'difficulty' => (string) $row['difficulty'],
            'risk_appetite' => (string) $row['risk_appetite'],
            'board_persona' => (string) $row['board_persona'],
            'date_of_simulation' => (string) $row['date_of_simulation'],
            'state' => (string) $row['state'],
            'current_stage' => $row['current_stage'] === null ? null : (string) $row['current_stage'],
            'state_sha256' => (string) $row['state_sha256'],
            'score' => $row['score'] === null ? null : (int) $row['score'],
            'dimensions' => $this->decodeMap((string) $row['dimensions']),
            'board_sha256' => isset($row['board_sha256']) && $row['board_sha256'] !== null
                ? (string) $row['board_sha256'] : null,
            'created_at' => (string) $row['created_at'],
            'finalized_at' => $row['finalized_at'] === null ? null : (string) $row['finalized_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateEvent(array $row): array
    {
        return [
            'run_id' => (string) $row['run_id'],
            'sequence' => (int) $row['sequence'],
            'idempotency_key' => (string) $row['idempotency_key'],
            'request_sha256' => isset($row['request_sha256']) ? (string) $row['request_sha256'] : '',
            'stage_id' => (string) $row['stage_id'],
            'option_id' => (string) $row['option_id'],
            'option_label' => isset($row['option_label']) ? (string) $row['option_label'] : '',
            'effects' => isset($row['effects_json']) ? $this->decodeMap($row['effects_json']) : [],
            'content_sha256' => isset($row['content_sha256']) ? (string) $row['content_sha256'] : '',
            'rule_ids' => $this->decodeList($row['rule_ids']),
            'metric_delta' => $this->decodeMap($row['metric_delta']),
            'suppressed' => $this->decodeList($row['suppressed_json']),
            'visible_fact_ids' => $this->decodeList($row['visible_fact_ids']),
            'unknown_fact_ids' => $this->decodeList($row['unknown_fact_ids']),
            'regulatory_status' => $this->decodeMap($row['regulatory_status']),
            'pre_state_sha256' => (string) $row['pre_state_sha256'],
            'post_state_sha256' => (string) $row['post_state_sha256'],
            'prev_event_sha256' => (string) $row['prev_event_sha256'],
            'event_sha256' => (string) $row['event_sha256'],
            'recorded_at' => (string) $row['recorded_at'],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMap(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
