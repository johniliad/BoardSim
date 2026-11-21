import { Fragment, useEffect, useState } from 'react';
import { ApiError, api, type Counterfactuals, type Explanation } from './api';
import { addToProfile, forgetResult } from './profileStore';
import { navigate } from './nav';
import { useGlossary } from './glossary';
import Term from './Term';
import ResultHelp from './ResultHelp';

/** `disruption_hours` → `Disruption hours`, as in the decision ledger. */
function humanise(value: string): string {
  const spaced = value.replaceAll('_', ' ').toLowerCase();

  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

export default function ResultsView({ runId }: { runId: string }) {
  const [explain, setExplain] = useState<Explanation | null>(null);
  const { glossary } = useGlossary();
  const [error, setError] = useState<string | null>(null);
  const [showTrace, setShowTrace] = useState(false);
  const [alternatives, setAlternatives] = useState<Counterfactuals | null>(null);
  const [alternativesError, setAlternativesError] = useState<string | null>(null);
  const [loadingAlternatives, setLoadingAlternatives] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const erase = async () => {
    setDeleteError(null);

    try {
      await api.deleteRun(runId);
      forgetResult(runId);
      navigate('/');
    } catch (e: unknown) {
      setDeleteError(
        e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to delete this run.',
      );
    }
  };

  // Loaded on demand: three alternative replays are cheap but there is no reason
  // to compute them for someone who never asks.
  const loadAlternatives = () => {
    if (alternatives !== null || loadingAlternatives) {
      return;
    }

    setLoadingAlternatives(true);
    setAlternativesError(null);

    api
      .counterfactuals(runId)
      .then(setAlternatives)
      .catch((e: unknown) =>
        setAlternativesError(
          e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to load the alternatives.',
        ),
      )
      .finally(() => setLoadingAlternatives(false));
  };

  useEffect(() => {
    api
      .explain(runId)
      .then(setExplain)
      .catch((e: unknown) =>
        setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to load the result.'),
      );
  }, [runId]);

  if (error !== null) {
    return (
      <section className="bs-section">
        <div className="bs-container bs-reading">
          <p className="bs-notice bs-notice--critical" role="alert">
            {error}
          </p>
          <button
            className="bs-button bs-button--secondary"
            type="button"
            onClick={() => navigate('/scenarios')}
          >
            Start another scenario
          </button>
        </div>
      </section>
    );
  }

  if (explain === null) {
    return (
      <section className="bs-section">
        <div className="bs-container">
          <p className="bs-lead" role="status">
            Assembling the explanation…
          </p>
        </div>
      </section>
    );
  }

  const scoredRules = new Set(explain.trace.map((entry) => entry.rule));

  // Codes and scores come from the run's resolved release. Human-readable names come
  // from the public glossary, which describes the deployment's current catalogue.
  const dimensionRows = (glossary?.dimensions.map((entry) => entry.code) ?? Object.keys(explain.dimensions))
    .map((code) => ({
      code,
      name: glossary?.dimensions.find((entry) => entry.code === code)?.name ?? '',
    }));
  const integrityVerified = explain.chain.ok && explain.score_verified;

  return (
    <section className="bs-section">
      <div className="bs-container">
        <div className="bs-grid bs-mb-6">
          <div className="bs-kpi bs-kpi--accent">
            <span className="bs-kpi__label">Simulated judgment score</span>
            <span className="bs-kpi__value">{explain.score}</span>
            <span className="bs-kpi__meta">out of 100</span>
          </div>
          <div className="bs-kpi">
            <span className="bs-kpi__label">Band</span>
            <ResultHelp
              label={`Band: ${explain.label}`}
              title="What the Band means"
              description={
                <p>
                  The Band is a descriptive label assigned from the final 0–100 simulated-judgment
                  score using this run’s pinned ruleset. It is not pass/fail, a professional
                  certification, legal advice, a comparison with other people, or a psychometrically
                  validated grade.
                </p>
              }
            >
              <span className="bs-kpi__value bs-fs-115">{explain.label}</span>
            </ResultHelp>
            <span className="bs-kpi__meta">
              {explain.pins.scenario_id} · ruleset {explain.pins.ruleset_version}
            </span>
          </div>
          <div className="bs-kpi">
            <span className="bs-kpi__label">Run integrity</span>
            <ResultHelp
              label={`Run integrity: ${integrityVerified ? 'Verified' : 'Could not verify'}`}
              title="What Run integrity means"
              description={
                integrityVerified ? (
                  <p>
                    Verified means the saved decisions, board selections, event evidence, run state,
                    score, and dimension values are internally consistent and were reconstructed with
                    the run’s pinned release. It is a consistency check, not a digital signature.
                  </p>
                ) : (
                  <p>
                    BoardSim could not reproduce or validate part of the stored run. Your answer choices
                    cannot cause this. Do not rely on the result until the application operator has
                    investigated it; keep the run ID private when reporting the problem.
                  </p>
                )
              }
            >
              <span className="bs-kpi__value bs-fs-115">
                {integrityVerified ? 'Verified' : 'Could not verify'}
              </span>
            </ResultHelp>
            <span className="bs-kpi__meta">
              Stored {explain.stored_score} · reconstructed {explain.reconstructed_score} ·{' '}
              {explain.chain.events ?? 0} events
            </span>
            {!integrityVerified && explain.chain.reason != null && (
              <span className="bs-kpi__meta">Diagnostic: {explain.chain.reason}</span>
            )}
          </div>
        </div>

        <div className="bs-card bs-mb-6">
          <p className="bs-eyebrow bs-mt-0">
            Why this score
          </p>
          <p className="bs-kpi__meta">
            You started at 70 in each of ten dimensions. The rules below fired, ordered by stage then
            ascending rule id. Each dimension is clamped once, then weighted and rounded.
          </p>
          <p className="bs-mono bs-mt-0">
            {explain.rounding} → floor(({explain.numerator} + 50) / 100) = {explain.score}
          </p>

          <div className="bs-table-wrap bs-mt-4">
            <table className="bs-table">
              <caption
                className="bs-caption"
              >
                Dimension scores after all rules, with the weight applied.
              </caption>
              <thead>
                <tr>
                  <th scope="col">Dimension</th>
                  <th scope="col" className="bs-num">
                    Score
                  </th>
                  <th scope="col" className="bs-num">
                    Weight
                  </th>
                  <th scope="col" className="bs-num">
                    Contribution
                  </th>
                </tr>
              </thead>
              <tbody>
                {dimensionRows.map(({ code, name }) => (
                  <tr key={code}>
                    <td>
                      <Term k={code} className="bs-mono">
                        {code}
                      </Term>
                      {name === '' ? '' : ` ${name}`}
                    </td>
                    <td className="bs-num">{explain.dimensions[code]}</td>
                    <td className="bs-num">{explain.weights[code]}%</td>
                    <td className="bs-num">{explain.contributions[code]}</td>
                  </tr>
                ))}
                <tr>
                  <th scope="row">Weighted numerator</th>
                  <td className="bs-num" />
                  <td className="bs-num">100%</td>
                  <td className="bs-num">
                    <strong>{explain.numerator}</strong>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        {explain.consequences.length > 0 && (
          <div className="bs-card bs-mb-6">
            <p className="bs-eyebrow bs-mt-0">
              What your decisions did
            </p>
            <p className="bs-kpi__meta">
              These authored decision labels and business consequences were snapshotted into the
              hashed event when you decided. They are never a score input — the score comes only from
              the rule trace below.
            </p>
            <div className="bs-table-wrap bs-mt-3">
              <table className="bs-table">
                <thead>
                  <tr>
                    <th scope="col">Stage</th>
                    <th scope="col">Decision</th>
                    <th scope="col">Operational consequence</th>
                  </tr>
                </thead>
                <tbody>
                  {explain.consequences.map((entry) => (
                    <tr key={entry.stage_id}>
                      <th scope="row">{entry.stage_id}</th>
                      <td>{entry.decision}</td>
                      <td className="bs-mono">
                        {Object.keys(entry.effects).length === 0
                          ? '—'
                          : Object.entries(entry.effects)
                              .map(([key, value]) => `${humanise(key)}: ${String(value)}`)
                              .join(' · ')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {explain.board_declined.length > 0 && (
          <div className="bs-card bs-mb-6">
            <p className="bs-eyebrow bs-mt-0">
              Board fields that earned nothing
            </p>
            <p className="bs-kpi__meta">
              A structured field scores only when the selected option is substantiated. These
              selections were recorded but declined:
            </p>
            <ul className="bs-muted-90">
              {explain.board_declined.map((entry) => (
                <li key={entry.field}>
                  <strong>{entry.field.replaceAll('_', ' ')}</strong> — {entry.reason}
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="bs-card bs-mb-6">
          <div className="bs-inline bs-justify-between">
            <p className="bs-eyebrow bs-mt-0">
              Rule trace
            </p>
            <button
              className="bs-button bs-button--secondary"
              type="button"
              onClick={() => setShowTrace((value) => !value)}
              aria-expanded={showTrace}
            >
              {showTrace ? 'Hide trace' : `Show ${explain.trace.length} firings`}
            </button>
          </div>

          {showTrace && (
            <div className="bs-table-wrap bs-mt-4">
              <table className="bs-table">
                <thead>
                  <tr>
                    <th scope="col">Stage</th>
                    <th scope="col">Rule</th>
                    <th scope="col">Dimension deltas</th>
                    <th scope="col">Appetite</th>
                  </tr>
                </thead>
                <tbody>
                  {explain.trace.map((entry) => (
                    <tr key={`${entry.stage}-${entry.rule}`}>
                      <td className="bs-mono">{entry.stage}</td>
                      <td>
                        <Term k={entry.rule} className="bs-mono">
                          {entry.rule}
                        </Term>
                        <br />
                        <span className="bs-kpi__meta">{entry.title}</span>
                      </td>
                      <td className="bs-mono">
                        {Object.values(entry.deltas).every((value) => value === 0) ? (
                          '—'
                        ) : (
                          Object.entries(entry.deltas)
                            .filter(([, value]) => value !== 0)
                            .map(([code, value], index) => (
                              <Fragment key={code}>
                                {index > 0 ? ' · ' : ''}
                                <Term k={code} className="bs-mono">
                                  {code}
                                </Term>{' '}
                                {value > 0 ? '+' : ''}
                                {value}
                              </Fragment>
                            ))
                        )}
                      </td>
                      <td className="bs-num">
                        {entry.risk_appetite_delta === 0 ? (
                          '—'
                        ) : (
                          <>
                            {entry.risk_appetite_delta > 0 ? '+' : ''}
                            {entry.risk_appetite_delta}{' '}
                            <Term k="RJ" className="bs-mono">
                              RJ
                            </Term>
                          </>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        <div className="bs-card bs-mb-6">
          <div className="bs-inline bs-justify-between">
            <p className="bs-eyebrow bs-mt-0">
              What would change it
            </p>
            <button
              className="bs-button bs-button--secondary"
              type="button"
              aria-expanded={alternatives !== null}
              onClick={loadAlternatives}
            >
              {alternatives === null ? 'Show the alternatives' : 'Alternatives shown'}
            </button>
          </div>
          <p className="bs-kpi__meta">
            Each alternative substitutes one authored option and replays the whole run against the
            same pinned policy. Shown only after the run is locked, so it is a review aid rather than
            a way to optimise during play.
          </p>

          {loadingAlternatives && (
            <p className="bs-lead" role="status">
              Replaying the alternatives…
            </p>
          )}

          {alternativesError !== null && (
            <p className="bs-notice bs-notice--critical" role="alert">
              {alternativesError}
            </p>
          )}

          {alternatives !== null &&
            alternatives.stages.map((stage) => (
              <div key={stage.stage} className="bs-table-wrap bs-mt-4">
                <table className="bs-table">
                  <caption className="bs-kpi__meta">
                    <span className="bs-mono">{stage.stage}</span> — you chose “{stage.chosen_label}”
                  </caption>
                  <thead>
                    <tr>
                      <th scope="col">Alternative</th>
                      <th scope="col">Score</th>
                      <th scope="col">Rules swapped</th>
                      <th scope="col">Effect</th>
                    </tr>
                  </thead>
                  <tbody>
                    {stage.alternatives.map((alternative) => (
                      <tr key={`${stage.stage}-${alternative.option_id}`}>
                        <td>{alternative.label}</td>
                        <td className="bs-mono bs-num">
                          {alternative.score}
                          {alternative.score_delta !== 0 && (
                            <>
                              {' '}
                              ({alternative.score_delta > 0 ? '+' : ''}
                              {alternative.score_delta})
                            </>
                          )}
                        </td>
                        <td className="bs-mono">
                          {alternative.rules_added.length === 0 &&
                          alternative.rules_removed.length === 0 ? (
                            '—'
                          ) : (
                            <>
                              {alternative.rules_added.length > 0 && (
                                <>
                                  +
                                  {alternative.rules_added.map((rule, index) => (
                                    <Fragment key={rule}>
                                      {index > 0 ? ', ' : ''}
                                      <Term k={rule} className="bs-mono">
                                        {rule}
                                      </Term>
                                    </Fragment>
                                  ))}{' '}
                                </>
                              )}
                              {alternative.rules_removed.length > 0 && (
                                <>
                                  −
                                  {alternative.rules_removed.map((rule, index) => (
                                    <Fragment key={rule}>
                                      {index > 0 ? ', ' : ''}
                                      <Term k={rule} className="bs-mono">
                                        {rule}
                                      </Term>
                                    </Fragment>
                                  ))}
                                </>
                              )}
                            </>
                          )}
                        </td>
                        <td className="bs-mono">
                          {Object.keys(alternative.dimension_delta).length === 0
                            ? '—'
                            : Object.entries(alternative.dimension_delta).map(
                                ([code, value], index) => (
                                  <Fragment key={code}>
                                    {index > 0 ? ' · ' : ''}
                                    <Term k={code} className="bs-mono">
                                      {code}
                                    </Term>{' '}
                                    {value > 0 ? '+' : ''}
                                    {value}
                                  </Fragment>
                                ),
                              )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ))}

          {alternatives !== null && (
            <p className="bs-notice bs-mt-4">
              {alternatives.note}
            </p>
          )}
        </div>

        <div className="bs-card">
          <p className="bs-eyebrow bs-mt-0">
            Pinned inputs for this run
          </p>
          <div className="bs-table-wrap">
            <table className="bs-table">
              <tbody>
                <tr>
                  <th scope="row">Ruleset</th>
                  <td className="bs-mono">{explain.pins.ruleset_version}</td>
                </tr>
                <tr>
                  <th scope="row">Policy SHA-256</th>
                  <td className="bs-mono">{explain.pins.policy_sha256}</td>
                </tr>
                <tr>
                  <th scope="row">Regulatory bundle</th>
                  <td className="bs-mono">{explain.pins.regulatory_bundle_sha256}</td>
                </tr>
                <tr>
                  <th scope="row">Scenario content</th>
                  <td className="bs-mono">
                    {explain.pins.content_version} · {explain.pins.content_sha256}
                  </td>
                </tr>
                <tr>
                  <th scope="row">Distinct rules fired</th>
                  <td>{scoredRules.size}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p className="bs-notice bs-mt-4">
            This is an arithmetic result over an authored policy, not a measurement of professional
            capability. Regulatory applicability is reported separately and is not a legal opinion.
          </p>
          <div className="bs-inline">
            <button className="bs-button" type="button" onClick={() => navigate('/scenarios')}>
              Start another scenario
            </button>
            <button
              className="bs-button bs-button--secondary"
              type="button"
              onClick={() => {
                addToProfile(runId);
                navigate('/profile');
              }}
            >
              Add to my profile
            </button>
          </div>
        </div>

        <div className="bs-card">
          <p className="bs-eyebrow bs-mt-0">
            Delete this run
          </p>
          <p className="bs-kpi__meta bs-mt-0">
            Deletion is permanent. The run, its three decisions and its board selections are removed
            from the server, not hidden. Afterwards this result cannot be opened or checked because
              it no longer exists. This browser also removes it from local history and active profile
              selection after the server confirms deletion.
          </p>

          {deleteError !== null && (
            <p className="bs-notice bs-notice--critical" role="alert">
              {deleteError}
            </p>
          )}

          {confirmingDelete ? (
            <div className="bs-inline">
              <button className="bs-button" type="button" onClick={erase}>
                Yes, delete it permanently
              </button>
              <button
                className="bs-button bs-button--secondary"
                type="button"
                onClick={() => setConfirmingDelete(false)}
              >
                Keep it
              </button>
            </div>
          ) : (
            <div className="bs-inline">
              <button
                className="bs-button bs-button--secondary"
                type="button"
                onClick={() => setConfirmingDelete(true)}
              >
                Delete this run
              </button>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
