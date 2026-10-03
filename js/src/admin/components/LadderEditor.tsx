import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Badge from 'flarum/common/components/Badge';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import { ladderApi, rangeLabel, LadderData, RungData, SyncResult } from '../../common/api';
import RungModal from './RungModal';
import LadderBanner from '../../common/components/LadderBanner';
import state from '../state';

const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.admin.${name}`, params);

type Progress = { done: number; total: number; changed: number; status: 'running' | 'finished' | 'failed' };

/**
 * The ladder, bottom rung first, as members climb it.
 *
 * There is nothing to drag into order here, and that is on purpose: a rung's
 * place on the ladder IS its post threshold, so the list sorts itself and can
 * never disagree with the rule it describes.
 *
 * Every change re-ranks the forum straight away. A ladder that has been edited
 * but not applied is a page that says one thing while members' badges say
 * another, and the admin has no way to tell which is which.
 */
export default class LadderEditor extends Component {
  ladder: LadderData | null = null;
  progress: Progress | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    return ladderApi('GET').then((ladder) => this.set(ladder));
  }

  set(ladder: LadderData) {
    this.ladder = ladder;
    state.ladder = ladder;
    m.redraw();
  }

  view() {
    return (
      <div className="Form-group LadderEditor">
        <label>{t('ladder.heading')}</label>
        <div className="helpText">{t('ladder.help')}</div>

        {this.ladder === null ? <LoadingIndicator /> : this.rungList()}

        <div className="LadderEditor-actions">
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.open()}>
            {t('ladder.add')}
          </Button>
          <Button
            className="Button"
            icon="fas fa-arrows-rotate"
            disabled={!this.ladder?.rungs.length || this.progress?.status === 'running'}
            onclick={() => this.rerank()}
          >
            {t('rerank.button')}
          </Button>
        </div>

        {this.progressView()}

        {!!this.ladder?.rungs.length && (
          <div className="LadderEditor-preview">
            <label>{t('ladder.preview')}</label>
            <div className="helpText">{t('ladder.preview_help')}</div>
            <LadderBanner ladder={this.ladder} />
          </div>
        )}
      </div>
    );
  }

  rungList() {
    const rungs = this.ladder!.rungs;

    if (!rungs.length) {
      return <p className="LadderEditor-empty">{t('ladder.empty')}</p>;
    }

    // Highest rank on top, the way a ladder is drawn.
    return (
      <ol className="LadderEditor-rungs">
        {[...rungs].reverse().map((rung) => this.rungRow(rung))}
      </ol>
    );
  }

  rungRow(rung: RungData) {
    return (
      <li className="LadderEditor-rung" key={rung.id}>
        <button type="button" className="LadderEditor-rungButton" onclick={() => this.open(rung)}>
          <span className="LadderEditor-badge">
            <Badge icon={rung.icon || 'fas fa-circle'} color={rung.color || undefined} />
          </span>
          <span className="LadderEditor-name">{rung.name}</span>
          <span className="LadderEditor-range">{rangeLabel('ernestdefoe-ladder.admin.ladder', rung)}</span>
          <span className="LadderEditor-members">{t('ladder.members', { count: rung.memberCount })}</span>
          {rung.isHidden && (
            <span className="LadderEditor-flag" title={t('ladder.hidden_group')}>
              <Icon name="fas fa-eye-slash" />
            </span>
          )}
          {!rung.ownsGroup && <span className="LadderEditor-tag">{t('ladder.existing_group')}</span>}
          {rung.imageUrl && (
            <span className="LadderEditor-flag" title={t('ladder.has_image')}>
              <Icon name="fas fa-image" />
            </span>
          )}
          <span className="LadderEditor-edit">
            <Icon name="fas fa-pen" />
          </span>
        </button>
      </li>
    );
  }

  progressView() {
    const progress = this.progress;

    if (!progress) return null;

    const percent = progress.total ? Math.min(100, Math.round((progress.done / progress.total) * 100)) : 100;

    return (
      <div className={`LadderEditor-progress LadderEditor-progress--${progress.status}`} role="status">
        {progress.status === 'running' && (
          <div className="LadderEditor-bar">
            <div className="LadderEditor-barFill" style={{ width: `${percent}%` }} />
          </div>
        )}
        <span>
          {progress.status === 'running' && t('rerank.running', { done: progress.done, total: progress.total })}
          {progress.status === 'finished' && t('rerank.finished', { changed: progress.changed })}
          {progress.status === 'failed' && t('rerank.failed')}
        </span>
      </div>
    );
  }

  open(rung?: RungData) {
    app.modal.show(RungModal, {
      rung,
      ladder: this.ladder,
      onsaved: (ladder: LadderData) => {
        this.set(ladder);
        // Rung groups were created, renamed or deleted: refresh the store so
        // the Permissions page and the badge previews agree with the ladder.
        app.store.find('groups').finally(() => m.redraw());
        this.rerank();
      },
    });
  }

  async rerank() {
    if (this.progress?.status === 'running') return;

    this.progress = { done: 0, total: 0, changed: 0, status: 'running' };
    m.redraw();

    let after = 0;

    try {
      for (;;) {
        const result = await ladderApi<SyncResult>('POST', '/sync', { after });

        if (result.total !== undefined) this.progress.total = result.total;

        this.progress.done = Math.min(this.progress.total || Infinity, this.progress.done + result.processed);
        this.progress.changed += result.changed;
        after = result.lastId;
        m.redraw();

        if (result.done) break;
      }

      this.progress.status = 'finished';
    } catch (e) {
      this.progress.status = 'failed';
    }

    // Member counts on each rung have moved.
    await this.load();
  }
}
