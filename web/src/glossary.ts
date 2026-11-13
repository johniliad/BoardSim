/**
 * Glossary data: one fetch per session, cached in module scope.
 *
 * Lazy by design — nothing is fetched until a term is actually opened, so the
 * home page and the catalogue pay nothing for help text they may never show.
 */
import { useEffect, useState } from 'react';
import {
  api,
  type Glossary,
  type GlossaryDimension,
  type GlossaryInstrument,
  type GlossaryRule,
  type GlossarySetting,
  type GlossaryStatus,
} from './api';

let cache: Glossary | null = null;
let inflight: Promise<Glossary> | null = null;
let lastError: string | null = null;

export function loadGlossary(): Promise<Glossary> {
  if (cache !== null) {
    return Promise.resolve(cache);
  }

  if (inflight !== null) {
    return inflight;
  }

  inflight = api
    .glossary()
    .then((payload) => {
      cache = payload;
      lastError = null;

      return payload;
    })
    .catch((error: unknown) => {
      inflight = null;
      lastError = error instanceof Error ? error.message : 'The glossary is unavailable.';

      throw error;
    });

  return inflight;
}

export interface GlossaryState {
  glossary: Glossary | null;
  error: string | null;
}

export function useGlossary(): GlossaryState {
  const [state, setState] = useState<GlossaryState>(() => ({ glossary: cache, error: lastError }));

  useEffect(() => {
    if (cache !== null) {
      setState({ glossary: cache, error: null });

      return;
    }

    let alive = true;

    loadGlossary()
      .then((glossary) => {
        if (alive) setState({ glossary, error: null });
      })
      .catch((error: unknown) => {
        if (alive) {
          setState({
            glossary: null,
            error: error instanceof Error ? error.message : 'The glossary is unavailable.',
          });
        }
      });

    return () => {
      alive = false;
    };
  }, []);

  return state;
}

export type TermHit =
  | { kind: 'dimension'; entry: GlossaryDimension }
  | { kind: 'rule'; entry: GlossaryRule }
  | { kind: 'instrument'; entry: GlossaryInstrument }
  | { kind: 'setting'; entry: GlossarySetting }
  | { kind: 'status'; entry: GlossaryStatus };

/**
 * Resolve any rendered identifier to its glossary entry.
 *
 * Accepts a dimension code (SJ), a rule id (DR-028), an instrument id
 * (REG-NIS2-23), a setting key (difficulty.leadership) or a status code.
 */
export function findTerm(glossary: Glossary, key: string): TermHit | null {
  const wanted = key.trim();

  if (wanted === '') {
    return null;
  }

  const dimension = glossary.dimensions.find((entry) => entry.code === wanted);

  if (dimension !== undefined) {
    return { kind: 'dimension', entry: dimension };
  }

  const rule = glossary.rules.find((entry) => entry.id === wanted);

  if (rule !== undefined) {
    return { kind: 'rule', entry: rule };
  }

  const instrument = glossary.instruments.find((entry) => entry.id === wanted);

  if (instrument !== undefined) {
    return { kind: 'instrument', entry: instrument };
  }

  const setting = glossary.settings.find((entry) => entry.key === wanted);

  if (setting !== undefined) {
    return { kind: 'setting', entry: setting };
  }

  const status = glossary.statuses.find((entry) => entry.code === wanted);

  if (status !== undefined) {
    return { kind: 'status', entry: status };
  }

  return null;
}

export function dimensionName(glossary: Glossary | null, code: string): string {
  return glossary?.dimensions.find((entry) => entry.code === code)?.name ?? code;
}

/** Signed, compact rendering of an appetite's RJ modifiers. */
function appetiteDetail(setting: GlossarySetting): string {
  const modifiers = Object.entries(setting.modifiers ?? {});

  if (modifiers.length === 0) {
    return 'No modifier — balanced appetite leaves every rule delta unchanged.';
  }

  return modifiers
    .map(([rule, delta]) => `${rule} ${delta > 0 ? '+' : ''}${delta}`)
    .join(' · ');
}

export function settingDetail(setting: GlossarySetting): string {
  switch (setting.group) {
    case 'difficulty': {
      const parts = [
        `${setting.board_fields ?? 0} board fields`,
        setting.time_estimate ?? '',
        setting.available === false ? 'Not available' : '',
      ].filter((part) => part !== '');

      return parts.join(' · ');
    }
    case 'appetite':
      return appetiteDetail(setting);
    case 'persona':
      return 'Display only — changes the dialogue, never the score.';
    default:
      return `Band starts at ${setting.min ?? 0} points.`;
  }
}
