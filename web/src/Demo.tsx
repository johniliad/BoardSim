import { Fragment, useEffect, useState } from 'react';
import { ApiError, api, type DemoChoice, type DemoStart } from './api';
import { navigate } from './nav';
import Term from './Term';

/**
 * The five-minute board challenge: the second landing route required by 06.
 *
 * It shows the mechanism — choice maps to rules, rules move dimensions — and
 * deliberately never shows a total. There is no total to show: the server does
 * not calculate one for a demo, so this screen cannot leak a score even if it
 * tried. Nothing here is stored, so there is nothing to retain or delete.
 */
export default function Demo() {
  const [demo, setDemo] = useState<DemoStart | null>(null);
  const [stageIndex, setStageIndex] = useState(0);
  const [choice, setChoice] = useState<DemoChoice | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api
      .demo()
      .then(setDemo)
      .catch((e: unknown) =>
        setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to load the challenge.'),
      );
  }, []);

  const choose = async (optionId: string) => {
    if (demo === null) {
      return;
    }

    setBusy(true);
    setError(null);

    try {
      setChoice(await api.demoDecide(demo.stages[stageIndex].stage_id, optionId));
    } catch (e: unknown) {
      setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to show the mechanism.');
    } finally {
      setBusy(false);
    }
  };

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

  if (demo === null) {
    return (
      <section className="bs-section">
        <div className="bs-container">
          <p className="bs-lead" role="status">
            Loading the challenge…
          </p>
        </div>
      </section>
    );
  }

  const stage = demo.stages[stageIndex];
  const last = stageIndex === demo.stages.length - 1;
  const finished = last && choice !== null;

  return (
    <section className="bs-section">
      <div className="bs-container bs-reading">
        <p className="bs-eyebrow">Board challenge · not scored · nothing stored</p>
        <h1>See how a decision becomes a score</h1>
        <p className="bs-lead">{demo.note}</p>
        <DemoBody
          stage={stage}
          stageLabel={`${stage.stage_id} · ${stage.time}`}
          choice={choice}
          busy={busy}
          last={last}
          finished={finished}
          onChoose={choose}
          onNext={() => {
            setChoice(null);
            setStageIndex(stageIndex + 1);
          }}
          onRestart={() => {
            setChoice(null);
            setStageIndex(0);
          }}
        />
        <p className="bs-kpi__meta">{demo.disclaimer}</p>
      </div>
    </section>
  );
}


interface DemoBodyProps {
  stage: DemoStart['stages'][number];
  stageLabel: string;
  choice: DemoChoice | null;
  busy: boolean;
  last: boolean;
  finished: boolean;
  onChoose: (optionId: string) => void;
  onNext: () => void;
  onRestart: () => void;
}

function DemoBody({
  stage,
  stageLabel,
  choice,
  busy,
  last,
  finished,
  onChoose,
  onNext,
  onRestart,
}: DemoBodyProps) {
  if (finished) {
    return (
      <div className="bs-card bs-card--accent">
        <p className="bs-eyebrow bs-mt-0">
          That is the mechanism
        </p>
        <p>
          A scored run adds the three things this challenge leaves out: regulatory guards that can
          suppress a rule, a single clamp per dimension, and one integer total. It also stores an
          stored event record and a result explanation.
        </p>
        <p className="bs-kpi__meta">
          Nothing from this challenge was saved. There is no profile entry and nothing to delete.
        </p>
        <div className="bs-inline">
          <button className="bs-button" type="button" onClick={() => navigate('/scenarios')}>
            Start a full scenario
          </button>
          <button className="bs-button bs-button--secondary" type="button" onClick={onRestart}>
            Try the other choices
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="bs-card">
      <p className="bs-eyebrow bs-mt-0">
        {stageLabel}
      </p>
      <p>{stage.inject}</p>

      {choice === null ? (
        <div className="bs-choices" role="group" aria-label="Choose a course of action">
          {stage.options.map((option) => (
            <button
              key={option.id}
              className="bs-choice"
              type="button"
              disabled={busy}
              onClick={() => onChoose(option.id)}
            >
              <span className="bs-choice__label">{option.label}</span>
            </button>
          ))}
        </div>
      ) : (
        <>
          <p className="bs-eyebrow bs-mt-4">
            What that choice did
          </p>
          <div className="bs-table-wrap">
            <table className="bs-table">
              <thead>
                <tr>
                  <th scope="col">Rule</th>
                  <th scope="col">Why it fired</th>
                  <th scope="col">Dimension movement</th>
                </tr>
              </thead>
              <tbody>
                {choice.mechanism.map((entry) => (
                  <tr key={entry.rule}>
                    <td className="bs-mono">
                      <Term k={entry.rule} className="bs-mono">
                        {entry.rule}
                      </Term>
                    </td>
                    <td>{entry.title}</td>
                    <td className="bs-mono">
                      {Object.keys(entry.deltas).length === 0
                        ? '—'
                        : Object.entries(entry.deltas).map(([code, value], index) => (
                            <Fragment key={code}>
                              {index > 0 ? ' · ' : ''}
                              <Term k={code} className="bs-mono">
                                {code}
                              </Term>{' '}
                              {value > 0 ? '+' : ''}
                              {value}
                            </Fragment>
                          ))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="bs-notice">{choice.note}</p>
          <div className="bs-inline">
            <button className="bs-button" type="button" onClick={onNext}>
              {last ? 'Finish the challenge' : 'Next stage'}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
