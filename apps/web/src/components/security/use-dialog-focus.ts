"use client";

import { useEffect, useRef } from "react";

export function useDialogFocus(
  open: boolean,
  onClose: () => void,
  returnFocus?: HTMLElement | null,
) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const firstRef = useRef<HTMLInputElement | HTMLButtonElement>(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    if (!open) return;

    const initialFocus =
      firstRef.current ??
      dialogRef.current?.querySelector<HTMLElement>("input:not([disabled])") ??
      dialogRef.current?.querySelector<HTMLElement>(
        "input:not([disabled]), button:not([disabled]), [href]",
      );
    initialFocus?.focus();

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") return;

      const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [href]',
      );
      if (!focusable?.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      if (returnFocus) {
        window.setTimeout(() => {
          if (returnFocus.isConnected) returnFocus.focus();
        }, 0);
      }
    };
  }, [open, returnFocus]);

  return { dialogRef, firstRef };
}
