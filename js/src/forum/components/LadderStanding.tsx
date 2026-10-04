import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Badge from 'flarum/common/components/Badge';
import Link from 'flarum/common/components/Link';
import { unit, Standing } from '../../common/api';

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
    const { current, next, score, metric } = standing;
    const own = app.session.user === user;

    let percent = 100;

    if (next) {
      const from = Math.min(current ? current.min : 0, score);
      percent = Math.max(0, Math.min(100, Math.round(((score - from) / Math.max(1, next.min - from)) * 100)));
    }

    const remaining = next ? next.min - score : 0;
    const name = user.displayName();

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

        <div className="LadderStanding-score">{t(unit('score', metric), { count: score })}</div>

        {next ? (
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
              {t(unit(own ? 'next_own' : 'next', metric), { count: remaining, rank: <strong>{next.name}</strong>, name })}
            </div>
          </>
        ) : (
          current && <div className="LadderStanding-next">{t(own ? 'top_own' : 'top', { name })}</div>
        )}
      </div>
    );
  }
}
