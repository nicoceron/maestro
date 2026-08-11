"use client";

import { useEffect, useId, useRef, useState } from "react";
import {
  AlertCircle,
  Check,
  Eye,
  EyeOff,
  LoaderCircle,
} from "lucide-react";

export function useFocusFirstInvalid<FieldName extends string>(
  errors: Partial<Record<FieldName, string>>,
  fieldOrder: readonly FieldName[],
) {
  const formRef = useRef<HTMLFormElement>(null);

  useEffect(() => {
    const firstInvalidField = fieldOrder.find((field) => Boolean(errors[field]));
    if (!firstInvalidField) return;

    formRef.current
      ?.querySelector<HTMLElement>(`[name="${firstInvalidField}"]`)
      ?.focus();
  }, [errors, fieldOrder]);

  return formRef;
}

export function AuthTextField({
  label,
  name,
  type = "text",
  autoComplete,
  defaultValue,
  placeholder,
  error,
  hint,
  disabled,
  required = true,
  inputMode,
  onChange,
}: {
  label: string;
  name: string;
  type?: "text" | "email";
  autoComplete?: string;
  defaultValue?: string;
  placeholder?: string;
  error?: string;
  hint?: string;
  disabled?: boolean;
  required?: boolean;
  inputMode?: "email" | "text";
  onChange?: React.ChangeEventHandler<HTMLInputElement>;
}) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;

  return (
    <div>
      <label htmlFor={id} className="mb-2 block text-sm font-semibold text-[#352e3d]">
        {label}
      </label>
      <input
        id={id}
        name={name}
        type={type}
        autoComplete={autoComplete}
        defaultValue={defaultValue}
        placeholder={placeholder}
        disabled={disabled}
        required={required}
        inputMode={inputMode}
        aria-invalid={error ? "true" : undefined}
        aria-describedby={[hintId, errorId].filter(Boolean).join(" ") || undefined}
        onChange={onChange}
        className={`h-12 w-full rounded-xl border bg-[#fbfaf8] px-3.5 text-[15px] text-[#292330] shadow-[inset_0_1px_0_rgba(255,255,255,.8)] transition placeholder:text-[#a7a0aa] disabled:cursor-not-allowed disabled:bg-[#f1efec] disabled:text-[#78717c] ${
          error
            ? "border-[#c96b61] focus:border-[#b65e55]"
            : "border-[#dcd7df] hover:border-[#c9c1ce] focus:border-[#8a70dc]"
        } focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10`}
      />
      {hint ? (
        <p id={hintId} className="mt-1.5 text-xs leading-5 text-[#817a85]">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="mt-1.5 flex gap-1.5 text-xs leading-5 text-[#a54e46]">
          <AlertCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {error}
        </p>
      ) : null}
    </div>
  );
}

