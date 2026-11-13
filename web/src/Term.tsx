import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import type { Glossary } from './api';
import { findTerm, settingDetail, useGlossary, type TermHit } from './glossary';
import { useGlossaryUi } from './GlossaryUi';

/**
 * An inline glossary term.
 *
 * Deliberately a <button>, not <abbr title>: a title tooltip is invisible on
 * touch, silent to screen readers and cannot hold the official source link that
 * a REG- instrument needs. This opens on click, tap, Enter or Space, closes on
 * Escape or an outside click, and always offers a route into the full glossary.
 *
 * The popover is rendered in a portal and positioned from the trigger's rect,
 * because these terms live inside tables whose wrapper scrolls horizontally — an
 * absolutely positioned popover would be clipped by it.
 */
export default function Term({
  k,
  children,
  className = '',
}: {
  k: string;
  children?: ReactNode;
  className?: string;
}) {
  const { glossary } = useGlossary();
  const { openGlossary } = useGlossaryUi();
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const trigger = useRef<HTMLButtonElement | null>(null);
  const panelId = useId();

  const hit = glossary === null ? null : findTerm(glossary, k);
  const label = typeof children === 'string' ? children : k;

  const close = useCallback(() => {
    setOpen(false);
    setRect(null);
  }, []);

  const toggle = useCallback(() => {
    if (open) {
      close();
      trigger.current?.focus();

      return;
    }

    setRect(trigger.current?.getBoundingClientRect() ?? null);
    setOpen(true);
  }, [open, close]);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onPointer = (event: MouseEvent) => {
      const target = event.target as Node;
      const panel = document.getElementById(panelId);

      if (trigger.current?.contains(target) === true) {
        return;
      }

      if (panel !== null && panel.contains(target)) {
        return;
      }

      close();
    };

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        close();
        trigger.current?.focus();
      }
    };

    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);

    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
    };
  }, [open, panelId, close]);

  // Until the glossary has loaded there is nothing to explain, so the identifier
  // renders as plain text rather than as a dead button.
  if (hit === null || glossary === null) {
    return <span className={className}>{children ?? k}</span>;
  }

  const width = Math.min(352, Math.max(240, window.innerWidth - 16));
  const left = rect === null ? 8 : Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
  const below = rect === null ? 8 : rect.bottom + 8;
  const top = rect !== null && below + 300 > window.innerHeight
    ? Math.max(8, rect.top - 308)
    : below;

  return (
    <>
      <button
        ref={trigger}
        type="button"
        className={`bs-term ${className}`.trim()}
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        aria-label={`${label} — what this means`}
        onClick={toggle}
      >
        {children ?? k}
      </button>

      {open &&
        createPortal(
          <div
            className="bs-popover"
            id={panelId}
            role="dialog"
            aria-label={`${label} explained`}
            style={{ top, left, width }}
          >
            <PopoverBody
              hit={hit}
              glossary={glossary}
              onOpenGlossary={() => {
                setOpen(false);
                setRect(null);
                openGlossary(k);
              }}
            />
          </div>,
          document.body,
        )}
    </>
  );
}

function PopoverBody({
  hit,
  glossary,
  onOpenGlossary,
}: {
  hit: TermHit;
  glossary: Glossary;
  onOpenGlossary: () => void;
}) {
  const more = (
    <button type="button" className="bs-popover__link" onClick={onOpenGlossary}>
      Open in the glossary
    </button>
  );

  switch (hit.kind) {
    case 'dimension':
      return (
        <>
          <p className="bs-popover__title">
            {hit.entry.name} <span className="bs-mono">({hit.entry.code})</span>
          </p>
          <p className="bs-popover__meta">
            {hit.entry.weight}% of the score · moved by {hit.entry.rules.length} rules
          </p>
          <p>{hit.entry.definition}</p>
          {more}
        </>
      );

    case 'rule':
      return (
        <>
          <p className="bs-popover__title">
            <span className="bs-mono">{hit.entry.id}</span> — {hit.entry.title}
          </p>
          <p className="bs-popover__meta">
            {hit.entry.board_rule ? 'Board-pack rule' : 'Stage rule'} · moves{' '}
            {hit.entry.dimensions
              .map(
                (code) =>
                  `${code} (${glossary.dimensions.find((entry) => entry.code === code)?.name ?? code})`,
              )
              .join(', ')}
          </p>
          {hit.entry.nist_function != null && (
            <p className="bs-popover__meta">
              NIST CSF 2.0 · {hit.entry.nist_function} (
              {glossary.nist_functions[hit.entry.nist_function] ?? hit.entry.nist_function})
            </p>
          )}
          <p>{hit.entry.rationale}</p>
          {more}
        </>
      );

    case 'instrument':
      return (
        <>
          <p className="bs-popover__title">{hit.entry.title}</p>
          <p className="bs-popover__meta">
            <span className="bs-mono">{hit.entry.id}</span> ·{' '}
            {hit.entry.nature.replaceAll('_', ' ').toLowerCase()}
            {hit.entry.reporting_duty ? ' · carries a reporting duty' : ''}
          </p>
          <p className="bs-popover__meta">{hit.entry.verification_tier.replaceAll('_', ' ').toLowerCase()}</p>
          {hit.entry.source_url !== '' && (
            <a
              className="bs-popover__link"
              href={hit.entry.source_url}
              target="_blank"
              rel="noreferrer noopener"
            >
              Official source ↗
            </a>
          )}
          {more}
        </>
      );

    case 'setting':
      return (
        <>
          <p className="bs-popover__title">{hit.entry.label}</p>
          <p className="bs-popover__meta">{settingDetail(hit.entry)}</p>
          {hit.entry.description !== undefined && hit.entry.description !== '' && (
            <p>{hit.entry.description}</p>
          )}
          {hit.entry.unavailable_reason != null && <p>{hit.entry.unavailable_reason}</p>}
          {more}
        </>
      );

    default:
      return (
        <>
          <p className="bs-popover__title">{hit.entry.label}</p>
          <p className="bs-popover__meta">
            Regulatory status · <span className="bs-mono">{hit.entry.code}</span>
          </p>
          <p>
            {hit.entry.meaning ??
              'Applicability is derived from authored facts only. The regulatory panel states what each status means for this run.'}
          </p>
          {more}
        </>
      );
  }
}
