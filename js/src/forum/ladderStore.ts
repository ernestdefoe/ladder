import { ladderApi, LadderData } from '../common/api';

let cached: LadderData | null = null;
let pending: Promise<LadderData> | null = null;

/**
 * The ladder, fetched once per page load and shared by everything on the
 * forum that draws it, so the home-page banner and the Ranks page never cost
 * more than one request between them.
 */
export function loadLadder(): Promise<LadderData> {
  if (cached) return Promise.resolve(cached);

  pending ??= ladderApi('GET').then((ladder) => {
    cached = ladder;
    pending = null;
    m.redraw();

    return ladder;
  });

  return pending;
}

export function ladder(): LadderData | null {
  return cached;
}
