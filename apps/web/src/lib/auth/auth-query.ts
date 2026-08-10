export type AuthQuery = {
  email?: string;
  returnTo?: "/account/security";
};

type SearchParams = Record<string, string | string[] | undefined>;

function first(value: string | string[] | undefined) {
  return Array.isArray(value) ? value[0] : value;
}

function safeValue(value: string | undefined, maxLength: number) {
  const normalized = value?.trim();
  if (!normalized || normalized.length > maxLength) return undefined;
  return normalized;
}

export function readAuthQuery(searchParams: SearchParams): AuthQuery {
  const emailCandidate = safeValue(first(searchParams.email), 254);
  const email =
    emailCandidate && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailCandidate)
      ? emailCandidate.toLowerCase()
      : undefined;
  const returnToCandidate = safeValue(first(searchParams.returnTo), 128);
  const returnTo =
    returnToCandidate === "/account/security" ? returnToCandidate : undefined;

  return {
    email,
    ...(returnTo ? { returnTo } : {}),
  };
}

export function authHref(
  path: string,
  query: Pick<AuthQuery, "email" | "returnTo">,
) {
  const search = new URLSearchParams();
  if (query.email) search.set("email", query.email);
  if (query.returnTo) search.set("returnTo", query.returnTo);
  const suffix = search.toString();
  return suffix ? `${path}?${suffix}` : path;
}
