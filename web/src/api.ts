/** Typed client for the BoardSim API. The server is the only source of truth. */

export type RegulatoryStatus =
  | 'NOT_APPLICABLE'
  | 'APPLICABLE_NOT_TRIGGERED'
  | 'TRIGGERED'
  | 'PENDING_CLASSIFICATION'
  | 'REQUIRES_HUMAN_REVIEW'
  | 'GUIDANCE_NOT_LAW';

export interface Pins {
  ruleset_version: string;
  policy_sha256: string;
  regulatory_bundle_sha256: string;
  content_version: string;
  content_sha256: string;
  difficulty_views_version: string;
  difficulty_views_sha256: string;
  scenario_id: string;
  scenario_version: string;
}

export interface CharOption {
  id: string;
  label: string;
  effects: Record<string, unknown>;
}

export interface StagePayload {
  stage_id: string;
  time: string;
  /** The standing situation the scenario opened with. */
  briefing: string;
  inject: string;
  is_primary: boolean;
  reveal: string[];
  stakeholder_pressure: string | null;
  options: CharOption[];
  regulatory: Record<string, RegulatoryStatus>;
}

export interface RunView {
  run_id: string;
  pins: Pins;
  difficulty: string;
  risk_appetite: string;
  board_persona: string;
  date_of_simulation: string;
  state: string;
  current_stage: string | null;
  score: number | null;
  dimensions: Record<string, number>;
  state_sha256: string;
  stage?: StagePayload;
  difficulty_view?: DifficultyView;
}

export interface DifficultyOption {
  key: string;
  label: string;
  available: boolean;
  time_estimate: string;
  description: string;
  board_field_count: number;
  unavailable_reason: string | null;
}

export interface DifficultyView extends DifficultyOption {
  primary_stage: string | null;
  stages: string[];
  show_effects: boolean;
  show_stakeholder_pressure: boolean;
  challenge_cards: number;
  difficulty_views_version: string;
  difficulty_views_sha256: string;
}

export interface ScenarioEntry {
  scenario_id: string;
  version: string;
  name: string;
  industry: string;
  track: string;
  difficulties: DifficultyOption[];
  board_crisis_available: boolean;
}

export interface Health {
  status: string;
  ruleset_version: string;
  policy_sha256: string;
  regulatory_bundle_sha256: string;
  content_version: string;
  content_sha256: string;
  scenarios: number;
  integrity_controls: string[];
  mode?: string;
}

/**
 * The in-product glossary. Derived server-side from the pinned policy, the
 * regulatory registry and the difficulty overlay, so it can never disagree with
 * the score it explains.
 */
export interface GlossaryDimension {
  code: string;
  name: string;
  weight: number;
  definition: string;
  rules: string[];
}

export interface GlossaryRule {
  id: string;
  title: string;
  rationale: string;
  dimensions: string[];
  /** NIST CSF 2.0 function the rule belongs to (GV, ID, PR, DE, RS, RC). */
  nist_function: string | null;
  board_rule: boolean;
}

export interface GlossaryInstrument {
  id: string;
  title: string;
  nature: string;
  verification_tier: string;
  reporting_duty: boolean;
  counsel_signed_off: boolean;
  source_url: string;
}

export interface GlossarySetting {
  key: string;
  group: 'difficulty' | 'appetite' | 'persona' | 'band';
  label: string;
  time_estimate?: string;
  board_fields?: number;
  available?: boolean;
  description?: string;
  unavailable_reason?: string | null;
  modifiers?: Record<string, number>;
  display_only?: boolean;
  min?: number;
}

export interface GlossaryStatus {
  code: RegulatoryStatus;
  label: string;
  meaning: string | null;
}

export interface Glossary {
  glossary_version: string;
  glossary_sha256: string;
  nist_functions: Record<string, string>;
  nist_functions_version: string;
  dimensions: GlossaryDimension[];
  rules: GlossaryRule[];
  instruments: GlossaryInstrument[];
  settings: GlossarySetting[];
  statuses: GlossaryStatus[];
}

