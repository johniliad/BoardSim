/** Browser-owned result history and active profile selection. */
const LEGACY_RUNS_KEY = 'boardsim.profile.runs';
const HISTORY_KEY = 'boardsim.history.runs';
const SELECTION_KEY = 'boardsim.profile.selectedRuns';
const SHARE_KEY = 'boardsim.profile.share';
const PENDING_DECISION_PREFIX = 'boardsim.pendingDecision.';

export const MAX_PROFILE_RUNS = 20;

function read(key: string): string[] {
  try {
    const raw = window.localStorage.getItem(key);
    const parsed: unknown = raw === null ? [] : JSON.parse(raw);

    return Array.isArray(parsed)
      ? [...new Set(parsed.filter((value): value is string => typeof value === 'string'))]
      : [];
  } catch {
    return [];
  }
}

function write(key: string, values: string[]): void {
  window.localStorage.setItem(key, JSON.stringify([...new Set(values)]));
}

function migrate(): void {
  const legacy = read(LEGACY_RUNS_KEY);
  if (legacy.length === 0) return;
  if (read(HISTORY_KEY).length === 0) write(HISTORY_KEY, legacy);
  if (read(SELECTION_KEY).length === 0) write(SELECTION_KEY, legacy.slice(0, MAX_PROFILE_RUNS));
  window.localStorage.removeItem(LEGACY_RUNS_KEY);
}

export function resultHistoryRunIds(): string[] {
  migrate();
  return read(HISTORY_KEY);
}

export function profileRunIds(): string[] {
  migrate();
  return read(SELECTION_KEY).slice(0, MAX_PROFILE_RUNS);
}

export function rememberResult(runId: string): void {
  const history = resultHistoryRunIds();
  if (!history.includes(runId)) write(HISTORY_KEY, [...history, runId]);
}

export function addToProfile(runId: string): string[] {
  rememberResult(runId);
  const ids = profileRunIds();

  if (!ids.includes(runId)) {
    if (ids.length >= MAX_PROFILE_RUNS) {
      throw new Error(`A profile can contain at most ${MAX_PROFILE_RUNS} selected results.`);
    }
    ids.push(runId);
    write(SELECTION_KEY, ids);
  }

  return ids;
}

export function removeFromProfile(runId: string): string[] {
  const ids = profileRunIds().filter((id) => id !== runId);
  write(SELECTION_KEY, ids);

  return ids;
}

export function forgetResult(runId: string): void {
  write(HISTORY_KEY, resultHistoryRunIds().filter((id) => id !== runId));
  removeFromProfile(runId);
}

export function clearProfile(): void {
  window.localStorage.removeItem(HISTORY_KEY);
  window.localStorage.removeItem(SELECTION_KEY);
}

export function shareToken(): string | null {
  const tokens = read(SHARE_KEY);

  return tokens.length > 0 ? tokens[0] : null;
}

export function rememberShare(token: string): void {
  write(SHARE_KEY, [token]);
}

export function forgetShare(): void {
  window.localStorage.removeItem(SHARE_KEY);
}

export interface PendingDecision {
  runId: string;
  stageId: string;
  choiceId: string;
  idempotencyKey: string;
}

export function pendingDecision(runId: string): PendingDecision | null {
  try {
    const raw = window.sessionStorage.getItem(`${PENDING_DECISION_PREFIX}${runId}`);
    const value = raw === null ? null : (JSON.parse(raw) as Partial<PendingDecision>);
    return value?.runId === runId && typeof value.stageId === 'string'
      && typeof value.choiceId === 'string' && typeof value.idempotencyKey === 'string'
      ? (value as PendingDecision)
      : null;
  } catch {
    return null;
  }
}

export function rememberPendingDecision(value: PendingDecision): void {
  window.sessionStorage.setItem(`${PENDING_DECISION_PREFIX}${value.runId}`, JSON.stringify(value));
}

export function forgetPendingDecision(runId: string): void {
  window.sessionStorage.removeItem(`${PENDING_DECISION_PREFIX}${runId}`);
}
