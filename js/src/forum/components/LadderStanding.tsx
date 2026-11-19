import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Badge from 'flarum/common/components/Badge';
import Link from 'flarum/common/components/Link';
import { money, progressPercent, unit, Standing } from '../../common/api';

const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.forum.profile.${name}`, params);

/**
 * A member's rank on their profile, and how far they are from the next one.
 *
 * Worded for whoever is looking: "you" on your own profile, the member's name
 * on anyone else's.
 */
export default class LadderStanding extends Component<{ standing: Standing; user: any }> {
  view() {
    const { standing, user } = this.attrs;
    const { current, score, metric } = standing;
    const own = app.session.user === user;

    return (
      <div className="LadderStanding" style={current?.color ? { '--ladder-color': current.color } : undefined}>
        <div className="LadderStanding-heading">
          <Link href={app.route('ladder.ranks')}>{t('heading')}</Link>
        </div>

        <div className="LadderStanding-rank">
          {current ? (
            <>
              <Badge icon={current.icon || 'fas fa-circle'} color={current.color || undefined} />
              <span className="LadderStanding-name">{current.name}</span>
            </>
          ) : (
            <span className="LadderStanding-name LadderStanding-name--none">{t('unranked')}</span>
          )}
        </div>

        <div className="LadderStanding-score">{t(unit('score', metric), { count: score, amount: money(score) })}</div>

        {this.next(own)}
      </div>
    );
  }

  /** The meter and the score still to go, or the top of the ladder. */
  next(own: boolean) {
    const { standing, user } = this.attrs;
    const { current, next, score, metric } = standing;
    const name = user.displayName();

    if (!next) return current && <div className="LadderStanding-next">{t(own ? 'top_own' : 'top', { name })}</div>;

    const percent = progressPercent(score, current?.min ?? null, next.min);
    const remaining = next.min - score;

    return (
      <>
        <div
          className="LadderStanding-meter"
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          style={next.color ? { '--ladder-next': next.color } : undefined}
        >
          <div className="LadderStanding-meterFill" style={{ width: `${percent}%` }} />
        </div>
        <div className="LadderStanding-next">
          {t(unit(own ? 'next_own' : 'next', metric), { count: remaining, amount: money(remaining), rank: <strong>{next.name}</strong>, name })}
        </div>
      </>
    );
  }
}
