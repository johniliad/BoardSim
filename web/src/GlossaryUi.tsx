import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { navigate } from './nav';
import { settingDetail, useGlossary } from './glossary';

interface GlossaryUi {
  /** Open the glossary, optionally focused on one term (SJ, DR-028, …). */
  openGlossary: (term?: string) => void;
}

const Ui = createContext<GlossaryUi>({ openGlossary: () => undefined });

export function useGlossaryUi(): GlossaryUi {
  return useContext(Ui);
}

/**
 * The glossary is presented twice from one body: a sideframe during a run (where
 * leaving the page would lose the participant's place) and a full page below the
 * sideframe breakpoint, where a drawer would cover the content it explains.
 */
export function GlossaryUiProvider({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState<string | null>(null);
  const wide = useMediaQuery('(min-width: 48rem)');

  const openGlossary = useCallback(
    (wanted?: string) => {
      if (wide) {
        setTerm(wanted ?? null);
        setOpen(true);

        return;
      }

      navigate(`/glossary${wanted === undefined ? '' : `?term=${encodeURIComponent(wanted)}`}`);
    },
    [wide],
  );

  return (
    <Ui.Provider value={{ openGlossary }}>
      {children}
      {wide && open && <GlossaryDrawer term={term} onClose={() => setOpen(false)} />}
    </Ui.Provider>
  );
}

function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => window.matchMedia(query).matches);

  useEffect(() => {
    const list = window.matchMedia(query);
    const onChange = (event: MediaQueryListEvent) => setMatches(event.matches);

    setMatches(list.matches);
    list.addEventListener('change', onChange);

    return () => list.removeEventListener('change', onChange);
  }, [query]);

  return matches;
}

function GlossaryDrawer({ term, onClose }: { term: string | null; onClose: () => void }) {
  const closeRef = useRef<HTMLButtonElement | null>(null);

  useEffect(() => {
    closeRef.current?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', onKey);

    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div
      className="bs-drawer-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onClose();
        }
      }}
    >
      <aside className="bs-drawer" role="dialog" aria-label="Glossary">
        <div className="bs-drawer__head">
          <p className="bs-eyebrow bs-m-0">
            Glossary
          </p>
          <button
            ref={closeRef}
            type="button"
            className="bs-button bs-button--secondary"
            onClick={onClose}
          >
            Close
          </button>
        </div>
        <GlossaryPanel term={term} />
      </aside>
    </div>
  );
}

export function GlossaryPage({ term }: { term: string | null }) {
  return (
    <section className="bs-section">
      <div className="bs-container">
        <p className="bs-eyebrow">Glossary</p>
        <h1>What the codes mean</h1>
        <p className="bs-lead">
          Every identifier the interface uses, with the wording the scoring policy, the regulatory
          registry and the difficulty overlay already carry. Nothing here is written twice: this page
          is a rendering of those artefacts.
        </p>
        <GlossaryPanel term={term} />
      </div>
    </section>
  );
}

