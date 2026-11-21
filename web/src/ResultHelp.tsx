import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/** Accessible result help that works with hover, keyboard focus, click and touch. */
export default function ResultHelp({
  label,
  children,
  title,
  description,
}: {
  label: string;
  children: ReactNode;
  title: string;
  description: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const trigger = useRef<HTMLButtonElement | null>(null);
  const panelId = useId();

  const show = useCallback(() => {
    setRect(trigger.current?.getBoundingClientRect() ?? null);
    setOpen(true);
  }, []);

  const close = useCallback(() => {
    setOpen(false);
    setRect(null);
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onPointer = (event: MouseEvent) => {
      const target = event.target as Node;
      const panel = document.getElementById(panelId);

      if (trigger.current?.contains(target) === true || panel?.contains(target) === true) {
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
  }, [close, open, panelId]);

  const width = Math.min(384, Math.max(256, window.innerWidth - 16));
  const left = rect === null ? 8 : Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
  const below = rect === null ? 8 : rect.bottom + 8;
  const top = rect !== null && below + 240 > window.innerHeight
    ? Math.max(8, rect.top - 248)
    : below;

  return (
    <>
      <button
        ref={trigger}
        type="button"
        className="bs-result-help"
        aria-label={`${label}. Open explanation`}
        aria-expanded={open}
        aria-describedby={open ? panelId : undefined}
        onMouseEnter={show}
        onMouseLeave={close}
        onFocus={show}
        onBlur={close}
        onClick={() => (open ? close() : show())}
      >
        {children}
      </button>
      {open &&
        createPortal(
          <div
            id={panelId}
            role="tooltip"
            className="bs-popover bs-result-help__popover"
            style={{ left, top, width }}
          >
            <p className="bs-popover__title">{title}</p>
            <div>{description}</div>
          </div>,
          document.body,
        )}
    </>
  );
}