export interface TraceEntry {
  stage: string;
  rule: string;
  title: string;
  deltas: Record<string, number>;
  risk_appetite_delta: number;
  pre_clamp: Record<string, number>;
  evidence_refs: string[];
  source_kind: string;
}

export interface Explanation {
  run_id: string;
  pins: Pins;
  score: number;
  stored_score: number;
  reconstructed_score: number;
  stored_dimensions: Record<string, number>;
  reconstructed_dimensions: Record<string, number>;
  score_verified: boolean;
  score_verification: {
    verified: boolean;
    score_matches: boolean;
    dimensions_match: boolean;
    missing_from_stored: string[];
    missing_from_reconstructed: string[];
    unexpected_in_stored: string[];
    unexpected_in_reconstructed: string[];
    invalid_in_stored: string[];
    invalid_in_reconstructed: string[];
    mismatched_dimensions: string[];
  };
  label: string;
  dimensions: Record<string, number>;
  weights: Record<string, number>;
  contributions: Record<string, number>;
  numerator: number;
  rounding: string;
  trace: TraceEntry[];
  /** The authored, non-scoring business consequences of each decision. */
  consequences: Array<{
    stage_id: string;
    option_id: string;
    decision: string;
    effects: Record<string, unknown>;
    regulatory_status: Record<string, RegulatoryStatus>;
  }>;
  board_declined: Array<{ field: string; rule: string; option: string; reason: string }>;
  events: Array<{
    sequence: number;
    stage_id: string;
    option_id: string;
    rule_ids: string[];
    metric_delta: Record<string, number>;
    suppressed: unknown[];
    regulatory_status: Record<string, RegulatoryStatus>;
    event_sha256: string;
  }>;
  chain: {
    ok: boolean;
    events?: number;
    head?: string;
    broken_at?: number | null;
    reason?: string;
    run_state_verified?: boolean;
    score_verified?: boolean | null;
    score_verification?: Explanation['score_verification'];
  };
  governance: Record<string, unknown>;
}

export interface CounterfactualAlternative {
  option_id: string;
  label: string;
  score: number;
  score_delta: number;
  label_for_score: string;
  dimension_delta: Record<string, number>;
  rules_added: string[];
  rules_removed: string[];
  summary: string;
}

export interface CounterfactualStage {
  stage: string;
  chosen_option_id: string;
  chosen_label: string;
  alternatives: CounterfactualAlternative[];
}

export interface Counterfactuals {
  run_id: string;
  pins: Pins;
  score: number;
  dimensions: Record<string, number>;
  stages: CounterfactualStage[];
  note: string;
  governance: Record<string, unknown>;
}

export interface BoardField {
  key: string;
  label: string;
  prompt: string;
  options: Array<{ id: string; label: string }>;
}

export interface DemoOption {
  id: string;
  label: string;
}

export interface DemoStage {
  stage_id: string;
  time: string;
  inject: string;
  options: DemoOption[];
}

export interface DemoStart {
  demo_id: string;
  version: string;
  sha256: string;
  scenario_name: string;
  difficulty: string;
  scored: false;
  persisted: false;
  stages: DemoStage[];
  disclaimer: string;
  note: string;
  governance: Record<string, unknown>;
}

export interface DemoMechanismRule {
  rule: string;
  title: string;
  deltas: Record<string, number>;
  source_kind: string;
}

export interface DemoChoice {
  demo_id: string;
  stage_id: string;
  choice_id: string;
  choice_label: string;
  mechanism: DemoMechanismRule[];
  dimension_effect: Record<string, number>;
  scored: false;
  note: string;
  governance: Record<string, unknown>;
}

export interface ChallengeCard {
  id: string;
  from: string;
  title: string;
  probe: string;
}

export interface Preparation {
  suggested_minutes: number;
  scoring: false;
  auto_start: boolean;
  hard_stop: boolean;
  note: string;
}

