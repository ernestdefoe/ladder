import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import stringToColor from 'flarum/common/utils/stringToColor';
import { ladderApi, unit, Metric, RungData } from '../../common/api';

const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.forum.members.${name}`, params);

type Member = { id: number; displayName: string; avatarUrl: string | null; url: string; score: number };
type MembersPage = { members: Member[]; total: number; nextOffset: number | null };

interface RankMembersModalAttrs extends IInternalModalAttrs {
  rung: RungData;
  metric: Metric;
}

/** Who holds one rank, highest score first, thirty at a time. */
export default class RankMembersModal extends Modal<RankMembersModalAttrs> {
  members: Member[] = [];
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
              <li className="LadderMembers-item" key={member.id}>
                <Link href={member.url} className="LadderMembers-link" onclick={() => this.hide()}>
                  {member.avatarUrl ? (
                    <img className="Avatar LadderMembers-avatar" src={member.avatarUrl} alt="" loading="lazy" />
                  ) : (
                    <span className="Avatar LadderMembers-avatar" style={{ '--avatar-bg': '#' + stringToColor(member.displayName) }}>
                      {member.displayName.charAt(0).toUpperCase()}
                    </span>
                  )}
                  <span className="LadderMembers-name">{member.displayName}</span>
                  <span className="LadderMembers-score">
                    {app.translator.trans(`ernestdefoe-ladder.forum.members.${unit('score', metric)}`, { count: member.score })}
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

    ladderApi<MembersPage>('GET', `/rungs/${this.attrs.rung.id}/members`, undefined, { params: { offset: this.nextOffset } })
      .then((page) => {
        this.members.push(...page.members);
        this.total = page.total;
        this.nextOffset = page.nextOffset;
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
