define('custom:views/prtg/circuit-charts', ['views/base'], function (Dep) {
    return Dep.extend({
        template: false,
        className: 'prtg-charts-panel',

        setup() {
            Dep.prototype.setup.call(this);
        },

        afterRender() {
            this.renderCharts();
        },

        renderCharts() {
            this.$el.html('<div class="text-muted small">Carregando gráficos do PRTG...</div>');

            const url = `PrtgIntegration/Charts/${this.model.entityType}/${this.model.id}`;

            Espo.Ajax.getRequest(url)
                .then((data) => {
                    if (!data || !data.success) {
                        const msg = data && data.message ? this.getHelper().escapeString(data.message) : 'Falha ao carregar gráficos.';
                        this.$el.html('<div class="text-danger">' + msg + '</div>');
                        return;
                    }

                    const charts = data.charts || {};
                    const html = `
                        <div class="row">
                            <div class="col-sm-12 col-md-12">
                                <div class="panel panel-default">
                                    <div class="panel-heading"><strong>Gráfico (2 horas)</strong></div>
                                    <div class="panel-body text-center">
                                        <img src="${charts.h2}" alt="PRTG 2h" style="max-width:100%; border:1px solid #e5e7eb; border-radius:4px;" />
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-12">
                                <div class="panel panel-default">
                                    <div class="panel-heading"><strong>Gráfico (2 dias)</strong></div>
                                    <div class="panel-body text-center">
                                        <img src="${charts.d2}" alt="PRTG 2d" style="max-width:100%; border:1px solid #e5e7eb; border-radius:4px;" />
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-12">
                                <div class="panel panel-default">
                                    <div class="panel-heading"><strong>Gráfico (30 dias)</strong></div>
                                    <div class="panel-body text-center">
                                        <img src="${charts.d30}" alt="PRTG 30d" style="max-width:100%; border:1px solid #e5e7eb; border-radius:4px;" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    this.$el.html(html);
                })
                .catch((xhr) => {
                    const reason =
                        (xhr && xhr.getResponseHeader && xhr.getResponseHeader('X-Status-Reason')) ||
                        (xhr && xhr.responseText) ||
                        (xhr && xhr.status ? `HTTP ${xhr.status}` : '') ||
                        'Erro ao carregar gráficos.';
                    this.$el.html('<div class="text-danger">' + this.getHelper().escapeString(reason) + '</div>');
                });
        }
    });
});
