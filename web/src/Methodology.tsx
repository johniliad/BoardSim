import { useEffect, useState } from 'react';
import { api, ApiError, type Health } from './api';
import { settingDetail, useGlossary } from './glossary';

/**
 * The methodology page.
 *
 * It restates nothing by hand: weights, appetite modifiers and score bands are
 * read from `/v1/glossary`, the pinned versions from `/v1/methodology`. If the
 * policy changes, this page changes with it — the only way a methodology page
 * can stay true.
 */
export default function Methodology() {
  const { glossary } = useGlossary();
  const [health, setHealth] = useState<(Health & { note: string }) | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .methodology()
      .then(setHealth)
      .catch((e: unknown) =>
        setError(
          e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to load the methodology.',
        ),
      );
  }, []);

  const appetites = (glossary?.settings ?? []).filter((entry) => entry.group === 'appetite');
  const bands = (glossary?.settings ?? []).filter((entry) => entry.group === 'band');
  const difficulty = (glossary?.settings ?? []).filter((entry) => entry.group === 'difficulty');

  return (
    <section className="bs-section">
      <div className="bs-container bs-prose bs-reading">
        <p className="bs-eyebrow">Methodology</p>
        <h1>How the score is calculated</h1>
        <p className="bs-lead">
          Every number on a result page comes from the arithmetic below, applied to the decisions you
          recorded. There is no model, no randomness and no hindsight: the same decisions always
          produce the same score.
        </p>

        {error !== null && (
          <p className="bs-notice bs-notice--critical" role="alert">
            {error}
          </p>
        )}

        <h2>The formula</h2>
        <p>
          Each of the ten dimensions starts at a neutral <strong>70</strong>. The rules that fire add
          or subtract their authored deltas, the risk-appetite setting adds its modifier to risk
          judgment only, and each dimension is then clipped to the range 0–100:
        </p>
        <pre className="bs-notice bs-pre">
          {`dimension[d] = clamp(70 + Σ rule_deltas[d] + appetite_modifier[d], 0, 100)
numerator    = Σ (weight[d] × dimension[d])
score        = floor((numerator + 50) / 100)`}
        </pre>
        <p>
          The score is therefore an integer from 0 to 100, derived exactly — not a percentage of
          correct answers. Every delta is visible in the rule trace on the result page, together
          with the arithmetic that produced the final number.
        </p>

        <h2>Weights</h2>
        {glossary === null ? (
          <p className="bs-kpi__meta">Loading the current weights…</p>
        ) : (
          <div className="bs-table-wrap">
            <table className="bs-table">
              <thead>
                <tr>
                  <th scope="col">Dimension</th>
                  <th scope="col">Code</th>
                  <th scope="col" className="bs-num">
                    Weight
                  </th>
                </tr>
              </thead>
              <tbody>
                {glossary.dimensions.map((entry) => (
                  <tr key={entry.code}>
                    <td>{entry.name}</td>
                    <td className="bs-mono">{entry.code}</td>
                    <td className="bs-num">{entry.weight}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <h2>What changes a score</h2>
        <ul>
          <li>
            <strong>The options you choose.</strong> Each option maps to an explicit list of decision
            rules; each rule moves named dimensions by fixed amounts.
          </li>
          <li>
            <strong>The substantiated board fields.</strong> A board field scores only when the
            selected option is substantiated; declined fields fire nothing and are listed as such.
          </li>
          <li>
            <strong>The risk appetite.</strong> It modifies four rules, on risk judgment only, and is
            published here rather than hidden in the engine:
            {appetites.length === 0 ? (
              <span className="bs-kpi__meta"> loading…</span>
            ) : (
              <ul>
                {appetites.map((entry) => (
                  <li key={entry.key}>
                    <strong>{entry.label}</strong> — {settingDetail(entry)}
                  </li>
                ))}
              </ul>
            )}
          </li>
        </ul>
        <p>
          Difficulty does not change the options or the rules; it changes how many board fields are
          collected, and therefore the attainable range:
          {difficulty.map((entry) => ` ${entry.label} (${entry.board_fields ?? 0} fields)`).join(' · ')}
          .
        </p>

        <h2>Score bands</h2>
        {bands.length > 0 && (
          <ul>
            {bands.map((entry) => (
              <li key={entry.key}>
                <strong>{entry.label}</strong> — from {entry.min} points
              </li>
            ))}
          </ul>
        )}

        <h2>What is pinned, and why</h2>
        <p>
          A run stores the SHA-256 hashes of everything that could change its result — the scoring
          policy, the regulatory bundle and the scenario content — so a score can be reproduced years
          later even after the policy is revised. Replaying the recorded decisions recomputes the
          same numbers, and a verification chain over the event log detects any modification of the
          record itself.
        </p>
        {health !== null && (
          <div className="bs-table-wrap">
            <table className="bs-table">
              <tbody>
                <tr>
                  <th scope="row">Ruleset</th>
                  <td className="bs-mono">{health.ruleset_version}</td>
                </tr>
                <tr>
                  <th scope="row">Policy SHA-256</th>
                  <td className="bs-mono">{health.policy_sha256}</td>
                </tr>
                <tr>
                  <th scope="row">Regulatory bundle</th>
                  <td className="bs-mono">{health.regulatory_bundle_sha256}</td>
                </tr>
                <tr>
                  <th scope="row">Scenario content</th>
                  <td className="bs-mono">
                    {health.content_version} · {health.content_sha256}
                  </td>
                </tr>
                <tr>
                  <th scope="row">Integrity controls</th>
                  <td>{health.integrity_controls.join(', ')}</td>
                </tr>
              </tbody>
            </table>
          </div>
        )}
        {health !== null && <p className="bs-notice">{health.note}</p>}

        <h2>Regulation is assessed separately</h2>
        <p>
          The regulatory panel reports applicability, trigger and reporting duties derived from
          authored facts — never from the option you picked, and never as a score input. A status of{' '}
          <em>pending classification</em> means the facts do not yet establish scope or threshold, and
          instruments that require counsel remain marked as such. See the{' '}
          <a href="#/glossary">glossary</a> for every instrument and its official source.
        </p>

        <h2>What the number is not</h2>
        <p>
          The weights and deltas are authored judgement, not empirical calibration. The score
          measures how a decision compares with the published BoardSim policy; it is not a measure of
          professional competence, and it has not been validated as a psychometric, hiring or
          compliance instrument. Every rule carries a NIST CSF 2.0 function so the trace can be read
          as an operating-model view as well as a score.
        </p>
      </div>
    </section>
  );
}