function GlossaryPanel({ term = null }: { term?: string | null }) {
  const { glossary, error } = useGlossary();
  const [query, setQuery] = useState('');
  const wanted = query.trim().toLowerCase();

  useEffect(() => {
    if (term === null || term === '') {
      return;
    }

    document.getElementById(`term-${term}`)?.scrollIntoView({ block: 'center' });
  }, [term, glossary]);

  if (error !== null) {
    return (
      <p className="bs-notice bs-notice--critical" role="alert">
        The glossary could not be loaded: {error}
      </p>
    );
  }

  if (glossary === null) {
    return (
      <p className="bs-lead" role="status">
        Loading the glossary…
      </p>
    );
  }

  const matches = (...values: Array<string | null | undefined>) =>
    wanted === '' || values.join(' ').toLowerCase().includes(wanted);

  const dimensions = glossary.dimensions.filter((entry) =>
    matches(entry.code, entry.name, entry.definition),
  );
  const rules = glossary.rules.filter((entry) => matches(entry.id, entry.title, entry.rationale));
  const instruments = glossary.instruments.filter((entry) =>
    matches(entry.id, entry.title, entry.nature),
  );
  const settings = glossary.settings.filter((entry) => matches(entry.key, entry.label));
  const statuses = glossary.statuses.filter((entry) => matches(entry.code, entry.label));

  const nothing =
    dimensions.length + rules.length + instruments.length + settings.length + statuses.length === 0;

  return (
    <>
      <label className="bs-field bs-field-narrow">
        <span>Filter</span>
        <input
          className="bs-input"
          type="search"
          value={query}
          placeholder="SJ, DR-028, NIS2, leadership…"
          onChange={(event) => setQuery(event.target.value)}
        />
      </label>

      <p className="bs-kpi__meta">
        {glossary.glossary_version} ·{' '}
        <span className="bs-mono">{glossary.glossary_sha256.slice(0, 12)}</span>
      </p>

      {nothing && <p className="bs-notice">No entry matches “{query}”.</p>}

      {dimensions.length > 0 && (
        <section className="bs-glossary__group">
          <h2>Dimensions</h2>
          {dimensions.map((entry) => (
            <Entry
              key={entry.code}
              id={entry.code}
              target={term === entry.code}
              title={
                <>
                  {entry.name} <span className="bs-mono">({entry.code})</span>
                </>
              }
              meta={`${entry.weight}% of the score · ${entry.rules.length} rules move it`}
              body={entry.definition}
            />
          ))}
        </section>
      )}

      {rules.length > 0 && (
        <section className="bs-glossary__group">
          <h2>Decision rules</h2>
          {rules.map((entry) => (
            <Entry
              key={entry.id}
              id={entry.id}
              target={term === entry.id}
              title={
                <>
                  <span className="bs-mono">{entry.id}</span> — {entry.title}
                </>
              }
              meta={`${entry.board_rule ? 'Board-pack rule' : 'Stage rule'} · ${entry.nist_function ?? '—'} · moves ${entry.dimensions.join(', ')}`}
              body={entry.rationale}
            />
          ))}
        </section>
      )}

      {settings.length > 0 && (
        <section className="bs-glossary__group">
          <h2>Run settings and score bands</h2>
          {settings.map((entry) => (
            <Entry
              key={entry.key}
              id={entry.key}
              target={term === entry.key}
              title={entry.label}
              meta={settingDetail(entry)}
              body={entry.group === 'difficulty' ? entry.description : undefined}
            />
          ))}
        </section>
      )}

      {instruments.length > 0 && (
        <section className="bs-glossary__group">
          <h2>Regulatory instruments</h2>
          {instruments.map((entry) => (
            <Entry
              key={entry.id}
              id={entry.id}
              target={term === entry.id}
              title={entry.title}
              meta={`${entry.id} · ${entry.nature.replaceAll('_', ' ').toLowerCase()}${
                entry.reporting_duty ? ' · reporting duty' : ''
              }`}
              link={entry.source_url}
            />
          ))}
        </section>
      )}

      {statuses.length > 0 && (
        <section className="bs-glossary__group">
          <h2>Regulatory statuses</h2>
          {statuses.map((entry) => (
            <Entry
              key={entry.code}
              id={entry.code}
              target={term === entry.code}
              title={entry.label}
              meta={entry.code}
              body={
                entry.meaning ??
                'Applicability is derived from authored facts only. The regulatory panel states what each status means for this run.'
              }
            />
          ))}
        </section>
      )}
    </>
  );
}

function Entry({
  id,
  target,
  title,
  meta,
  body,
  link,
}: {
  id: string;
  target: boolean;
  title: ReactNode;
  meta: string;
  body?: string;
  link?: string;
}) {
  return (
    <article
      id={`term-${id}`}
      className={`bs-glossary__entry${target ? ' bs-glossary__entry--target' : ''}`}
    >
      <p className="bs-glossary__title">{title}</p>
      <p className="bs-glossary__meta">{meta}</p>
      {body !== undefined && body !== '' && <p>{body}</p>}
      {link !== undefined && link !== '' && (
        <a className="bs-popover__link" href={link} target="_blank" rel="noreferrer noopener">
          Official source ↗
        </a>
      )}
    </article>
  );
}
