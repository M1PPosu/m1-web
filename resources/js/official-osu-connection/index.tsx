// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import BigButton from 'components/big-button';
import OfficialOsuConnectionJson from 'interfaces/official-osu-connection-json';
import { route } from 'laroute';
import { action, makeObservable, observable } from 'mobx';
import { observer } from 'mobx-react';
import * as React from 'react';
import { onErrorWithCallback } from 'utils/ajax';
import { trans } from 'utils/lang';

interface Props {
  container: HTMLElement;
}

interface ImportResponse {
  message: string;
  status?: string;
}

@observer
export default class OfficialOsuConnection extends React.Component<Props> {
  @observable private confirmed = false;
  @observable private importXhr: JQuery.jqXHR | null = null;
  @observable private removeConfirmed = false;
  @observable private unlinkXhr: JQuery.jqXHR<ImportResponse> | null = null;
  @observable private message: string | null = null;
  private readonly canAuthenticate: boolean;
  @observable private user: OfficialOsuConnectionJson | null;

  constructor(props: Props) {
    super(props);

    this.canAuthenticate = JSON.parse(this.props.container.dataset.canAuthenticate ?? 'false') as boolean;
    this.user = JSON.parse(this.props.container.dataset.connection ?? '') as OfficialOsuConnectionJson | null;

    makeObservable(this);
  }

  componentWillUnmount() {
    this.importXhr?.abort();
    this.unlinkXhr?.abort();
  }

  render() {
    return (
      <div className='account-edit-entry account-edit-entry--official-osu'>
        {this.user == null ? this.renderConnect() : this.renderConnected()}
      </div>
    );
  }

  private renderConnect() {
    if (!this.canAuthenticate) {
      return (
        <div className='official-osu-connection'>
          <div className='account-edit-entry__rules'>
            {trans('accounts.official_osu.unavailable')}
          </div>
        </div>
      );
    }

    return (
      <div className='official-osu-connection'>
        <div className='official-osu-connection__heading'>
          {trans('accounts.official_osu.state.not_connected')}
        </div>
        <BigButton
          href={route('account.official-osu.create')}
          icon='fas fa-link'
          modifiers='account-edit account-edit-small'
          text={trans('accounts.official_osu.link')}
        />
        <div className='account-edit-entry__rules'>
          {trans('accounts.official_osu.info')}
        </div>
      </div>
    );
  }

  private renderConnected() {
    if (this.user == null) return null;

    return (
      <div className='official-osu-connection'>
        <div className='official-osu-connection__header'>
          <div className='official-osu-connection__heading'>
            {trans('accounts.official_osu.connected_heading')}
          </div>
          {this.renderStatusBadge()}
        </div>

        <div className='official-osu-connection__account'>
          {this.user.avatar_url != null && (
            <img alt='' className='avatar avatar--full-rounded official-osu-connection__avatar' src={this.user.avatar_url} />
          )}
          <div className='official-osu-connection__account-main'>
            <a className='official-osu-connection__username' href={this.user.official_url}>
              {this.user.username}
            </a>
            <div className='official-osu-connection__meta'>
              {trans('accounts.official_osu.official_user_id')}: {this.user.official_user_id}
            </div>
          </div>
        </div>

        {this.renderStatus()}
        {this.renderPreview()}

        {this.user.can_import && this.canAuthenticate && (
          <div className='official-osu-connection__actions'>
            <div className='official-osu-connection__confirm-box'>
              <div className='official-osu-connection__confirm-title'>
                {trans('accounts.official_osu.confirm.title')}
              </div>
              <ul className='official-osu-connection__list'>
                <li>{trans('accounts.official_osu.confirm.profile')}</li>
                <li>{trans('accounts.official_osu.confirm.no_pp')}</li>
                <li>{trans('accounts.official_osu.confirm.native_intact')}</li>
                <li>{trans('accounts.official_osu.confirm.locked')}</li>
              </ul>
              <label className='official-osu-connection__confirm'>
                <input
                  checked={this.confirmed}
                  onChange={this.onConfirmChange}
                  type='checkbox'
                />
                <span>{trans('accounts.official_osu.confirm.checkbox')}</span>
              </label>
            </div>

            <BigButton
              disabled={!this.confirmed}
              icon='fas fa-file-import'
              isBusy={this.importXhr != null}
              modifiers='account-edit account-edit-small'
              props={{ onClick: this.onImportButtonClick }}
              text={trans('accounts.official_osu.import')}
            />
          </div>
        )}

        {this.user.can_disconnect && (
          <div className='official-osu-connection__actions'>
            {this.user.is_imported && (
              <div className='official-osu-connection__confirm-box official-osu-connection__confirm-box--danger'>
                <div className='official-osu-connection__confirm-title'>
                  {trans('accounts.official_osu.remove.title')}
                </div>
                <ul className='official-osu-connection__list'>
                  <li>{trans('accounts.official_osu.remove.profile')}</li>
                  <li>{trans('accounts.official_osu.remove.native_intact')}</li>
                  <li>{trans('accounts.official_osu.remove.reimport_blocked')}</li>
                  <li>{trans('accounts.official_osu.remove.staff_help')}</li>
                </ul>
                <label className='official-osu-connection__confirm'>
                  <input
                    checked={this.removeConfirmed}
                    onChange={this.onRemoveConfirmChange}
                    type='checkbox'
                  />
                  <span>{trans('accounts.official_osu.remove.checkbox')}</span>
                </label>
              </div>
            )}
            <BigButton
              disabled={this.user.is_imported && !this.removeConfirmed}
              icon='fas fa-unlink'
              isBusy={this.unlinkXhr != null}
              modifiers='account-edit account-edit-small danger'
              props={{ onClick: this.onUnlinkButtonClick }}
              text={trans(this.user.is_imported ? 'accounts.official_osu.remove.button' : 'accounts.official_osu.unlink')}
            />
          </div>
        )}

        {this.message != null && (
          <div className='account-edit-entry__rules'>
            {this.message}
          </div>
        )}
      </div>
    );
  }

