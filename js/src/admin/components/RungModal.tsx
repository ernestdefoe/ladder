import app from 'flarum/admin/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Badge from 'flarum/common/components/Badge';
import ColorPreviewInput from 'flarum/common/components/ColorPreviewInput';
import Group from 'flarum/common/models/Group';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import { ladderApi, LadderData, RungData } from '../../common/api';

const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.admin.modal.${name}`, params);

interface RungModalAttrs extends IFormModalAttrs {
  rung?: RungData;
  ladder: LadderData | null;
  onsaved: (ladder: LadderData) => void;
}

/**
 * Add or edit one rung: the rank's name, look and threshold in one place.
 *
 * The name, icon and colour are the GROUP's, written straight onto it, so the
 * badge members wear and the rung the admin edits can never drift apart.
 */
export default class RungModal extends FormModal<RungModalAttrs> {
  source!: Stream<'new' | 'existing'>;
  groupId!: Stream<string>;
  name!: Stream<string>;
  namePlural!: Stream<string>;
  minPosts!: Stream<string>;
  icon!: Stream<string>;
  color!: Stream<string>;
  description!: Stream<string>;

  oninit(vnode: any) {
    super.oninit(vnode);

    const rung = this.attrs.rung;

    this.source = Stream<'new' | 'existing'>('new');
    this.groupId = Stream('');
    this.name = Stream(rung?.name ?? '');
    this.namePlural = Stream(rung && rung.namePlural !== rung.name ? rung.namePlural : '');
    this.minPosts = Stream(rung ? String(rung.minPosts) : this.suggestedThreshold());
    this.icon = Stream(rung?.icon ?? '');
    this.color = Stream(rung?.color ?? '');
    this.description = Stream(rung?.description ?? '');
  }

  className() {
    return 'Modal--small LadderRungModal';
  }

  title() {
    return this.attrs.rung ? t('edit_title') : t('create_title');
  }

  /** The first rung starts at 0; later ones start one step past the top. */
  suggestedThreshold(): string {
    const rungs = this.attrs.ladder?.rungs ?? [];

    if (!rungs.length) return '0';

    const top = rungs[rungs.length - 1].minPosts;

    return String(top === 0 ? 10 : top * 2);
  }

  availableGroups(): Group[] {
    const taken = (this.attrs.ladder?.rungs ?? []).map((rung) => String(rung.groupId));
    const reserved = [Group.ADMINISTRATOR_ID, Group.GUEST_ID, Group.MEMBER_ID, '4'];

    return (app.store.all('groups') as Group[]).filter((group) => !reserved.includes(group.id()!) && !taken.includes(group.id()!));
  }

  pickExisting(id: string) {
    this.groupId(id);

    const group = app.store.getById<Group>('groups', id);

    if (group) {
      this.name(group.nameSingular() || '');
      this.namePlural(group.namePlural() !== group.nameSingular() ? group.namePlural() || '' : '');
      this.icon(group.icon() || '');
      this.color(group.color() || '');
    }
  }

  content() {
    const creating = !this.attrs.rung;
    const existing = creating && this.source() === 'existing';
    const groups = creating ? this.availableGroups() : [];

    return (
      <div className="Modal-body">
        <div className="Form">
          {creating && groups.length > 0 && (
            <div className="Form-group">
              <label>{t('source_label')}</label>
              <label className="checkbox">
                <input type="radio" name="source" checked={this.source() === 'new'} onchange={() => this.source('new')} />
                {t('source_new')}
              </label>
              <label className="checkbox">
                <input type="radio" name="source" checked={existing} onchange={() => this.source('existing')} />
                {t('source_existing')}
              </label>
            </div>
          )}

          {existing && (
            <div className="Form-group">
              <label for="ladder-group">{t('existing_label')}</label>
              <select id="ladder-group" name="groupId" className="FormControl" value={this.groupId()} onchange={(e: Event) => this.pickExisting((e.target as HTMLSelectElement).value)}>
                <option value="" disabled>
                  {extractText(t('existing_placeholder'))}
                </option>
                {groups.map((group) => (
                  <option value={group.id()}>{group.nameSingular()}</option>
                ))}
              </select>
              <div className="helpText">{t('existing_help')}</div>
            </div>
          )}

          <div className="Form-group LadderRungModal-preview">
            <label>{t('preview_label')}</label>
            <div className="LadderRungModal-previewBody">
              <Badge icon={this.icon() || 'fas fa-circle'} color={this.color() || undefined} />
              <strong>{this.name() || '…'}</strong>
            </div>
          </div>

          <div className="LadderRungModal-row">
            <div className="Form-group">
              <label for="ladder-name">{t('name_label')}</label>
              <input id="ladder-name" name="name" className="FormControl" bidi={this.name} maxlength={100} required />
            </div>
            <div className="Form-group LadderRungModal-threshold">
              <label for="ladder-min">{t('min_posts_label')}</label>
              <input id="ladder-min" name="minPosts" className="FormControl" type="number" min="0" step="1" bidi={this.minPosts} required />
            </div>
          </div>
          <div className="helpText LadderRungModal-help">{t('min_posts_help')}</div>

          <div className="Form-group">
            <label for="ladder-plural">{t('plural_label')}</label>
            <input id="ladder-plural" name="namePlural" className="FormControl" bidi={this.namePlural} placeholder={this.name()} maxlength={100} />
            <div className="helpText">{t('plural_help')}</div>
          </div>

          <div className="LadderRungModal-row">
            <div className="Form-group">
              <label for="ladder-icon">{t('icon_label')}</label>
              <input id="ladder-icon" name="icon" className="FormControl" bidi={this.icon} placeholder="fas fa-tv" />
            </div>
            <div className="Form-group">
              <label>{t('color_label')}</label>
              <ColorPreviewInput name="color" placeholder="#7c3aed" bidi={this.color} />
            </div>
          </div>
          <div className="helpText LadderRungModal-help">{t('icon_help')}</div>

          <div className="Form-group">
            <label for="ladder-description">{t('description_label')}</label>
            <textarea id="ladder-description" name="description" className="FormControl" rows={2} maxlength={500} bidi={this.description} />
            <div className="helpText">{t('description_help')}</div>
          </div>

          <div className="Form-group Form-controls">
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={existing && !this.groupId()}>
              {t('save')}
            </Button>
            {this.attrs.rung && (
              <Button type="button" className="Button Button--danger LadderRungModal-delete" icon="fas fa-trash-alt" onclick={() => this.remove()}>
                {t('delete')}
              </Button>
            )}
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();

    const rung = this.attrs.rung;
    const body: Record<string, any> = {
      name: this.name(),
      namePlural: this.namePlural(),
      minPosts: this.minPosts(),
      icon: this.icon(),
      color: this.color(),
      description: this.description(),
    };

    if (!rung && this.source() === 'existing') body.groupId = this.groupId();

    this.loading = true;

    ladderApi(rung ? 'PATCH' : 'POST', rung ? `/rungs/${rung.id}` : '/rungs', body, { errorHandler: this.onerror.bind(this) })
      .then((ladder) => {
        this.hide();
        this.attrs.onsaved(ladder);
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  remove() {
    const rung = this.attrs.rung!;

    if (!confirm(extractText(rung.ownsGroup ? t('delete_confirm_owned') : t('delete_confirm_existing')))) return;

    this.loading = true;

    ladderApi('DELETE', `/rungs/${rung.id}`, undefined, { errorHandler: this.onerror.bind(this) })
      .then((ladder) => {
        this.hide();
        this.attrs.onsaved(ladder);
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
