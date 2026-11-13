import type { Health } from './api';
import { navigate } from './nav';

export default function Home({ health }: { health: Health | null }) {
  return (
    <>
      <section className="bs-hero">
        <div className="bs-hero__inner">
          <div>
            {/* The full lockup, as on the brand sheet. The <h1> below is the
                page's statement, so this image is the identity, not a heading:
                alt text names the product and nothing is duplicated as text. */}
            <img
              className="bs-brand-lockup"
              src="/app/boardsim-logo.svg"
              alt="BoardSim"
              width={320}
              height={88}
            />
            <h1>Executive Decision-Making Under Pressure</h1>
            <p className="bs-hero__copy">
              Make the decision. Defend the decision. See the consequences. Fourteen staged
              scenarios put you in the chair while security, commercial continuity, regulation,
              finance and stakeholder pressure pull against each other.
            </p>
            <div className="bs-hero__actions">
              <button className="bs-button" type="button" onClick={() => navigate('/scenarios')}>
                Start a scenario
              </button>
              <a className="bs-button bs-button--secondary" href="#/demo">
                Explore a five-minute board challenge
              </a>
              <a className="bs-button bs-button--secondary" href="#/glossary">
                Glossary and scoring
              </a>
            </div>
          </div>

          <aside className="bs-card bs-card--accent">
            <h2 className="bs-fs-100">Deterministic by design</h2>
            <p className="bs-muted-90">
              Every score is computed server-side from pinned inputs using integer arithmetic. No
              model inference, no random events, no hindsight.
            </p>
            <ul className="bs-muted-list">
              <li>Ten reported dimensions, weighted to 100</li>
              <li>Three decisions, then a seven-field board pack</li>
              <li>Regulatory status derived from authored facts only</li>
            </ul>
            {health !== null && (
              <p className="bs-mono bs-muted-72">
                {health.scenarios} scenarios · integrity: {health.integrity_controls.join(', ')}
              </p>
            )}
          </aside>
        </div>
      </section>

      <section className="bs-section">
        <div className="bs-container">
          <div className="bs-section__head">
            <div>
              <h2>How a run works</h2>
              <p className="bs-lead">
                Three stages, one decision each, then a board briefing you must defend. The
                consequences you see are authored teaching outcomes, kept strictly separate from the
                score.
              </p>
            </div>
          </div>
          <div className="bs-grid">
            <div className="bs-card">
              <h3>1 · Decide</h3>
              <p>
                Each stage reveals only what is known at that moment. Options carry no point values,
                so you choose on judgement.
              </p>
            </div>
            <div className="bs-card">
              <h3>2 · Defend</h3>
              <p>
                Fill seven structured board fields: situation, impact, uncertainty, decision,
                investment, residual risk and the resolution you want.
              </p>
            </div>
            <div className="bs-card">
              <h3>3 · Understand</h3>
              <p>
                A full trace shows every rule that fired, the exact dimension deltas, and the
                arithmetic behind the integer score.
              </p>
            </div>
          </div>
        </div>
      </section>

      <section className="bs-section bs-section--alt" id="method">
        <div className="bs-container bs-prose bs-reading">
          <h2>What BoardSim does not claim</h2>
          <p>
            Scores are arithmetic over an editable, published policy — not a measurement of
            professional capability. The cases are fictional, the weights are authored judgement,
            and the regulatory panel reports applicability rather than legal advice. A legal
            deadline starts only when an authored fact establishes a trigger; unresolved scope is
            reported as pending rather than assumed either way.
          </p>
        </div>
      </section>
    </>
  );
}