  private renderStatus() {
    if (this.user == null) return null;

    if (!this.canAuthenticate) {
      return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.unavailable')}</div>;
    }

    if (this.user.pending_review || this.user.review_status === 'pending') {
      return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.review_requested')}</div>;
    }

    if (this.user.review_status === 'applied') {
      return null;
    }

    if (this.user.review_status === 'self_removed') {
      return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.remove.self_removed')}</div>;
    }

    if (this.user.review_status === 'removed_by_staff') {
      return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.remove.removed_by_staff')}</div>;
    }

    if (this.user.review_status === 'denied') {
      return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.review_denied')}</div>;
    }

    if (this.user.review_status === 'failed') {
      return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.review_failed')}</div>;
    }

    return <div className='account-edit-entry__rules'>{trans('accounts.official_osu.import_prompt')}</div>;
  }

  private renderStatusBadge() {
    if (this.user == null) return null;

    return (
      <div className={`official-osu-connection__status official-osu-connection__status--${this.statusKey()}`}>
        {trans(`accounts.official_osu.state.${this.statusKey()}`)}
      </div>
    );
  }

  private renderPreview() {
    if (this.user == null) return null;
    if (!this.user.can_import || this.user.is_imported || this.user.is_removed) return null;

    return (
      <div className='official-osu-connection__preview'>
        <div>{trans('accounts.official_osu.preview.profile')}</div>
        <div>{trans('accounts.official_osu.preview.native_unchanged')}</div>
        {this.user.username_conflict && (
          <div>{trans('accounts.official_osu.preview.username_conflict')}</div>
        )}
        {this.user.token_unavailable && (
          <div>{trans('accounts.official_osu.preview.reconnect_required')}</div>
        )}
      </div>
    );
  }

  private statusKey() {
    if (this.user == null) return 'not_connected';
    if (this.user.pending_review || this.user.review_status === 'pending') return 'pending';
    if (this.user.review_status === 'applied') return 'imported';
    if (this.user.review_status === 'self_removed') return 'self_removed';
    if (this.user.review_status === 'removed_by_staff') return 'removed_by_staff';
    if (this.user.review_status === 'denied') return 'denied';
    if (this.user.review_status === 'failed') return 'failed';

    return 'ready';
  }

  @action
  private readonly onConfirmChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    this.confirmed = event.currentTarget.checked;
  };

  @action
  private readonly onRemoveConfirmChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    this.removeConfirmed = event.currentTarget.checked;
  };

  @action
  private readonly onImportButtonClick = () => {
    if (this.importXhr != null || !this.confirmed) return;

    this.importXhr = $.ajax(route('account.official-osu.import'), {
      data: { confirmed: 1 },
      method: 'POST',
    })
      .done(action((response: ImportResponse) => {
        this.message = response.message;
        if (this.user != null && response.status != null) {
          this.user.review_status = response.status;
          this.user.pending_review = response.status === 'pending';
          this.user.is_imported = response.status === 'applied';
          this.user.is_removed = false;
          this.user.can_import = response.status !== 'applied' && response.status !== 'pending';
          this.user.can_disconnect = response.status === 'applied' || response.status !== 'pending';
        }
      }))
      .fail(onErrorWithCallback(this.onImportButtonClick))
      .always(action(() => this.importXhr = null));
  };

  @action
  private readonly onUnlinkButtonClick = () => {
    if (this.unlinkXhr != null || this.user == null) return;
    if (this.user.is_imported && !this.removeConfirmed) return;

    this.unlinkXhr = $.ajax(
      route('account.official-osu.destroy'),
      {
        data: this.user.is_imported ? { confirmed: 1 } : {},
        method: 'DELETE',
      },
    )
      .done(action((response: ImportResponse | undefined) => {
        if (response?.status === 'self_removed') {
          this.message = response.message;
          if (this.user != null) {
            this.user.can_disconnect = false;
            this.user.can_import = false;
            this.user.is_imported = false;
            this.user.is_removed = true;
            this.user.pending_review = false;
            this.user.review_status = response.status;
          }
        } else {
          this.user = null;
        }
      }))
      .fail(onErrorWithCallback(this.onUnlinkButtonClick))
      .always(action(() => this.unlinkXhr = null));
  };
}
