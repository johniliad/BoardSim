<?php

declare(strict_types=1);

namespace BoardSim\Http;

use BoardSim\Content\PinnedScenarios;
use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Run\RunService;
use BoardSim\Domain\Run\StoreUnavailable;

/**
 * Transport-agnostic API router.
 *
 * Returning [status, payload] instead of writing output means the same routing
 * and error mapping can be exercised from a CLI harness without a web server,
 * which is how the contract is verified in bin/verify-http.php.
 */
final class ApiRouter
{
    public function __construct(
        private readonly RunService $service,
        private readonly PinnedScenarios $pinned,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: mixed}
     */
    public function handle(string $method, string $uri, array $body): array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = preg_replace('#^.*?/v1#', '', rtrim($path, '/')) ?? '';
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        // Read the query from the URI rather than $_GET, so the router behaves
        // identically when called programmatically in a test.
        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);

        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        try {
            return $this->dispatch($method, $segments, $body, $query);
        } catch (DomainError $e) {
            return [$e->httpStatus, ['error' => $e->errorCode, 'message' => $e->getMessage()]];
        } catch (StoreUnavailable $e) {
            // 503, not 500: a missing or unmigrated database is a deployment
            // state, and /v1/health says which one. The reason code is short and
            // contains nothing sensitive.
            return [503, [
                'error' => 'DB_UNAVAILABLE',
                'reason' => $e->getMessage(),
                'message' => 'Persistence is unavailable. GET /v1/health reports what is missing.',
            ]];
        } catch (\Throwable $e) {
            error_log('BoardSim API failure: ' . $e->getMessage());

            return [500, ['error' => 'INTERNAL', 'message' => 'Unexpected server error']];
        }
    }

    /**
     * @param list<string> $segments
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     *
     * @return array{0: int, 1: mixed}
     */
    private function dispatch(string $method, array $segments, array $body, array $query): array
    {
        $first = $segments[0] ?? '';
        $count = count($segments);

        if ($first === 'health' && $count === 1 && $method === 'GET') {
            return [200, $this->service->health()];
        }

        if ($first === 'scenarios' && $count === 1 && $method === 'GET') {
            return [200, $this->catalogue()];
        }

        if ($first === 'methodology' && $count === 1 && $method === 'GET') {
            return [200, $this->service->health() + ['note' => self::METHODOLOGY_NOTE]];
        }

        if ($first === 'glossary' && $count === 1 && $method === 'GET') {
            return [200, $this->service->glossary()];
        }

        if ($first === 'profiles' && $count === 1 && $method === 'POST') {
            $runIds = $body['run_ids'] ?? null;

            if (!is_array($runIds)) {
                return [400, ['error' => 'INVALID_REQUEST', 'message' => 'run_ids must be an array']];
            }

            return [200, $this->service->profile(array_values(array_map('strval', $runIds)))];
        }

        if ($first === 'shares' && $count === 1 && $method === 'POST') {
            $runIds = $body['run_ids'] ?? null;

            if (!is_array($runIds)) {
                return [400, ['error' => 'INVALID_REQUEST', 'message' => 'run_ids must be an array']];
            }

            return [201, $this->service->shareCreate(array_values(array_map('strval', $runIds)))];
        }

        if ($first === 'shares' && $count === 2 && $method === 'GET') {
            return [200, $this->service->shareResolve($segments[1])];
        }

        if ($first === 'shares' && $count === 2 && $method === 'DELETE') {
            return [200, $this->service->shareRevoke($segments[1])];
        }

        if ($first === 'demo' && $count === 1 && $method === 'GET') {
            return [200, $this->service->demoStart()];
        }

        if ($first === 'demo' && $count === 2 && $segments[1] === 'decide' && $method === 'POST') {
            $stageId = $body['stage_id'] ?? null;
            $choiceId = $body['choice_id'] ?? null;

            if (!is_string($stageId) || !is_string($choiceId)) {
                return [400, ['error' => 'INVALID_REQUEST', 'message' => 'stage_id and choice_id are required']];
            }

            return [200, $this->service->demoDecide($stageId, $choiceId)];
        }

        if ($first === 'difficulties' && $count === 1 && $method === 'GET') {
            return [200, ['difficulties' => $this->service->availableDifficulties()]];
        }

        if ($first === 'board-rubric' && $count === 1 && $method === 'GET') {
            $difficulty = (string) ($query['difficulty'] ?? 'leadership');

            return [200, $this->service->boardRubric($difficulty)];
        }

        if ($first === 'refs' && $count === 2 && $method === 'GET') {
            return [200, $this->service->reference($segments[1])];
        }

        if ($first === 'runs') {
            if ($count === 1 && $method === 'POST') {
                return [201, $this->service->create($body)];
            }

            $runId = $segments[1] ?? '';

            if ($count === 2 && $method === 'GET') {
                return [200, $this->service->status($runId)];
            }

            if ($count === 2 && $method === 'DELETE') {
                return [200, $this->service->delete($runId)];
            }

            if ($count === 3 && $segments[2] === 'decisions' && $method === 'POST') {
                return [200, $this->service->decide(
                    $runId,
                    (string) ($body['idempotency_key'] ?? ''),
                    (string) ($body['stage_id'] ?? ''),
                    (string) ($body['choice_id'] ?? '')
                )];
            }

            if ($count === 3 && $segments[2] === 'board' && $method === 'POST') {
                $selections = $body['selections'] ?? null;

                if (!is_array($selections)) {
                    return [400, ['error' => 'INVALID_REQUEST', 'message' => 'selections must be an object']];
                }

                return [200, $this->service->board($runId, array_map('strval', $selections))];
            }

            if ($count === 3 && $segments[2] === 'explain' && $method === 'GET') {
                return [200, $this->service->explain($runId)];
            }

            if ($count === 3 && $segments[2] === 'counterfactuals' && $method === 'GET') {
                return [200, $this->service->counterfactuals($runId)];
            }
        }

        return [404, ['error' => 'NOT_FOUND', 'message' => 'No such endpoint']];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogue(): array
    {
        $scenarios = [];

        foreach ($this->pinned->loadAll() as $scenario) {
            $scenarios[] = [
                'scenario_id' => $scenario['scenario_id'],
                'version' => $scenario['version'],
                'name' => $scenario['scenario_name'],
                'industry' => $scenario['industry'],
                'track' => $scenario['track'],
                'difficulties' => $this->service->availableDifficulties(),
                'board_crisis_available' => false,
            ];
        }

        return ['content_version' => $this->pinned->contentVersion(), 'scenarios' => $scenarios];
    }

    private const METHODOLOGY_NOTE = 'Scores are relative to the published BoardSim policy, not a '
        . 'measure of professional capability. See docs/ for methodology and limitations.';
}
