import { useCallback, useEffect, useMemo, useState } from 'react';
import { ApiError, api, type DifficultyOption, type RunView, type ScenarioEntry } from './api';
import { navigate } from './nav';
import { useGlossaryUi } from './GlossaryUi';

const APPETITES = ['conservative', 'balanced', 'aggressive'];
const PERSONAS = ['balanced', 'risk_conservative', 'growth', 'financial', 'regulatory'];
const SELECT_CLASS = 'bs-select';

export default function Scenarios() {
  const { openGlossary } = useGlossaryUi();
  const [scenarios, setScenarios] = useState<ScenarioEntry[] | null>(null);
  const [difficulties, setDifficulties] = useState<DifficultyOption[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [difficulty, setDifficulty] = useState('leadership');
  const [appetite, setAppetite] = useState('balanced');
  const [persona, setPersona] = useState('balanced');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api
      .scenarios()
      .then((payload) => setScenarios(payload.scenarios))
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Unable to load scenarios'));

    // Difficulties come from the server policy, so an unavailable view is
    // rendered disabled with its own explanation rather than hidden.
    api
      .difficulties()
      .then((payload) => setDifficulties(payload.difficulties))
      .catch(() => setDifficulties([]));
  }, []);

  const start = useCallback(async () => {
    if (selected === null) return;

    setBusy(true);
    setError(null);

    try {
      const run: RunView = await api.createRun({
        scenario_id: selected,
        difficulty,
        risk_appetite: appetite,
        board_persona: persona,
        date_of_simulation: new Date().toISOString().slice(0, 10),
      });
      navigate(`/run/${run.run_id}`);
    } catch (e: unknown) {
      setError(
        e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to create the run right now.',
      );
      setBusy(false);
    }
  }, [selected, difficulty, appetite, persona]);

  const grouped = useMemo(() => {
    const tracks = new Map<string, ScenarioEntry[]>();

    (scenarios ?? []).forEach((scenario) => {
      tracks.set(scenario.track, [...(tracks.get(scenario.track) ?? []), scenario]);
    });

    return [...tracks.entries()];
  }, [scenarios]);

  return (
    <section className="bs-section">
      <div className="bs-container">
        <div className="bs-section__head">
          <div>
            <p className="bs-eyebrow">Catalogue</p>
            <h1>Choose a scenario</h1>
            <p className="bs-lead">
              Difficulty and risk appetite lock at creation, so an assessment can never be re-scored
              by changing them afterwards.
            </p>
          </div>
        </div>

        {error !== null && (
          <p className="bs-notice bs-notice--critical" role="alert">
            {error}
          </p>
        )}

        <div className="bs-card bs-mb-6">
          <div className="bs-inline bs-justify-between-start">
            <p className="bs-eyebrow bs-mt-0">
              Run settings
            </p>
            <button
              type="button"
              className="bs-button bs-button--secondary"
              onClick={() => openGlossary('difficulty.leadership')}
            >
              What do these settings mean?
            </button>
          </div>
          <div className="bs-grid">
            <label className="bs-field">
              <span>Difficulty</span>
              <select
                className={SELECT_CLASS}
                value={difficulty}
                onChange={(e) => setDifficulty(e.target.value)}
              >
                {difficulties.length === 0 && <option value="leadership">Leadership</option>}
                {difficulties.map((option) => (
                  <option key={option.key} value={option.key} disabled={!option.available}>
                    {option.label}
                    {option.available ? '' : ' — not available'}
                  </option>
                ))}
              </select>
            </label>

            <label className="bs-field">
              <span>Risk appetite</span>
              <select
                className={SELECT_CLASS}
                value={appetite}
                onChange={(e) => setAppetite(e.target.value)}
              >
                {APPETITES.map((value) => (
                  <option key={value} value={value}>
                    {value.charAt(0).toUpperCase() + value.slice(1)}
                  </option>
                ))}
              </select>
            </label>

            <label className="bs-field">
              <span>Board persona</span>
              <select
                className={SELECT_CLASS}
                value={persona}
                onChange={(e) => setPersona(e.target.value)}
              >
                {PERSONAS.map((value) => (
                  <option key={value} value={value}>
                    {value.replace('_', ' ')}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <p className="bs-notice bs-mt-4">
            {(() => {
              const chosen = difficulties.find((option) => option.key === difficulty);

              if (chosen === undefined) {
                return 'Difficulty sets how many board fields you must complete; it therefore changes the attainable score.';
              }

              const timing = chosen.time_estimate === '' ? '' : ` · ${chosen.time_estimate}`;
              const collected = chosen.available
                ? `${chosen.board_field_count} board fields will be collected`
                : `Not available: ${chosen.unavailable_reason ?? 'reserved'}`;

              return `${collected}${timing}. ${chosen.description}`;
            })()}
          </p>
        </div>

        <div className="bs-stack">
          {grouped.map(([track, entries]) => (
            <div key={track}>
              <h2 className="bs-fs-110">{track} track</h2>
              <div className="bs-grid">
                {entries.map((scenario) => (
                  <button
                    key={scenario.scenario_id}
                    type="button"
                    className="bs-choice"
                    aria-pressed={selected === scenario.scenario_id}
                    onClick={() => setSelected(scenario.scenario_id)}
                  >
                    <span className="bs-inline">
                      <span className="bs-choice__id">
                        {scenario.scenario_id.replace('BS-', '')}
                      </span>
                      <span className="bs-badge">{scenario.industry}</span>
                    </span>
                    <span className="bs-choice__label">{scenario.name}</span>
                    <span className="bs-choice__meta">version {scenario.version}</span>
                  </button>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="bs-inline bs-mt-6">
          <button
            className="bs-button"
            type="button"
            onClick={start}
            disabled={selected === null || busy}
          >
            {busy ? 'Creating run…' : selected === null ? 'Select a scenario' : `Start ${selected}`}
          </button>
        </div>
      </div>
    </section>
  );
}
