(function () {
    const dashboardData = window.CrmDashboardData || {};
    const chartInstances = new Map();
    const sourcePalette = ['#064aa8', '#087747', '#765000', '#76edac', '#c0182f'];

    function initShell() {
        document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
            document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
        });
    }

    function getElement(id) {
        return document.getElementById(id);
    }

    function showEmpty(container, message) {
        if (!container) {
            return;
        }

        container.innerHTML = '';
        const node = document.createElement('div');
        node.className = 'crm-chart-empty';
        node.textContent = message;
        container.appendChild(node);
    }

    function normalizeSeries(series, fallbackLabel) {
        const rows = Array.isArray(series) ? series : [];

        if (!rows.length) {
            return [{ label: fallbackLabel || 'Chua co', value: 0 }];
        }

        return rows.map((row) => ({
            label: String(row.hour || row.agent || row.label || ''),
            value: Number(row.value || 0),
        }));
    }

    function createCanvas(container) {
        container.innerHTML = '';
        const canvas = document.createElement('canvas');
        canvas.setAttribute('role', 'img');
        container.appendChild(canvas);
        return canvas;
    }

    function destroyChart(id) {
        const instance = chartInstances.get(id);

        if (instance) {
            instance.destroy();
            chartInstances.delete(id);
        }
    }

    function renderChart(id, config) {
        const container = getElement(id);

        if (!container || typeof window.Chart === 'undefined') {
            showEmpty(container, 'Không Thể Tải');
            return;
        }

        destroyChart(id);
        const canvas = createCanvas(container);
        const chart = new window.Chart(canvas, config);
        chartInstances.set(id, chart);
    }

    function baseOptions(extraOptions) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 280,
            },
            interaction: {
                intersect: false,
                mode: 'index',
            },
            plugins: {
                legend: {
                    display: false,
                    labels: {
                        color: '#262b45',
                        boxWidth: 18,
                    },
                },
                tooltip: {
                    backgroundColor: '#070a18',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    padding: 10,
                    displayColors: false,
                },
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        color: '#6e7288',
                        font: {
                            family: 'Inter, system-ui, sans-serif',
                            size: 12,
                        },
                    },
                    border: {
                        display: false,
                    },
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#dedff0',
                    },
                    ticks: {
                        color: '#6e7288',
                        precision: 0,
                        font: {
                            family: 'Inter, system-ui, sans-serif',
                            size: 12,
                        },
                    },
                    border: {
                        display: false,
                    },
                },
            },
            ...extraOptions,
        };
    }

    function renderLineChart(id, series, options) {
        const rows = normalizeSeries(series, '08:00');
        const color = options?.color || '#0f56b3';
        const fill = options?.fill !== false;

        renderChart(id, {
            type: 'line',
            data: {
                labels: rows.map((row) => row.label),
                datasets: [{
                    label: options?.label || 'Hội Thoại',
                    data: rows.map((row) => row.value),
                    borderColor: color,
                    backgroundColor: fill ? 'rgba(15, 86, 179, .14)' : 'rgba(15, 86, 179, .04)',
                    fill,
                    tension: .38,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#fbfaff',
                    pointBorderColor: color,
                    pointBorderWidth: 2,
                    borderWidth: 2,
                }],
            },
            options: baseOptions({
                plugins: {
                    ...baseOptions().plugins,
                    legend: {
                        display: Boolean(options?.legend),
                        position: 'top',
                        labels: {
                            color: '#262b45',
                            boxWidth: 36,
                            font: {
                                family: 'Inter, system-ui, sans-serif',
                                size: 12,
                            },
                        },
                    },
                },
            }),
        });
    }

    function renderBarChart(id, series) {
        const rows = normalizeSeries(series, 'Chưa Có');

        renderChart(id, {
            type: 'bar',
            data: {
                labels: rows.map((row) => row.label),
                datasets: [{
                    label: 'Hoi thoai',
                    data: rows.map((row) => row.value),
                    backgroundColor: '#c0182f',
                    borderRadius: 4,
                    maxBarThickness: 48,
                }],
            },
            options: baseOptions({
                plugins: {
                    ...baseOptions().plugins,
                    tooltip: {
                        ...baseOptions().plugins.tooltip,
                        displayColors: false,
                    },
                },
            }),
        });
    }

    function renderDonutChart(id, series) {
        const rows = Array.isArray(series) ? series.filter((row) => Number(row.value || 0) > 0) : [];

        if (!rows.length) {
            showEmpty(getElement(id), 'Chưa Có Dữ Liệu');
            return;
        }

        renderChart(id, {
            type: 'doughnut',
            data: {
                labels: rows.map((row) => String(row.label || 'Khác')),
                datasets: [{
                    data: rows.map((row) => Number(row.value || 0)),
                    backgroundColor: rows.map((_, index) => sourcePalette[index % sourcePalette.length]),
                    borderColor: '#fbfaff',
                    borderWidth: 2,
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '46%',
                animation: {
                    duration: 280,
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        backgroundColor: '#070a18',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        padding: 10,
                    },
                },
            },
        });
    }

    function drawCharts() {
        renderBarChart('agent-handled-bar', dashboardData.agentBar || []);
        renderLineChart('agent-response-line', dashboardData.agentLine || [], {
            color: '#0052cc',
            fill: false,
            label: 'Phút',
        });

        if (dashboardData.activeSection === 'dashboard' || dashboardData.activeSection === 'reports') {
            renderLineChart('today-conversation-line', dashboardData.todayLine || [], {
                color: '#0f56b3',
                fill: true,
                legend: true,
                label: 'Số Hội Thoại',
            });
            renderDonutChart('today-source-donut', dashboardData.todaySources || []);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initShell();
        drawCharts();
    });
})();
