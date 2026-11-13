import { useEffect, useState } from 'react';
import { ApiError, api, type Profile as ProfileData, type SharedProfile } from './api';
import {
  clearProfile,
  forgetShare,
  profileRunIds,
  rememberShare,
  removeFromProfile,
  shareToken,
} from './profileStore';
import { navigate } from './nav';
import { dimensionName, useGlossary } from './glossary';
import Term from './Term';

/** Shared rendering for the owner view and the read-only shared view. */
function SegmentTable({ profile }: { profile: ProfileData }) {
  const { glossary } = useGlossary();
  return (
    <>
      {profile.segments.map((segment) => (
        <div key={segment.segment} className="bs-card bs-mb-5">
          <p className="bs-eyebrow bs-mt-0">
            {segment.difficulty} · {segment.runs} of 5 scenarios · {segment.status}
          </p>
          {segment.overall === null ? (
            <p className="bs-notice">{segment.caveats.join(' ')}</p>
          ) : (
            <>
              <p className="bs-kpi__value bs-m-0">
                {segment.overall}
                <span className="bs-kpi__meta"> / 100</span>
              </p>
              <div className="bs-table-wrap bs-mt-4">
                <table className="bs-table">
                  <tbody>
                    {Object.entries(segment.dimensions ?? {}).map(([code, value]) => (
                      <tr key={code}>
                        <th scope="row">
                          <Term k={code} className="bs-mono">
                            {code}
                          </Term>{' '}
                          <span className="bs-kpi__meta">{dimensionName(glossary, code)}</span>
                        </th>
                        <td className="bs-mono bs-num">
                          {value}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="bs-kpi__meta">
                Coverage: {segment.coverage.distinct_scenarios} distinct scenarios ·{' '}
                {Object.keys(segment.coverage.tracks).join(', ')} · ruleset{' '}
                {segment.ruleset_version}
              </p>
              {segment.caveats.length > 0 && <p className="bs-notice">{segment.caveats.join(' ')}</p>}
            </>
          )}
        </div>
      ))}
    </>
  );
}


export default function Profile() {
  const [ids, setIds] = useState<string[]>([]);
  const [data, setData] = useState<ProfileData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [share, setShare] = useState<{ path: string; expires_at: string } | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const current = profileRunIds();
    setIds(current);
    setToken(shareToken());

    if (current.length === 0) {
      return;
    }

    api
      .profile(current)
      .then(setData)
      .catch((e: unknown) =>
        setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to load your profile.'),
      );
  }, []);

  const createShare = async () => {
    setBusy(true);
    setError(null);

    try {
      const created = await api.createShare(ids);
      rememberShare(created.token);
      setToken(created.token);
      setShare({ path: created.path, expires_at: created.expires_at });
    } catch (e: unknown) {
      setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to create a link.');
    } finally {
      setBusy(false);
    }
  };

  const revoke = async () => {
    if (token === null) {
      return;
    }

    setBusy(true);

    try {
      await api.revokeShare(token);
      forgetShare();
      setToken(null);
      setShare(null);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Unable to revoke the link.');
    } finally {
      setBusy(false);
    }
  };

  const daysLeft = data?.retention.oldest_expires_in_days ?? null;
  const eligible = data?.segments.some((segment) => segment.status === 'eligible') ?? false;

  return (
    <section className="bs-section">
      <div className="bs-container bs-reading">
        <p className="bs-eyebrow">Your profile · held in this browser only</p>
        <h1>Portfolio</h1>

        {error !== null && (
          <p className="bs-notice bs-notice--critical" role="alert">
            {error}
          </p>
        )}

        {ids.length === 0 ? (
          <div className="bs-card">
            <p>
              No scenarios saved yet. Finish a scenario and choose{' '}
              <strong>Add to my profile</strong> on the results page.
            </p>
            <button className="bs-button" type="button" onClick={() => navigate('/scenarios')}>
              Start a scenario
            </button>
          </div>
        ) : (
          <>
            <div className="bs-grid bs-mb-6">
              <div className="bs-kpi bs-kpi--accent">
                <span className="bs-kpi__label">Scenarios in profile</span>
                <span className="bs-kpi__value">{ids.length}</span>
                <span className="bs-kpi__meta">3 to 5 are needed</span>
              </div>
              <div className="bs-kpi">
                <span className="bs-kpi__label">Days left on the oldest</span>
                <span className="bs-kpi__value">{daysLeft === null ? '—' : daysLeft}</span>
                <span className="bs-kpi__meta">
                  {data === null
                    ? ''
                    : `deleted ${data.retention.run_retention_days} days after finishing`}
                </span>
              </div>
            </div>

            {data !== null && <SegmentTable profile={data} />}
            {data !== null && <p className="bs-notice">{data.retention.note}</p>}

            <SharePanel
              token={token}
              share={share}
              eligible={eligible}
              busy={busy}
              onCreate={() => void createShare()}
              onRevoke={() => void revoke()}
            />

            <SavedRuns
              ids={ids}
              onRemove={(id: string) => {
                const next = removeFromProfile(id);
                setIds(next);
                setData(null);

                if (next.length > 0) {
                  api.profile(next).then(setData).catch(() => setData(null));
                }
              }}
              onForget={() => {
                clearProfile();
                setIds([]);
                setData(null);
                setToken(null);
                setShare(null);
              }}
            />
          </>
        )}
      </div>
    </section>
  );
}


interface SharePanelProps {
  token: string | null;
  share: { path: string; expires_at: string } | null;
  eligible: boolean;
  busy: boolean;
  onCreate: () => void;
  onRevoke: () => void;
}

function SharePanel({ token, share, eligible, busy, onCreate, onRevoke }: SharePanelProps) {
  return (
    <div className="bs-card">
      <p className="bs-eyebrow bs-mt-0">
        Share
      </p>
      {token === null ? (
        <>
          <p className="bs-kpi__meta bs-mt-0">
            Creates an unguessable link, valid for 7 days, that shows this profile read-only. Anyone
            holding it can read the profile; revoking does not delete your runs.
          </p>
          <button
            className="bs-button"
            type="button"
            disabled={!eligible || busy}
            onClick={onCreate}
          >
            {eligible ? 'Create a 7-day link' : 'Needs 3 to 5 completed scenarios'}
          </button>
        </>
      ) : (
        <>
          <p className="bs-kpi__meta bs-mt-0">
            A link is live for 7 days. The token is shown once and cannot be recovered — if you lose
            it, revoke and create a new one.
          </p>
          {share !== null && (
            <p className="bs-mono bs-break-all">
              {window.location.origin}
              {share.path}
            </p>
          )}
          <button
            className="bs-button bs-button--secondary"
            type="button"
            disabled={busy}
            onClick={onRevoke}
          >
            Revoke the link now
          </button>
        </>
      )}
    </div>
  );
}

interface SavedRunsProps {
  ids: string[];
  onRemove: (id: string) => void;
  onForget: () => void;
}

function SavedRuns({ ids, onRemove, onForget }: SavedRunsProps) {
  return (
    <div className="bs-card">
      <p className="bs-eyebrow bs-mt-0">
        Saved scenarios
      </p>
      <div className="bs-table-wrap">
        <table className="bs-table">
          <tbody>
            {ids.map((id) => (
              <tr key={id}>
                <td className="bs-mono">{id.slice(0, 8)}…</td>
                <td className="bs-num">
                  <button
                    className="bs-button bs-button--secondary"
                    type="button"
                    onClick={() => onRemove(id)}
                  >
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="bs-inline">
        <button className="bs-button bs-button--secondary" type="button" onClick={onForget}>
          Forget everything in this browser
        </button>
      </div>
      <p className="bs-kpi__meta">
        Removing a scenario from this list does not delete the run. Use the delete button on the
        result page for that.
      </p>
    </div>
  );
}

/** Read-only view behind a share link. No controls: a visitor cannot revoke. */
export function Shared({ token }: { token: string }) {
  const [data, setData] = useState<SharedProfile | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .share(token)
      .then(setData)
      .catch(() => setError('This link has expired, was revoked, or its data has been deleted.'));
  }, [token]);

  return (
    <section className="bs-section">
      <div className="bs-container bs-reading">
        <p className="bs-eyebrow">Shared profile · read only</p>
        <h1>BoardSim portfolio</h1>

        {error !== null && (
          <p className="bs-notice bs-notice--critical" role="alert">
            {error}
          </p>
        )}

        {data === null && error === null && (
          <p className="bs-lead" role="status">
            Loading…
          </p>
        )}

        {data !== null && (
          <>
            <p className="bs-kpi__meta">
              Expires in {data.expires_in_days} day{data.expires_in_days === 1 ? '' : 's'} (
              {data.expires_at}).
            </p>
            <SegmentTable profile={data.profile} />
            <p className="bs-notice">
              This is how one person's choices compared with one published scoring policy. It is not
              an assessment of competence and says nothing about suitability for any role.
            </p>
            <button className="bs-button" type="button" onClick={() => navigate('/')}>
              Try a five-minute challenge
            </button>
          </>
        )}
      </div>
    </section>
  );
}
