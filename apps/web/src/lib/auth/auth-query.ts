export type AuthQuery = {
  email?: string;
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

  return {
    email,
  };
}

export function authHref(path: string, query: Pick<AuthQuery, "email">) {
  const search = new URLSearchParams();
  if (query.email) search.set("email", query.email);
  const suffix = search.toString();
  return suffix ? `${path}?${suffix}` : path;
}
