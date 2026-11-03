import app from 'flarum/common/app';

/** What the thresholds count: posts, Leaderboard's points, FoF Gamification's ('votes'), or Money's balance. */
export type Metric = 'posts' | 'points' | 'votes' | 'money';

/**
 * A translation key in the ladder's unit: `next` stays `next` for posts and
 * becomes `next_points` for either kind of points. Members are told "points"
 * either way; which extension supplies them only matters to the admin. Money
 * has its own keys, which name the sum with `{amount}`.
 */
export function unit(key: string, metric: Metric | undefined): string {
  if (!metric || metric === 'posts') return key;

  return metric === 'money' ? `${key}_money` : `${key}_points`;
}

/** A sum in the forum's own money name: "[money] coins" makes "250 coins". */
export function money(value: number | string): string {
  return (app.forum.attribute<string>('antoinefr-money.moneyname') || '[money]').replace('[money]', String(value));
}

export type RungData = {
  id: number;
  groupId: number;
  name: string;
  namePlural: string;
  icon: string | null;
  color: string | null;
  isHidden: boolean;
  minPosts: number;
  maxPosts: number | null;
  description: string | null;
  ownsGroup: boolean;
  memberCount: number;
  imageUrl: string | null;
};

export type LadderData = {
  rungs: RungData[];
  demotes: boolean;
  metric: Metric;
  leaderboardAvailable: boolean;
  gamificationAvailable: boolean;
  moneyAvailable: boolean;
  canListMembers: boolean;
  viewer: { score: number; groupId: number | null; exempt?: boolean } | null;
  banner: { title: string; tagline: string };
  savedId: number | null;
};

export type SyncResult = {
  processed: number;
  changed: number;
  lastId: number;
  done: boolean;
  total?: number;
};

export function ladderApi<T = LadderData>(method: string, path: string = '', body?: any, options: Record<string, any> = {}): Promise<T> {
  return app.request<T>({
    method,
    url: `${app.forum.attribute('apiUrl')}/ladder${path}`,
    body,
    ...options,
  });
}

/** "10–24 posts", or "1600+ points" for the top rung. */
export function rangeLabel(prefix: string, rung: RungData, metric?: Metric): any {
  if (metric === 'money') return money(rung.maxPosts === null ? `${rung.minPosts}+` : `${rung.minPosts}–${rung.maxPosts}`);

  return rung.maxPosts === null
    ? app.translator.trans(`${prefix}.${unit('range_open', metric)}`, { min: rung.minPosts })
    : app.translator.trans(`${prefix}.${unit('range', metric)}`, { min: rung.minPosts, max: rung.maxPosts });
}

/** A member's place on the ladder, as their profile shows it. */
export type Standing = {
  metric: Metric;
  score: number;
  current: { groupId: number; name: string; icon: string | null; color: string | null; min: number } | null;
  next: { groupId: number; name: string; icon: string | null; color: string | null; min: number } | null;
};