export interface BoardRubric {
  ruleset_version: string;
  difficulty: string;
  field_count: number;
  fields: BoardField[];
  challenges: ChallengeCard[];
  preparation: Preparation;
  challenges_version: string;
  challenges_sha256: string;
  note: string;
}

export interface BoardResult {
  run: RunView;
  score: number;
  label: string;
  dimensions: Record<string, number>;
  declined: Array<{ field: string; rule: string; option: string; reason: string }>;
}

export interface ProfileSegment {
  segment: string;
  ruleset_version: string;
  policy_sha256: string;
  content_version: string;
  content_sha256: string;
  difficulty_views_version: string;
  difficulty_views_sha256: string;
  difficulty: string;
  runs: number;
  status: 'eligible' | 'insufficient' | 'over_window';
  dimensions: Record<string, number> | null;
  overall: number | null;
  coverage: {
    distinct_scenarios: number;
    scenario_ids: string[];
    tracks: Record<string, number>;
    scenario_versions: string[];
    risk_appetites: string[];
  };
  caveats: string[];
  arithmetic?: string;
}

export interface ProfileRetention {
  run_retention_days: number;
  oldest_run_finalized_at: string | null;
  oldest_expires_in_days: number | null;
  note: string;
}

export interface Profile {
  segments: ProfileSegment[];
  segments_count: number;
  merged_across_versions: false;
  selection: {
    submitted_run_ids: string[];
    accepted_run_ids: string[];
    missing_run_ids: string[];
    unfinished_run_ids: string[];
    incompatible_run_ids: string[];
  };
  retention: ProfileRetention;
  note: string;
  governance: {
    source_kind: string;
    counsel_reviewed: boolean;
    psychometrically_calibrated: boolean;
  };
}

export interface ShareCreated {
  token: string;
  path: string;
  expires_at: string;
  expires_in_days: number;
  runs: number;
  profile: ProfileSegment[];
  note: string;
}

export interface SharedProfile {
  expires_at: string;
  expires_in_days: number;
  read_only: true;
  profile: Profile;
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(message);
  }
}

/** Where the router script lives, quoted in the diagnostics below. */
const ROUTER_HINT = 'php -S 127.0.0.1:8100 -t public web/dev-router.php';

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const url = `/v1${path}`;

  let response: Response;

  try {
    response = await fetch(url, {
      ...init,
      headers: { 'Content-Type': 'application/json', ...(init?.headers ?? {}) },
    });
  } catch {
    // fetch rejects only on a transport failure: nothing is serving this origin,
    // or the API process is down. A raw "Failed to fetch" names the wrong problem.
    throw new ApiError(
      0,
      'API_UNREACHABLE',
      `${url} could not be reached. The interface and the API must share one origin; ` +
        `for local development start the PHP server with its router script: ${ROUTER_HINT}.`,
    );
  }

  const text = await response.text();
  const contentType = response.headers.get('content-type') ?? '';

  let payload: unknown = null;

  if (text.trim() !== '') {
    try {
      payload = JSON.parse(text);
    } catch {
      // A static host with a history fallback (or PHP's own 404 page) answers /v1
      // with HTML. Report that instead of relaying the JSON.parse message, which
      // says "Unexpected token '<'" and hides the actual misconfiguration.
      const html = /^\s*(?:<!doctype|<html)/i.test(text);

      throw new ApiError(
        response.status,
        'NON_JSON_RESPONSE',
        `${url} returned ${html ? 'an HTML page' : contentType || 'an unparseable body'} ` +
          `(HTTP ${response.status}) instead of JSON, so the API front controller is not being ` +
          `reached at /v1 on this origin. Locally, serve it with ${ROUTER_HINT}; behind Apache, ` +
          'install deploy/api-index-shim.php as api/index.php and keep public/.htaccess in place.',
      );
    }
  }

  if (!response.ok) {
    const body = (payload ?? {}) as { error?: string; message?: string };
    throw new ApiError(response.status, body.error ?? 'ERROR', body.message ?? response.statusText);
  }

  return payload as T;
}