export function PasswordField({
  label = "Password",
  name = "password",
  autoComplete,
  error,
  onValueChange,
  autoFocus,
}: {
  label?: string;
  name?: string;
  autoComplete: "current-password" | "new-password";
  error?: string;
  onValueChange?: (value: string) => void;
  autoFocus?: boolean;
}) {
  const id = useId();
  const [visible, setVisible] = useState(false);
  const [capsLock, setCapsLock] = useState(false);
  const errorId = error ? `${id}-error` : undefined;
  const capsId = capsLock ? `${id}-caps` : undefined;

  return (
    <div>
      <div className="mb-2 flex items-center justify-between gap-4">
        <label htmlFor={id} className="text-sm font-semibold text-[#352e3d]">
          {label}
        </label>
        {capsLock ? (
          <span id={capsId} className="text-xs font-medium text-[#9d5c3c]">
            Caps Lock is on
          </span>
        ) : null}
      </div>
      <div className="relative">
        <input
          id={id}
          name={name}
          type={visible ? "text" : "password"}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          required
          minLength={12}
          aria-invalid={error ? "true" : undefined}
          aria-describedby={[errorId, capsId].filter(Boolean).join(" ") || undefined}
          onChange={(event) => onValueChange?.(event.currentTarget.value)}
          onKeyDown={(event) => setCapsLock(event.getModifierState("CapsLock"))}
          onKeyUp={(event) => setCapsLock(event.getModifierState("CapsLock"))}
          onBlur={() => setCapsLock(false)}
          className={`h-12 w-full rounded-xl border bg-[#fbfaf8] px-3.5 pr-12 text-[15px] text-[#292330] transition ${
            error
              ? "border-[#c96b61] focus:border-[#b65e55]"
              : "border-[#dcd7df] hover:border-[#c9c1ce] focus:border-[#8a70dc]"
          } focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10`}
        />
        <button
          type="button"
          onClick={() => setVisible((current) => !current)}
          aria-label={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
          aria-pressed={visible}
          className="absolute inset-y-1 right-1 grid w-10 place-items-center rounded-lg text-[#7c7482] transition-colors hover:bg-[#efebf4] hover:text-[#4e405f]"
        >
          {visible ? (
            <EyeOff className="size-4.5" aria-hidden="true" />
          ) : (
            <Eye className="size-4.5" aria-hidden="true" />
          )}
        </button>
      </div>
      {error ? (
        <p id={errorId} className="mt-1.5 flex gap-1.5 text-xs leading-5 text-[#a54e46]">
          <AlertCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {error}
        </p>
      ) : null}
    </div>
  );
}

export function PasswordRequirements({ value }: { value: string }) {
  const minimumMet = value.length >= 12;

  return (
    <div className="rounded-xl bg-[#f6f3fa] px-3.5 py-3 text-xs text-[#6f6676]">
      <p className="font-semibold text-[#5b5166]">Password policy</p>
      <ul className="mt-2" aria-live="polite">
        <li
          className={`flex items-center gap-1.5 ${minimumMet ? "text-[#37775e]" : "text-[#807886]"}`}
        >
          <span
            className={`grid size-4 place-items-center rounded-full ${minimumMet ? "bg-[#dff1e8]" : "border border-[#cbc5cf]"}`}
            aria-hidden="true"
          >
            {minimumMet ? <Check className="size-2.5" /> : null}
          </span>
          At least 12 characters <span className="font-semibold">Required</span>
        </li>
      </ul>
      <p className="mt-3 font-semibold text-[#5b5166]">Strength tips · optional</p>
      <ul className="mt-1.5 list-disc space-y-1 pl-4 leading-5">
        <li>Use a unique passphrase you do not use anywhere else.</li>
        <li>Longer is stronger; uppercase, numbers, and symbols are optional.</li>
      </ul>
      <p className="mt-2 leading-5 text-[#756c7c]">
        Maestro checks submitted passwords against known compromises.
      </p>
    </div>
  );
}

export function FormAlert({
  tone = "error",
  children,
}: {
  tone?: "error" | "success" | "info";
  children: React.ReactNode;
}) {
  const styles = {
    error: "border-[#eccbc7] bg-[#fff4f2] text-[#8e413b]",
    success: "border-[#c9e4d5] bg-[#f1faf5] text-[#2f6f56]",
    info: "border-[#dcd3f4] bg-[#f5f1ff] text-[#5d479b]",
  };

  return (
    <div
      role={tone === "error" ? "alert" : "status"}
      className={`flex gap-2.5 rounded-xl border px-3.5 py-3 text-sm leading-5 ${styles[tone]}`}
    >
      {tone === "success" ? (
        <Check className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      ) : (
        <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      )}
      <div>{children}</div>
    </div>
  );
}

export function AuthSubmitButton({
  pending,
  idleLabel,
  pendingLabel,
}: {
  pending: boolean;
  idleLabel: string;
  pendingLabel: string;
}) {
  return (
    <button
      type="submit"
      disabled={pending}
      className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white shadow-[0_9px_24px_rgba(106,76,195,.24)] transition hover:bg-[#684bc6] disabled:cursor-wait disabled:bg-[#a99bd4]"
    >
      {pending ? (
        <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
      ) : null}
      {pending ? pendingLabel : idleLabel}
    </button>
  );
}
