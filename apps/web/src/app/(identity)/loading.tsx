export default function IdentityLoading() {
  return (
    <div className="w-full max-w-md" role="status" aria-label="Loading account page">
      <div className="mb-7 space-y-3">
        <div className="h-3 w-28 animate-pulse rounded bg-[#ddd5e6]" />
        <div className="h-10 w-4/5 animate-pulse rounded-xl bg-[#e2dde6]" />
        <div className="h-4 w-full animate-pulse rounded bg-[#e8e4ea]" />
      </div>
      <div className="space-y-5 rounded-[1.6rem] border border-black/[0.06] bg-white p-6 shadow-[0_22px_70px_rgba(52,43,63,.10)]">
        <div className="h-12 animate-pulse rounded-xl bg-[#f0edf2]" />
        <div className="h-12 animate-pulse rounded-xl bg-[#f0edf2]" />
        <div className="h-12 animate-pulse rounded-xl bg-[#dcd3ef]" />
      </div>
      <span className="sr-only">Loading…</span>
    </div>
  );
}
