define('custom:views/prtg-config/detail', ['views/record/detail'], function (Dep) {
    /**
     * Detail view for PRTG config with "Test Connection" action.
     */
    return Dep.extend({
        setup() {
            Dep.prototype.setup.call(this);

            this.dropdownItemList.push({
                name: 'test-connection',
                text: this.translate('testConnection', 'labels', 'PrtgConfig'),
                onClick: () => this.actionTestConnection()
            });
        },

        actionTestConnection() {
            if (!this.model.id) {
                Espo.Ui.warning(
                    this.translate('saveRecordFirst', 'messages', 'PrtgConfig')
                );
                return;
            }

            const payload = {
                id: this.model.id,
                endpoint: this.model.get('endpoint'),
                username: this.model.get('username'),
                passhash: this.model.get('passhash'),
                verifyTls: this.model.get('verifyTls'),
                timeout: this.model.get('timeout')
            };

            Espo.Ui.notifyWait();

            Espo.Ajax.postRequest('PrtgIntegration/TestConnection', payload)
                .then((data) => {
                    Espo.Ui.notify(false);

                    if (data && data.success) {
                        Espo.Ui.success(this.translate('testSuccess', 'messages', 'PrtgConfig'));
                    } else {
                        const message = data && data.message ? data.message : 'Sem detalhes';
                        Espo.Ui.error(this.formatTestFailed(message));
                    }

                    this.model.fetch().then(() => this.render());
                })
                .catch((xhr) => {
                    Espo.Ui.notify(false);
                    const reason =
                        (xhr && xhr.getResponseHeader && xhr.getResponseHeader('X-Status-Reason')) ||
                        (xhr && xhr.responseText) ||
                        (xhr && xhr.status ? `HTTP ${xhr.status}` : '') ||
                        'Sem detalhes';
                    Espo.Ui.error(this.formatTestFailed(reason), true);
                });
        },

        formatTestFailed(message) {
            const template = this.translate('testFailed', 'messages', 'PrtgConfig');

            if (template && template.includes('{message}')) {
                return template.replace('{message}', message);
            }

            return `${template} ${message}`;
        }
    });
});