export const api = {
  health: () => request<Health>('/health'),
  scenarios: () => request<{ content_version: string; scenarios: ScenarioEntry[] }>('/scenarios'),
  difficulties: () => request<{ difficulties: DifficultyOption[] }>('/difficulties'),

  /** Dimensions, rules, instruments, settings and statuses, in one payload. */
  glossary: () => request<Glossary>('/glossary'),

  /** Health plus the methodology note; the same payload as /v1/health. */
  methodology: () => request<Health & { note: string }>('/methodology'),
  rubric: (difficulty: string) =>
    request<BoardRubric>(`/board-rubric?difficulty=${encodeURIComponent(difficulty)}`),

  createRun: (input: {
    scenario_id: string;
    difficulty: string;
    risk_appetite: string;
    board_persona: string;
    date_of_simulation: string;
  }) => request<RunView>('/runs', { method: 'POST', body: JSON.stringify(input) }),

  run: (id: string) => request<RunView>(`/runs/${id}`),

  decide: (id: string, stageId: string, choiceId: string, key: string) =>
    request<{ replayed: boolean; run: RunView }>(`/runs/${id}/decisions`, {
      method: 'POST',
      body: JSON.stringify({ idempotency_key: key, stage_id: stageId, choice_id: choiceId }),
    }),

  board: (id: string, selections: Record<string, string>) =>
    request<BoardResult>(`/runs/${id}/board`, {
      method: 'POST',
      body: JSON.stringify({ selections }),
    }),

  explain: (id: string) => request<Explanation>(`/runs/${id}/explain`),

  /**
   * "What would change it?" — each unchosen option substituted and replayed.
   * Available only once the run is scored; the server refuses otherwise.
   */
  counterfactuals: (id: string) => request<Counterfactuals>(`/runs/${id}/counterfactuals`),

  /** The public demonstration. Unscored, unpersisted, stateless. */
  demo: () => request<DemoStart>('/demo'),

  /**
   * Permanently erase a run and its event log. Not recoverable: the record is
   * physically deleted, so it can no longer be replayed or verified.
   */
  deleteRun: (id: string) =>
    request<{ run_id: string; deleted: true; events_removed: number; recoverable: false }>(
      `/runs/${id}`,
      { method: 'DELETE' },
    ),

  createShare: (runIds: string[]) =>
    request<ShareCreated>('/shares', {
      method: 'POST',
      body: JSON.stringify({ run_ids: runIds }),
    }),

  share: (token: string) => request<SharedProfile>(`/shares/${encodeURIComponent(token)}`),

  revokeShare: (token: string) =>
    request<{ revoked: true; already_revoked: boolean; revoked_at: string }>(
      `/shares/${encodeURIComponent(token)}`,
      { method: 'DELETE' },
    ),

  demoDecide: (stageId: string, choiceId: string) =>
    request<DemoChoice>('/demo/decide', {
      method: 'POST',
      body: JSON.stringify({ stage_id: stageId, choice_id: choiceId }),
    }),

  /**
   * Portfolio aggregation. Segments are keyed on exact policy, content and
   * difficulty-view hashes plus difficulty, so the response may carry several segments even
   * when one run list is supplied. A segment outside the 3–5 window returns
   * `overall: null` rather than a number.
   */
  profile: (runIds: string[]) =>
    request<Profile>('/profiles', {
      method: 'POST',
      body: JSON.stringify({ run_ids: runIds }),
    }),
};

/** Server-derived class names for a regulatory status. */
export function statusBadge(status: RegulatoryStatus): string {
  switch (status) {
    case 'TRIGGERED':
      return 'bs-badge bs-badge--critical';
    case 'PENDING_CLASSIFICATION':
      return 'bs-badge bs-badge--medium';
    case 'REQUIRES_HUMAN_REVIEW':
      return 'bs-badge bs-badge--high';
    case 'APPLICABLE_NOT_TRIGGERED':
      return 'bs-badge bs-badge--low';
    case 'NOT_APPLICABLE':
      return 'bs-badge bs-badge--none';
    default:
      return 'bs-badge';
  }
}
