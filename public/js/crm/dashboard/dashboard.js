(function () {
    const data = window.CrmDashboardData || {};
    const palette = ['#0052cc', '#2684ff', '#36b37e', '#ffab00', '#ba1a1a'];

    function initShell() {
        document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
            document.querySelector('[data-crm-shell]')?.classList.toggle('sidebar-collapsed');
        });
    }

    function element(id) {
        return document.getElementById(id);
    }

    function empty(container, message) {
        if (!container) {
            return;
        }

        container.innerHTML = '';
        const node = document.createElement('div');
        node.className = 'crm-chart-empty';
        node.textContent = message;
        container.appendChild(node);
    }

    function svgNode(name, attributes) {
        const node = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.entries(attributes || {}).forEach(([key, value]) => node.setAttribute(key, value));
        return node;
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

    function drawLineChart(id, series, options) {
        const container = element(id);

        if (!container) {
            return;
        }

        const rows = normalizeSeries(series, '08:00');
        const max = Math.max(...rows.map((row) => row.value), 1);
        const width = 760;
        const height = 320;
        const pad = { top: 26, right: 24, bottom: 42, left: 44 };
        const innerWidth = width - pad.left - pad.right;
        const innerHeight = height - pad.top - pad.bottom;
        const step = rows.length > 1 ? innerWidth / (rows.length - 1) : innerWidth;
        const color = options?.color || '#0052cc';
        const points = rows.map((row, index) => {
            const x = pad.left + (rows.length > 1 ? index * step : innerWidth / 2);
            const y = pad.top + innerHeight - ((row.value / max) * innerHeight);
            return { ...row, x, y };
        });

        container.innerHTML = '';

        const svg = svgNode('svg', {
            class: 'crm-svg-chart',
            viewBox: `0 0 ${width} ${height}`,
            role: 'img',
            'aria-label': options?.label || 'Dashboard chart',
        });

        for (let i = 0; i <= 4; i += 1) {
            const y = pad.top + (innerHeight / 4) * i;
            svg.appendChild(svgNode('line', {
                x1: pad.left,
                y1: y,
                x2: width - pad.right,
                y2: y,
                stroke: '#e6e9f0',
                'stroke-width': '1',
            }));
        }

        points.forEach((point, index) => {
            const text = svgNode('text', {
                x: point.x,
                y: height - 14,
                'text-anchor': index === 0 ? 'start' : index === points.length - 1 ? 'end' : 'middle',
            });
            text.textContent = point.label;
            svg.appendChild(text);
        });

        const pathValue = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');

        if (options?.area !== false) {
            const areaPath = `${pathValue} L ${points[points.length - 1].x} ${pad.top + innerHeight} L ${points[0].x} ${pad.top + innerHeight} Z`;
            svg.appendChild(svgNode('path', {
                d: areaPath,
                fill: color,
                opacity: '.08',
            }));
        }

        svg.appendChild(svgNode('path', {
            d: pathValue,
            fill: 'none',
            stroke: color,
            'stroke-width': '3',
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
        }));

        points.forEach((point) => {
            svg.appendChild(svgNode('circle', {
                cx: point.x,
                cy: point.y,
                r: '5',
                fill: '#ffffff',
                stroke: color,
                'stroke-width': '3',
            }));

            if (point.value > 0) {
                const label = svgNode('text', {
                    x: point.x,
                    y: point.y - 12,
                    'text-anchor': 'middle',
                });
                label.textContent = point.value;
                svg.appendChild(label);
            }
        });

        container.appendChild(svg);
    }

    function drawBarChart(id, series) {
        const container = element(id);

        if (!container) {
            return;
        }

        const rows = normalizeSeries(series, 'Chua co');
        const max = Math.max(...rows.map((row) => row.value), 1);
        const width = 760;
        const height = 320;
        const pad = { top: 26, right: 24, bottom: 46, left: 36 };
        const innerWidth = width - pad.left - pad.right;
        const innerHeight = height - pad.top - pad.bottom;
        const barSpace = innerWidth / rows.length;
        const barWidth = Math.min(68, barSpace * .52);

        container.innerHTML = '';

        const svg = svgNode('svg', {
            class: 'crm-svg-chart',
            viewBox: `0 0 ${width} ${height}`,
            role: 'img',
            'aria-label': 'Hoi thoai xu ly theo nhan vien',
        });

        for (let i = 0; i <= 4; i += 1) {
            const y = pad.top + (innerHeight / 4) * i;
            svg.appendChild(svgNode('line', {
                x1: pad.left,
                y1: y,
                x2: width - pad.right,
                y2: y,
                stroke: '#e6e9f0',
                'stroke-width': '1',
            }));
        }

        rows.forEach((row, index) => {
            const x = pad.left + (barSpace * index) + ((barSpace - barWidth) / 2);
            const barHeight = (row.value / max) * innerHeight;
            const y = pad.top + innerHeight - barHeight;

            svg.appendChild(svgNode('rect', {
                x,
                y,
                width: barWidth,
                height: Math.max(2, barHeight),
                rx: '6',
                fill: '#ba1a1a',
            }));

            const value = svgNode('text', {
                x: x + barWidth / 2,
                y: y - 10,
                'text-anchor': 'middle',
            });
            value.textContent = row.value;
            svg.appendChild(value);

            const label = svgNode('text', {
                x: x + barWidth / 2,
                y: height - 16,
                'text-anchor': 'middle',
            });
            label.textContent = row.label;
            svg.appendChild(label);
        });

        container.appendChild(svg);
    }

    function drawDonutChart(id, series) {
        const container = element(id);
        const rows = Array.isArray(series) ? series.filter((row) => Number(row.value || 0) > 0) : [];

        if (!container) {
            return;
        }

        if (!rows.length) {
            empty(container, 'Chua co du lieu nguon hoi thoai');
            return;
        }

        const total = rows.reduce((sum, row) => sum + Number(row.value || 0), 0);
        const size = 320;
        const radius = 104;
        const circumference = 2 * Math.PI * radius;
        let offset = 0;

        container.innerHTML = '';

        const svg = svgNode('svg', {
            class: 'crm-svg-chart',
            viewBox: `0 0 ${size} ${size}`,
            role: 'img',
            'aria-label': 'Nguon khach hang',
        });

        svg.appendChild(svgNode('circle', {
            cx: size / 2,
            cy: size / 2,
            r: radius,
            fill: 'none',
            stroke: '#edf0f5',
            'stroke-width': '42',
        }));

        rows.forEach((row, index) => {
            const value = Number(row.value || 0);
            const length = (value / total) * circumference;
            const circle = svgNode('circle', {
                cx: size / 2,
                cy: size / 2,
                r: radius,
                fill: 'none',
                stroke: palette[index % palette.length],
                'stroke-width': '42',
                'stroke-dasharray': `${length} ${circumference - length}`,
                'stroke-dashoffset': -offset,
                transform: `rotate(-90 ${size / 2} ${size / 2})`,
            });
            svg.appendChild(circle);
            offset += length;
        });

        const totalLabel = svgNode('text', {
            x: size / 2,
            y: size / 2 - 4,
            'text-anchor': 'middle',
            'font-size': '28',
            fill: '#191b23',
        });
        totalLabel.textContent = total;
        svg.appendChild(totalLabel);

        const note = svgNode('text', {
            x: size / 2,
            y: size / 2 + 24,
            'text-anchor': 'middle',
            fill: '#5f6675',
        });
        note.textContent = 'hoi thoai';
        svg.appendChild(note);

        container.appendChild(svg);
    }

    function drawCharts() {
        drawBarChart('agent-handled-bar', data.agentBar || []);
        drawLineChart('agent-response-line', data.agentLine || [], {
            color: '#0052cc',
            area: false,
            label: 'Toc do phan hoi trung binh',
        });

        if (data.activeSection === 'dashboard' || data.activeSection === 'reports') {
            drawLineChart('today-conversation-line', data.todayLine || [], {
                color: '#ba1a1a',
                area: true,
                label: 'Hoi thoai theo thoi gian',
            });
            drawDonutChart('today-source-donut', data.todaySources || []);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initShell();
        drawCharts();
    });
})();
