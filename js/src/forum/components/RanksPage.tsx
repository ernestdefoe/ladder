import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Badge from 'flarum/common/components/Badge';
import extractText from 'flarum/common/utils/extractText';
import classList from 'flarum/common/utils/classList';
import { rangeLabel, LadderData, RungData } from '../../common/api';
import LadderBanner from '../../common/components/LadderBanner';
import { loadLadder } from '../ladderStore';

const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.forum.ranks.${name}`, params);

/**
 * /ranks: every rung, what it takes, and where the reader stands.
 *
 * Generated from the ladder itself, so it can't go stale the way a
 * hand-written "here are our ranks" post does the first time a threshold
 * changes.
 */
export default class RanksPage extends Page {
  ladder: LadderData | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);

    app.history.push('ladder.ranks', extractText(t('title')));

    loadLadder().then((ladder) => {
      this.ladder = ladder;
      m.redraw();
    });
  }

  oncreate(vnode: any) {
    super.oncreate(vnode);

    app.setTitle(extractText(t('title')));
    app.setTitleCount(0);
  }

  view() {
    return (
      <PageStructure className="LadderRanksPage" hero={() => this.hero()} sidebar={() => <IndexSidebar />}>
        <div className="LadderRanks">
          <header className="LadderRanks-header">
            <h2 className="LadderRanks-title">{t('title')}</h2>
            <p className="LadderRanks-intro">{t('intro')}</p>
          </header>

          {this.ladder === null ? <LoadingIndicator /> : this.body(this.ladder)}
        </div>
      </PageStructure>
    );
  }

  hero() {
    if (!this.ladder?.rungs.length) return null;

    return (
      <div className="LadderRanksPage-hero container">
        <LadderBanner ladder={this.ladder} currentGroupId={this.ladder.viewer?.groupId} />
      </div>
    );
  }

  body(ladder: LadderData) {
    if (!ladder.rungs.length) {
      return <p className="LadderRanks-empty">{t('empty')}</p>;
    }

    return [
      this.standing(ladder),
      <ol className="LadderRanks-list">{ladder.rungs.map((rung) => this.rung(rung, ladder.viewer?.groupId === rung.groupId))}</ol>,
    ];
  }

  /** The reader's own place on the ladder, and how far it is to the next rung. */
  standing(ladder: LadderData) {
    const viewer = ladder.viewer;

    if (!viewer) {
      return <div className="LadderRanks-standing LadderRanks-standing--guest">{t('guest')}</div>;
    }

    const rungs = ladder.rungs;
    const index = rungs.findIndex((rung) => rung.groupId === viewer.groupId);
    const current = index >= 0 ? rungs[index] : null;

    // The next rung is the first one above both the rank held and the posts
    // made. With demotion off a member can hold a rank above their count.
    const floor = current ? current.minPosts : -1;
    const next = rungs.find((rung) => rung.minPosts > floor && rung.minPosts > viewer.posts) ?? null;

    let percent = 100;

    if (next) {
      // A member can hold a rank above their own count (demotion off, a
      // threshold raised), so measure from whichever is lower.
      const from = Math.min(current ? current.minPosts : 0, viewer.posts);
      const span = Math.max(1, next.minPosts - from);
      percent = Math.max(0, Math.min(100, Math.round(((viewer.posts - from) / span) * 100)));
    }

    const remaining = next ? next.minPosts - viewer.posts : 0;

    return (
      <div className="LadderRanks-standing">
        <div className="LadderRanks-standingText">
          {current && <p>{t('your_rank', { rank: <strong>{current.name}</strong>, count: viewer.posts })}</p>}
          <p>
            {next
              ? current
                ? t('next', { count: remaining, rank: <strong>{next.name}</strong> })
                : t('unranked', { count: remaining, rank: <strong>{next.name}</strong> })
              : t('top')}
          </p>
        </div>
        {next && (
          <div
            className="LadderRanks-meter"
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            style={next.color ? { '--ladder-next': next.color } : undefined}
          >
            <div className="LadderRanks-meterFill" style={{ width: `${percent}%` }} />
          </div>
        )}
      </div>
    );
  }

  rung(rung: RungData, isMine: boolean) {
    return (
      <li className={classList('LadderRanks-rung', isMine && 'LadderRanks-rung--mine')} style={rung.color ? { '--ladder-color': rung.color } : undefined}>
        <span className="LadderRanks-badge">
          <Badge icon={rung.icon || 'fas fa-circle'} color={rung.color || undefined} />
        </span>
        <div className="LadderRanks-main">
          <div className="LadderRanks-nameLine">
            <span className="LadderRanks-name">{rung.name}</span>
            {isMine && <span className="LadderRanks-here">{t('you_are_here')}</span>}
          </div>
          {rung.description && <p className="LadderRanks-description">{rung.description}</p>}
        </div>
        <div className="LadderRanks-meta">
          <span className="LadderRanks-range">{rangeLabel('ernestdefoe-ladder.forum.ranks', rung)}</span>
          <span className="LadderRanks-members">{t('members', { count: rung.memberCount })}</span>
        </div>
      </li>
    );
  }
}
