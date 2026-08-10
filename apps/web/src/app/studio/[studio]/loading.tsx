export default function StudioLoading() {
  return (
    <div
      aria-label="Loading studio overview"
      aria-busy="true"
      className="mx-auto w-full max-w-[1480px] animate-pulse px-4 pb-16 pt-7 sm:px-6 lg:px-8 xl:px-10"
    >
      <div className="h-3 w-36 rounded-full bg-[#ddd8e0]" />
      <div className="mt-5 h-12 w-[min(80%,430px)] rounded-2xl bg-[#ddd8e0]" />
      <div className="mt-4 h-4 w-[min(92%,620px)] rounded-full bg-[#e4dfe5]" />

      <div className="mt-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }, (_, index) => (
          <div key={index} className="h-32 rounded-[22px] border border-[#e3dee5] bg-white" />
        ))}
      </div>

      <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(330px,0.8fr)]">
        <div className="h-[620px] rounded-[24px] border border-[#e3dee5] bg-white" />
        <div className="grid gap-5">
          <div className="h-[360px] rounded-[24px] border border-[#e3dee5] bg-white" />
          <div className="h-[300px] rounded-[24px] border border-[#e3dee5] bg-white" />
        </div>
      </div>
    </div>
  );
}
