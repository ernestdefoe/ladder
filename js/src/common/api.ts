import app from 'flarum/common/app';

/** What the thresholds count: posts, Leaderboard's points, or FoF Gamification's ('votes'). */
export type Metric = 'posts' | 'points' | 'votes';

/**
 * A translation key in the ladder's unit: `next` stays `next` for posts and
 * becomes `next_points` for either kind of points. Members are told "points"
 * either way; which extension supplies them only matters to the admin.
 */
export function unit(key: string, metric: Metric | undefined): string {
  return metric && metric !== 'posts' ? `${key}_points` : key;
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
  return rung.maxPosts === null
    ? app.translator.trans(`${prefix}.${unit('range_open', metric)}`, { min: rung.minPosts })
    : app.translator.trans(`${prefix}.${unit('range', metric)}`, { min: rung.minPosts, max: rung.maxPosts });
}
