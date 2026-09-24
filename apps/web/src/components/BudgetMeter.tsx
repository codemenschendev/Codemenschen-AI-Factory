import { Icon } from "@/components/LineIcon";

/** The ad-spend meter: the SpendGuard promise in one picture. */
export function BudgetMeter({
  of,
  stop,
  className,
}: {
  of: string;
  stop: string;
  className?: string;
}) {
  return (
    <div className={className} aria-hidden="true">
      <p className="budget-head">
        <Icon name="ads" className="meta-ico" /> Meta Ads
      </p>
      <div className="meter">
        <span style={{ width: "38%" }} />
      </div>
      <p className="budget-fig">{of}</p>
      <p className="budget-note">{stop}</p>
    </div>
  );
}
