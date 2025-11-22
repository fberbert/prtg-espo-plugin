define('custom:views/prtg/detail', ['views/record/detail'], function (Dep) {
    return Dep.extend({
        setup() {
            Dep.prototype.setup.call(this);

            this.buttonList = this.buttonList || [];
            this.buttonList.push({
                name: 'sync-prtg',
                label: this.translate('syncFromPrtg', 'labels', 'Prtg'),
                style: 'default',
                onClick: () => this.actionSync()
            });

            this.dropdownItemList.push({
                name: 'sync-prtg',
                text: this.translate('syncFromPrtg', 'labels', 'Prtg'),
                onClick: () => this.actionSync()
            });
        },

        actionSyncPrtg() {
            this.actionSync();
        },

        actionSync() {
            if (!this.model.id) {
                return;
            }

            const url = `PrtgIntegration/Sync/${this.model.id}`;
            Espo.Ui.notifyWait();

            Espo.Ajax.getRequest(url)
                .then((data) => {
                    Espo.Ui.notify(false);
                    const msg = data && data.message ? data.message : this.translate('synced', 'messages', 'Prtg');
                    if (data && data.success) {
                        Espo.Ui.success(msg);
                        this.model.fetch().then(() => this.render());
                    } else {
                        Espo.Ui.error(msg);
                    }
                })
                .catch((xhr) => {
                    Espo.Ui.notify(false);
                    const reason =
                        (xhr && xhr.getResponseHeader && xhr.getResponseHeader('X-Status-Reason')) ||
                        (xhr && xhr.responseText) ||
                        (xhr && xhr.status ? `HTTP ${xhr.status}` : '') ||
                        'Erro ao sincronizar.';
                    Espo.Ui.error(this.translate('syncFailed', 'messages', 'Prtg', { message: reason }), true);
                });
        }
    });
});
