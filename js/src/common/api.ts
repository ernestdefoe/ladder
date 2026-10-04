import app from 'flarum/common/app';

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
  viewer: { posts: number; groupId: number | null; exempt?: boolean } | null;
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

/** "10–24 posts", or "1600+ posts" for the top rung. */
export function rangeLabel(prefix: string, rung: RungData): any {
  return rung.maxPosts === null
    ? app.translator.trans(`${prefix}.range_open`, { min: rung.minPosts })
    : app.translator.trans(`${prefix}.range`, { min: rung.minPosts, max: rung.maxPosts });
}
