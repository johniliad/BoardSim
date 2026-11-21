import { navigate } from './nav';

/**
 * The "About this product" page linked from the top menu and the footer byline.
 *
 * It states what the application does, how a score is produced, what happens to
 * the data and where the honest limits of the product are — in plain language,
 * because the footer promises an explanation and the interface never
 * over-claims.
 */
export default function About() {
  return (
    <section className="bs-section">
      <div className="bs-container bs-prose bs-reading">
        <p className="bs-eyebrow">About</p>
        <h1>What this product does</h1>
        <p className="bs-lead">
          BoardSim puts a board-level executive in the chair of a fictional organisation during a
          security crisis, and then shows exactly how the decisions they made were scored.
        </p>

        <h2>The exercise</h2>
        <p>
          You pick one of fourteen scenarios — ransomware, a privileged-account compromise, a
          supplier failure, an AI incident and others — and choose a difficulty and a risk appetite.
          A run then unfolds in three timed stages. At each stage you see the situation as it is
          known <em>at that moment</em>, the positions of the people around the table, and three
          courses of action. Every option is a board-level authorisation with a named trade-off:
          isolate now and accept the disruption, keep revenue running and accept the exposure,
          escalate to specialists and lose time.
        </p>
        <p>
          After the third decision you must defend the outcome in a board pack of seven structured
          fields — situation, business impact, uncertainty, recommendation, investment, residual
          risk and the resolution you request. The chair asks a question, three stakeholders
          challenge you, and the pack you submit is scored.
        </p>

        <h2>How the score is produced</h2>
        <p>
          The score is <strong>deterministic arithmetic, computed on the server</strong>. Every
          option maps to an explicit list of decision rules; each rule moves named dimensions of
          judgement (strategic judgment, risk judgment, regulatory judgment and seven more) by
          fixed amounts. The risk-appetite setting adds small, published modifiers to four rules
          only. The weighted dimensions produce an integer score from 0 to 100. Nothing is random,
          nothing is inferred, and no language model is involved anywhere in the product. The exact
          formula, the current weights and the score bands are on the{' '}
          <a href="#/methodology">methodology page</a>.
        </p>
        <p>
          The run records SHA-256 identities for the scoring policy, regulatory bundle, scenario
          content and difficulty projection. Run operations resolve the matching retained release and
          fail explicitly if an exact required artifact is unavailable. The result page verifies the
          hashed event evidence, run state, board selections and reconstructed score.
        </p>

        <h2>Understanding the result</h2>
        <p>
          The result screen separates three things that are usually blurred together: the{' '}
          <strong>operational consequences</strong> of your choices (shown as a running ledger
          during the run), the <strong>regulatory applicability</strong> of the situation, and the{' '}
          <strong>score</strong>. "What would change it?" lets you substitute an alternative
          decision and see the exact numerical effect of the choice you did not make. Every
          identifier used anywhere in the interface — dimension codes, decision rules, legal
          instruments — is explained in the{' '}
          <a href="#/glossary">glossary</a>, which is generated from the same documents that score
          the run.
        </p>

        <h2>The regulatory panel</h2>
        <p>
          Each stage reports whether legal instruments such as NIS2, GDPR, DORA or the AI Act are
          applicable, triggered, pending classification or guidance only. That status is derived
          from authored facts inside the scenario — never from the option you selected — and
          unresolved scope is shown as <em>pending</em> rather than assumed either way. This panel
          is a teaching aid about how reporting duties arise; it is{' '}
          <strong>not a legal opinion</strong>, and every non-guidance instrument is marked as
          requiring human review before production use.
        </p>

        <h2>Your data, and your rights</h2>
        <ul>
          <li>
            There is <strong>no account and no login</strong>. Possessing a run identifier is the
            capability: whoever holds the identifier can view its current state and result, request
            its explanation and counterfactuals, or delete it.
          </li>
          <li>
            Completed runs are kept for <strong>seven days from finalisation</strong>; abandoned
            runs use their creation time. They are then physically deleted by a scheduled job.
          </li>
          <li>
            You can <strong>erase a run yourself</strong> at any time from the result page.
            Deletion is physical: the run, its three decisions, its board selections and its event
            log are removed, and the result can no longer be opened or checked because it no longer
            exists.
          </li>
          <li>
            Share links expose a read-only view for up to seven days, but expire sooner when the
            oldest selected run reaches its deletion deadline. Sharing never extends retention.
            The token is stored only as a hash. This browser manages one active link: creating a
            replacement revokes the old one, and “Forget everything” revokes the active link before
            clearing local data.
          </li>
        </ul>

        <h2>What BoardSim is not</h2>
        <p>
          The cases are fictional, the weights are authored judgement, and multiple choices can be
          defensible. The score is relative to the published policy,{' '}
          <strong>not a measure of professional competence</strong>, and the product has not been
          validated as a psychometric, hiring or regulatory-compliance instrument. It is a
          self-development aid for practising how decisions are framed, defended and accounted
          for.
        </p>

        <h2>Under the hood</h2>
        <p>
          The engine is PHP with MySQL; the interface is a small React application served from the
          same origin, so there is no cross-site traffic and no third-party script. The scoring
          specification is pinned and immutable, the content overlays are versioned, and the
          releases are reproducible from source. The product is open source under the Apache-2.0
          licence, and the data behind the interface is also available as a public, read-only
          JSON API.
        </p>

        <div className="bs-inline bs-mt-5">
          <button className="bs-button" type="button" onClick={() => navigate('/scenarios')}>
            Start a scenario
          </button>
          <button
            className="bs-button bs-button--secondary"
            type="button"
            onClick={() => navigate('/demo')}
          >
            Try the five-minute demo
          </button>
          <a className="bs-button bs-button--secondary" href="#/glossary">
            Open the glossary
          </a>
        </div>
      </div>
    </section>
  );
}
