/**
 * The browser's own profile: a list of run ids and, if one was created, the share
 * token. Nothing identifying lives here — it is a list of random ids, which is
 * exactly what the consent text says it holds.
 */
const RUNS_KEY = 'boardsim.profile.runs';
const SHARE_KEY = 'boardsim.profile.share';

function read(key: string): string[] {
  try {
    const raw = window.localStorage.getItem(key);
    const parsed: unknown = raw === null ? [] : JSON.parse(raw);

    return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string') : [];
  } catch {
    return [];
  }
}

export function profileRunIds(): string[] {
  return read(RUNS_KEY);
}

export function addToProfile(runId: string): string[] {
  const ids = profileRunIds();

  if (!ids.includes(runId)) {
    ids.push(runId);
    window.localStorage.setItem(RUNS_KEY, JSON.stringify(ids));
  }

  return ids;
}

export function removeFromProfile(runId: string): string[] {
  const ids = profileRunIds().filter((id) => id !== runId);
  window.localStorage.setItem(RUNS_KEY, JSON.stringify(ids));

  return ids;
}

export function clearProfile(): void {
  window.localStorage.removeItem(RUNS_KEY);
  window.localStorage.removeItem(SHARE_KEY);
}

export function shareToken(): string | null {
  const tokens = read(SHARE_KEY);

  return tokens.length > 0 ? tokens[0] : null;
}

export function rememberShare(token: string): void {
  window.localStorage.setItem(SHARE_KEY, JSON.stringify([token]));
}

export function forgetShare(): void {
  window.localStorage.removeItem(SHARE_KEY);
}
