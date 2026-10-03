import app from 'flarum/common/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import textContrastClass from 'flarum/common/helpers/textContrastClass';
import classList from 'flarum/common/utils/classList';
import type { LadderData, RungData } from '../api';

export interface LadderBannerAttrs extends ComponentAttrs {
  ladder: LadderData;
  /** Group id of the rung the reader holds, to mark their card. */
  currentGroupId?: number | null;
}

/**
 * The whole ladder as one picture: a card per rank, in its colour, with its
 * artwork (or its icon, when it has none) and the posts it takes.
 *
 * Drawn from the ladder every time rather than exported as an image, so it is
 * never out of date, stays sharp at any size, and reads in any language.
 */
export default class LadderBanner extends Component<LadderBannerAttrs> {
  view() {
    const { ladder, currentGroupId } = this.attrs;
    const title = ladder.banner.title || app.forum.attribute<string>('title');

    return (
      <div className="LadderBanner">
        <div className="LadderBanner-head">
          <span className="LadderBanner-rule" aria-hidden="true" />
          <div className="LadderBanner-titles">
            <h2 className="LadderBanner-title">{title}</h2>
            {ladder.banner.tagline && <p className="LadderBanner-tagline">{ladder.banner.tagline}</p>}
          </div>
          <span className="LadderBanner-rule" aria-hidden="true" />
        </div>

        <ol className="LadderBanner-cards" style={{ '--ladder-count': ladder.rungs.length }}>
          {ladder.rungs.map((rung) => this.card(rung, rung.groupId === currentGroupId))}
        </ol>
      </div>
    );
  }

  card(rung: RungData, isMine: boolean) {
    const color = rung.color || 'var(--primary-color)';
    const range =
      rung.maxPosts === null
        ? app.translator.trans('ernestdefoe-ladder.lib.banner.range_open', { min: rung.minPosts })
        : app.translator.trans('ernestdefoe-ladder.lib.banner.range', { min: rung.minPosts, max: rung.maxPosts });

    return (
      <li className={classList('LadderBanner-card', isMine && 'LadderBanner-card--mine')} style={{ '--ladder-color': color }}>
        <div
          className={classList('LadderBanner-name', rung.color && textContrastClass(rung.color))}
          // Long names shrink to fit their card instead of being cut short:
          // about ten capitals fit at full size, so a 13-letter rank is
          // drawn at ten-thirteenths of it.
          style={{ '--ladder-fit': Math.min(1, 10 / Math.max(1, rung.name.length)).toFixed(3) }}
        >
          {rung.name}
        </div>
        <div className={classList('LadderBanner-art', !rung.imageUrl && 'LadderBanner-art--icon')}>
          {rung.imageUrl ? (
            <img src={rung.imageUrl} alt="" loading="lazy" />
          ) : (
            <Icon name={rung.icon || 'fas fa-star'} className="LadderBanner-icon" />
          )}
        </div>
        <div className="LadderBanner-range">{range}</div>
      </li>
    );
  }
}
