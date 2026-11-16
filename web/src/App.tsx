import { useEffect, useState } from 'react';
import { api, type Health } from './api';
import Home from './Home';
import Demo from './Demo';
import Profile, { Shared } from './Profile';
import Scenarios from './Scenarios';
import RunView from './RunView';
import ResultsView from './ResultsView';
import { GlossaryPage, GlossaryUiProvider } from './GlossaryUi';
import About from './About';
import Methodology from './Methodology';

type Route =
  | { name: 'home' }
  | { name: 'demo' }
  | { name: 'profile' }
  | { name: 'shared'; token: string }
  | { name: 'scenarios' }
  | { name: 'glossary'; term: string | null }
  | { name: 'methodology' }
  | { name: 'about' }
  | { name: 'run'; id: string }
  | { name: 'results'; id: string };

function parseRoute(hash: string): Route {
  const raw = hash.replace(/^#/, '') || '/';
  const [path, queryString = ''] = raw.split('?');
  const query = new URLSearchParams(queryString);

  if (path === '/' || path === '') return { name: 'home' };
  if (path === '/demo') return { name: 'demo' };
  if (path === '/profile') return { name: 'profile' };
  if (path === '/glossary') return { name: 'glossary', term: query.get('term') };
  if (path === '/methodology') return { name: 'methodology' };
  if (path === '/about') return { name: 'about' };

  const shared = /^\/shared\/([0-9a-f]{64})$/.exec(path);
  if (shared) return { name: 'shared', token: shared[1] };
  if (path === '/scenarios') return { name: 'scenarios' };

  const run = /^\/run\/([0-9a-f-]+)$/.exec(path);
  if (run) return { name: 'run', id: run[1] };

  const results = /^\/results\/([0-9a-f-]+)$/.exec(path);
  if (results) return { name: 'results', id: results[1] };

  return { name: 'home' };
}

export default function App() {
  const [route, setRoute] = useState<Route>(() => parseRoute(window.location.hash));
  const [health, setHealth] = useState<Health | null>(null);
  const [healthError, setHealthError] = useState<string | null>(null);

  useEffect(() => {
    const onHash = () => setRoute(parseRoute(window.location.hash));
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);

  // The footer pins are the interface's only statement about which policy it is
  // scored under, so a failed probe must say why it failed rather than silently
  // degrade into "unavailable".
  useEffect(() => {
    api
      .health()
      .then((payload) => {
        setHealth(payload);
        setHealthError(null);
      })
      .catch((e: unknown) => {
        setHealth(null);
        setHealthError(e instanceof Error ? e.message : 'The /v1 API did not return a health payload.');
      });
  }, []);

  return (
    <>
      <a className="bs-skip" href="#main">
        Skip to content
      </a>

      <header className="bs-header">
        <div className="bs-header__inner">
          <a className="bs-brand" href="#/">
            {/* The mark is the logo from the brand sheet: a navy-to-blue knight,
                the one piece on the board that moves in a way nobody expects. It
                is decorative here because the wordmark beside it is real text,
                which a screen reader reads once and a crawler can index. */}
            <img
              className="bs-brand__mark"
              src="/app/boardsim-mark.svg"
              alt=""
              width={42}
              height={42}
              aria-hidden="true"
            />
            <span className="bs-brand__name">
              BoardSim
              <span className="bs-brand__tagline">Executive decision-making under pressure</span>
            </span>
          </a>

          <nav className="bs-nav" aria-label="Primary">
            <a href="#/" aria-current={route.name === 'home' ? 'page' : undefined}>
              Home
            </a>
            <a href="#/scenarios" aria-current={route.name === 'scenarios' ? 'page' : undefined}>
              Scenarios
            </a>
            <a href="#/glossary" aria-current={route.name === 'glossary' ? 'page' : undefined}>
              Glossary
            </a>
            <a
              href="#/methodology"
              aria-current={route.name === 'methodology' ? 'page' : undefined}
            >
              Methodology
            </a>
            <a href="#/about" aria-current={route.name === 'about' ? 'page' : undefined}>
              About
            </a>
            <a href="https://www.iliadis.net" rel="author">
              iliadis.net
            </a>
          </nav>
        </div>
      </header>

      {healthError !== null && (
        <div className="bs-container bs-mt-4">
          <p className="bs-notice bs-notice--critical" role="alert">
            <strong>The /v1 API is not answering with JSON.</strong> Scenarios, runs and scores
            cannot be loaded until it does. {healthError}
          </p>
        </div>
      )}

      <GlossaryUiProvider>
        <main id="main">
          {route.name === 'home' && <Home health={health} />}
          {route.name === 'demo' && <Demo />}
          {route.name === 'profile' && <Profile />}
          {route.name === 'shared' && <Shared token={route.token} />}
          {route.name === 'scenarios' && <Scenarios />}
          {route.name === 'glossary' && <GlossaryPage term={route.term} />}
          {route.name === 'methodology' && <Methodology />}
          {route.name === 'about' && <About />}
          {route.name === 'run' && <RunView runId={route.id} />}
          {route.name === 'results' && <ResultsView runId={route.id} />}
        </main>
      </GlossaryUiProvider>

      <footer className="bs-footer">
        <div className="bs-footer__inner">
          <p className="bs-footer__legal">
            BoardSim is a simulated executive decision-making self-development aid. Its fictional
            cases, invented weights and authored options have not been validated as a psychometric,
            hiring or regulatory-compliance instrument. Multiple choices can be defensible, and
            scores are relative to the published BoardSim policy, not universal executive
            competence.
          </p>
          <div className="bs-footer__meta">
            <p className="bs-byline">
              <span>Built by</span>
              <a href="https://www.iliadis.net/#john-iliadis" rel="author">
                Dr. John ILIADIS
              </a>
              <span className="bs-byline__dot" aria-hidden="true">
                ·
              </span>
              <span className="bs-byline__role">Cybersecurity Executive Decision Simulator</span>
              <span className="bs-byline__dot" aria-hidden="true">
                ·
              </span>
              <a href="#/about">About this product</a>
              <span className="bs-byline__dot" aria-hidden="true">
                ·
              </span>
              {/* The licence reaches the browser as a served file, not as a repo
                  link: what a visitor downloads is the object form of React and
                  this application, so the notice has to travel with it. */}
              <a href="/licences.txt">Licences and attributions</a>
              <span className="bs-byline__dot" aria-hidden="true">
                ·
              </span>
              <span className="bs-byline__role">build {__BUILD_DATE__}</span>
            </p>
            <p>
              {health === null ? (
              <span title={healthError ?? undefined}>
                Scoring policy unavailable — the API did not report one.
              </span>
            ) : (
              <>
                <span className="bs-mono">ruleset {health.ruleset_version}</span>
                {' · '}
                <span className="bs-mono" title={health.policy_sha256}>
                  policy {health.policy_sha256.slice(0, 8)}
                </span>
                {' · '}
                <span className="bs-mono" title={health.regulatory_bundle_sha256}>
                  regulatory {health.regulatory_bundle_sha256.slice(0, 8)}
                </span>
                {' · '}
                <span className="bs-mono" title={health.content_sha256}>
                  content {health.content_version}
                </span>
                {health.mode === 'dev' ? ' · dev mode' : ''}
              </>
            )}
            </p>
          </div>
        </div>
      </footer>
    </>
  );
}
