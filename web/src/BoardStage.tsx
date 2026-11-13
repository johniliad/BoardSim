import { useEffect, useState } from 'react';
import { ApiError, api, type BoardRubric, type RunView as Run } from './api';
import { navigate } from './nav';

/**
 * The board room. Every field is a structured choice; there is deliberately no
 * free-text input, because prose is never graded and offering it would imply
 * otherwise.
 */
export default function BoardStage({ runId, run }: { runId: string; run: Run }) {
  const [rubric, setRubric] = useState<BoardRubric | null>(null);
  const [selections, setSelections] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [remaining, setRemaining] = useState<number | null>(null);

  // The preparation timer is advisory: it starts only on request, never blocks
  // submission, and is not part of any payload sent to the server.
  useEffect(() => {
    if (remaining === null || remaining <= 0) {
      return;
    }

    const handle = window.setTimeout(() => {
      setRemaining((value) => (value === null ? null : value - 1));
    }, 1000);

    return () => window.clearTimeout(handle);
  }, [remaining]);

  useEffect(() => {
    api
      .rubric(run.difficulty)
      .then(setRubric)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Unable to load the rubric'));
  }, [run.difficulty]);

  if (error !== null) {
    return (
      <section className="bs-section">
        <div className="bs-container bs-reading">
          <p className="bs-notice bs-notice--critical" role="alert">
            {error}
          </p>
        </div>
      </section>
    );
  }

  if (rubric === null) {
    return (
      <section className="bs-section">
        <div className="bs-container">
          <p className="bs-lead" role="status">
            Loading the board pack…
          </p>
        </div>
      </section>
    );
  }

  const complete = rubric.fields.every((field) => selections[field.key] !== undefined);

  const submit = async () => {
    setBusy(true);
    setError(null);

    try {
      await api.board(runId, selections);
      navigate(`/results/${runId}`);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to submit the board pack.');
      setBusy(false);
    }
  };

  return (
    <section className="bs-section">
      <div className="bs-container">
        <div className="bs-card bs-card--accent">
          <p className="bs-eyebrow bs-m-0">
            Board room · {run.pins.scenario_id}
          </p>
          <h1 className="bs-h-stage">Defend the decision</h1>
          <p className="bs-kpi__meta">
            {rubric.fields.length} structured fields for the {run.difficulty} view. Each is graded
            only when it is substantiated by the choice you make here — narrative is never scored.
          </p>
        </div>

        {rubric.challenges.length > 0 && (
          <div className="bs-card bs-mb-5">
            <p className="bs-eyebrow bs-mt-0">
              Prepare · not scored
            </p>
            <p className="bs-kpi__meta bs-mt-0">
              Three questions the board will ask. Use them to prepare; nothing you type is graded,
              and nothing on this card can change the score.
            </p>
            <ul className="bs-stack bs-list-plain">
              {rubric.challenges.map((card) => (
                <li key={card.id}>
                  <p className="bs-eyebrow bs-mb-tight">
                    {card.from} · {card.title}
                  </p>
                  <p className="bs-m-0">“{card.probe}”</p>
                </li>
              ))}
            </ul>
            <div className="bs-inline">
              {remaining === null ? (
                <button
                  className="bs-button bs-button--secondary"
                  type="button"
                  onClick={() => setRemaining(rubric.preparation.suggested_minutes * 60)}
                >
                  Start the suggested {rubric.preparation.suggested_minutes} minutes
                </button>
              ) : (
                // Deliberately not a live region: a countdown that announces every
                // second is hostile to screen-reader users and adds pressure that
                // 06 explicitly rules out. The text is available on demand.
                <p className="bs-kpi__meta bs-m-0">
                  {remaining > 0
                    ? `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')} suggested — take as long as you need.`
                    : 'Suggested time is up. Keep going; nothing is locked, and the timer never scored you.'}
                </p>
              )}
            </div>
          </div>
        )}

        <div className="bs-stack bs-mt-5">
          {rubric.fields.map((field, index) => (
            <fieldset key={field.key} className="bs-card bs-m-0">
              <legend className="bs-eyebrow bs-p-0">
                {index + 1} · {field.label}
              </legend>
              <p className="bs-kpi__meta bs-mt-0">
                {field.prompt}
              </p>
              <div className="bs-stack bs-gap-2">
                {field.options.map((option) => (
                  <label
                    key={option.id}
                    className="bs-choice bs-clickable"
                  >
                    <span className="bs-inline bs-items-start">
                      <input
                        type="radio"
                        name={field.key}
                        value={option.id}
                        checked={selections[field.key] === option.id}
                        onChange={() =>
                          setSelections((current) => ({ ...current, [field.key]: option.id }))
                        }
                        className="bs-mt-tight"
                      />
                      <span className="bs-choice__label bs-fw-500">
                        {option.label}
                      </span>
                    </span>
                  </label>
                ))}
              </div>
            </fieldset>
          ))}

          {error !== null && (
            <p className="bs-notice bs-notice--critical" role="alert">
              {error}
            </p>
          )}

          <div className="bs-inline">
            <button className="bs-button" type="button" onClick={submit} disabled={!complete || busy}>
              {busy ? 'Scoring…' : complete ? 'Submit board pack and score' : `Complete all ${rubric.fields.length} fields`}
            </button>
            <span className="bs-kpi__meta">
              {Object.keys(selections).length} of {rubric.fields.length} chosen. Scoring is final.
            </span>
          </div>
        </div>
      </div>
    </section>
  );
}
