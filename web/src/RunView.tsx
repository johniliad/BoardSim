import { useCallback, useEffect, useMemo, useState } from 'react';
import { ApiError, api, statusBadge, type RunView as Run } from './api';
import { navigate } from './nav';
import BoardStage from './BoardStage';
import Term from './Term';
import { useGlossaryUi } from './GlossaryUi';
import {
  forgetPendingDecision,
  pendingDecision,
  rememberPendingDecision,
  type PendingDecision,
} from './profileStore';

const STAGE_LABEL: Record<string, string> = { S1: 'Stage 1', S2: 'Stage 2', S3: 'Stage 3' };
const STAGE_ORDER = ['S1', 'S2', 'S3'];

/** `disruption_hours` → `Disruption hours`, `STAGE_1_OPEN` → `Stage 1 open`. */
function humanise(value: string): string {
  const spaced = value.replaceAll('_', ' ').toLowerCase();

  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

export default function RunView({ runId }: { runId: string }) {
  const [run, setRun] = useState<Run | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [chosen, setChosen] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const payload = await api.run(runId);
      setRun(payload);

      if (payload.state === 'FINALIZED' || payload.state === 'SCORED') {
        navigate(`/results/${runId}`);
      }
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Unable to load this run.');
    }
  }, [runId]);

  useEffect(() => {
    void load();
  }, [load]);

  const confirm = useCallback(async () => {
    if (run === null || run.stage === undefined || chosen === null) return;

    setBusy(true);
    setError(null);

    const existing = pendingDecision(runId);
    const operation: PendingDecision =
      existing?.stageId === run.stage.stage_id && existing.choiceId === chosen
        ? existing
        : {
            runId,
            stageId: run.stage.stage_id,
            choiceId: chosen,
            idempotencyKey: crypto.randomUUID(),
          };
    rememberPendingDecision(operation);

    try {
      await api.decide(runId, operation.stageId, operation.choiceId, operation.idempotencyKey);
      forgetPendingDecision(runId);
      setChosen(null);
      await load();
    } catch (e: unknown) {
      if (!(e instanceof ApiError) || e.status !== 0) forgetPendingDecision(runId);
      setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to record the decision.');
    } finally {
      setBusy(false);
    }
  }, [run, chosen, runId, load]);

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
            Back to the catalogue
          </button>
        </div>
      </section>
    );
  }

  if (run === null) {
    return (
      <section className="bs-section">
        <div className="bs-container">
          <p className="bs-lead" role="status">
            Loading run…
          </p>
        </div>
      </section>
    );
  }

  if (run.state === 'BOARD_OPEN') {
    return <BoardStage runId={runId} run={run} />;
  }

  const stage = run.stage;

  if (stage === undefined) {
    return (
      <section className="bs-section">
        <div className="bs-container">
          <p className="bs-notice">This run has no open stage.</p>
        </div>
      </section>
    );
  }

  return (
    <section className="bs-section">
      <div className="bs-container">
        <RunHeader run={run} />

        <div className="bs-stack bs-mt-5">
          <div className="bs-card">
            <p className="bs-eyebrow">
              {STAGE_LABEL[stage.stage_id] ?? stage.stage_id} · {stage.time}
            </p>
            {stage.briefing !== '' && (
              <p className="bs-muted-mb-2">{stage.briefing}</p>
            )}
            {stage.stakeholder_pressure !== null && stage.stakeholder_pressure !== '' && (
              <p className="bs-muted-mb-3">
                <strong>At the table:</strong> {stage.stakeholder_pressure}
              </p>
            )}
            <h1 className="bs-h-stage">{stage.inject}</h1>
            <p className="bs-kpi__meta">
              Choose one course of action. Options carry no point values; consequences are described
              in operational terms only.
            </p>
          </div>

          <div className="bs-choices" role="group" aria-label="Choose a course of action">
            {stage.options.map((option) => (
              <button
                key={option.id}
                type="button"
                className="bs-choice"
                aria-pressed={chosen === option.id}
                onClick={() => setChosen(option.id)}
              >
                <span className="bs-inline">
                  <span className="bs-choice__id">{option.id}</span>
                  <span className="bs-choice__label">{option.label}</span>
                </span>
                {Object.keys(option.effects).length > 0 && (
                  <span className="bs-choice__meta">
                    Operational effect:{' '}
                    {Object.entries(option.effects)
                      .map(([key, value]) => `${humanise(key)}: ${String(value)}`)
                      .join(' · ')}
                  </span>
                )}
              </button>
            ))}
          </div>

          <div className="bs-inline">
            <button
              className="bs-button"
              type="button"
              onClick={confirm}
              disabled={chosen === null || busy}
            >
              {busy ? 'Recording…' : 'Confirm decision'}
            </button>
            <span className="bs-kpi__meta">
              Decisions are final. A fresh attempt always begins a new run.
            </span>
          </div>

          <RegulatoryPanel regulatory={stage.regulatory} />
        </div>
      </div>
    </section>
  );
}

function RunHeader({ run }: { run: Run }) {
  const current = run.current_stage ?? '';
  const currentIndex = STAGE_ORDER.indexOf(current);

  return (
    <div className="bs-card bs-card--accent">
      <div className="bs-inline bs-justify-between">
        <div>
          <p className="bs-eyebrow bs-m-0">
            {run.pins.scenario_id} · v{run.pins.scenario_version}
          </p>
          <h2 className="bs-m-0-fs-110">
            <Term k={`difficulty.${run.difficulty}`}>{humanise(run.difficulty)}</Term>
            {' · '}
            <Term k={`appetite.${run.risk_appetite}`}>{humanise(run.risk_appetite)}</Term>
            {' · '}
            <Term k={`persona.${run.board_persona}`}>{humanise(run.board_persona)}</Term>
          </h2>
        </div>
        <span className="bs-badge bs-badge--accent">{humanise(run.state)}</span>
      </div>

      <ol className="bs-progress">
        {STAGE_ORDER.map((stageId, index) => (
          <li
            key={stageId}
            data-state={index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'todo'}
          >
            {STAGE_LABEL[stageId]}
          </li>
        ))}
        <li data-state={current === '' ? 'current' : 'todo'}>Board pack</li>
      </ol>
    </div>
  );
}

export function RegulatoryPanel({ regulatory }: { regulatory: Record<string, string> }) {
  const { openGlossary } = useGlossaryUi();
  const entries = useMemo(
    () => Object.entries(regulatory).sort(([a], [b]) => a.localeCompare(b)),
    [regulatory],
  );

  return (
    <div className="bs-card">
      <div className="bs-inline bs-justify-between-start">
        <p className="bs-eyebrow bs-mt-0">
          Regulatory applicability at this stage
        </p>
        <button
          type="button"
          className="bs-button bs-button--secondary"
          onClick={() => openGlossary('TRIGGERED')}
        >
          What do the statuses mean?
        </button>
      </div>
      <div className="bs-table-wrap">
        <table className="bs-table">
          <caption
            className="bs-caption"
          >
            Derived from authored facts only, never from the option chosen. <Term k="NOT_APPLICABLE">Not
            applicable</Term> means out of scope;{' '}
            <Term k="PENDING_CLASSIFICATION">Pending classification</Term> means the facts are not yet
            established.
          </caption>
          <thead>
            <tr>
              <th scope="col">Instrument</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            {entries.map(([id, status]) => (
              <tr key={id}>
                <td className="bs-mono">
                  <Term k={id} className="bs-mono">
                    {id}
                  </Term>
                </td>
                <td>
                  <span className={statusBadge(status as never)}>{humanise(status)}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
