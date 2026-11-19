import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import type User from 'flarum/common/models/User';
import { money, unit, Metric, RungData } from '../../common/api';

const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.forum.members.${name}`, params);

const PER_PAGE = 30;

interface RankMembersModalAttrs extends IInternalModalAttrs {
  rung: RungData;
  metric: Metric;
}

/**
 * Who holds one rank, highest score first, thirty at a time: core's member
 * list, narrowed by the `ladderRung` filter, so its permission and visibility
 * rules apply.
 */
export default class RankMembersModal extends Modal<RankMembersModalAttrs> {
  members: User[] = [];
  total = 0;
  nextOffset: number | null = 0;
  loading = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.more();
  }

  className() {
    return 'LadderMembersModal Modal--small';
  }

  title() {
    return this.attrs.rung.name;
  }

  content() {
    const metric = this.attrs.metric;

    return (
      <div className="Modal-body">
        {this.members.length > 0 && (
          <ol className="LadderMembers">
            {this.members.map((member) => (
              <li className="LadderMembers-item" key={member.id()}>
                <Link href={app.route.user(member)} className="LadderMembers-link" onclick={() => this.hide()}>
                  <Avatar user={member} className="LadderMembers-avatar" loading="lazy" />
                  <span className="LadderMembers-name">{member.displayName()}</span>
                  <span className="LadderMembers-score">
                    {app.translator.trans(`ernestdefoe-ladder.forum.members.${unit('score', metric)}`, {
                      count: member.attribute<number>('ladderScore') ?? 0,
                      amount: money(member.attribute<number>('ladderScore') ?? 0),
                    })}
                  </span>
                </Link>
              </li>
            ))}
          </ol>
        )}

        {!this.loading && this.members.length === 0 && <p className="LadderMembers-empty">{t('empty')}</p>}

        {this.loading ? (
          <LoadingIndicator />
        ) : (
          this.nextOffset !== null && (
            <Button className="Button Button--block" onclick={() => this.more()}>
              {t('more', { shown: this.members.length, total: this.total })}
            </Button>
          )
        )}
      </div>
    );
  }

  more() {
    if (this.nextOffset === null || this.loading) return;

    this.loading = true;
    m.redraw();

    const offset = this.nextOffset;

    app.store
      .find<User[]>('users', { filter: { ladderRung: String(this.attrs.rung.id) }, sort: '-ladderScore', page: { offset, limit: PER_PAGE } })
      .then((users) => {
        this.members.push(...users);
        this.total = users.payload?.meta?.page?.total ?? this.members.length;
        this.nextOffset = users.payload?.links?.next ? offset + PER_PAGE : null;
      })
      .catch(() => {
        this.nextOffset = null;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
