import Chart from 'chart.js/auto';

const el = document.getElementById('customer-risk-data');

if (el) {
    const data = JSON.parse(el.textContent);
    const animation = new URLSearchParams(window.location.search).get('print') === '1' ? false : undefined;
    const base = { responsive: true, maintainAspectRatio: false, animation };

    const months = data.months;
    const labels = months.map((m) => m.label);

    new Chart(document.getElementById('chart-ontime-late'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'On time', data: months.map((m) => m.on_time), backgroundColor: '#10b981' },
                { label: 'Late', data: months.map((m) => m.late), backgroundColor: '#ef4444' },
            ],
        },
        options: { ...base, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } },
    });

    new Chart(document.getElementById('chart-days-late'), {
        type: 'line',
        data: {
            labels: data.installment_series.labels,
            datasets: [
                { label: 'Days late', data: data.installment_series.days_late, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: 0.2 },
                { label: 'Trend line', data: data.installment_series.trend_line, borderColor: '#6366f1', borderDash: [6, 4], pointRadius: 0, spanGaps: false },
            ],
        },
        options: { ...base, scales: { y: { beginAtZero: true, title: { display: true, text: 'Days late' } }, x: { ticks: { display: false } } } },
    });

    new Chart(document.getElementById('chart-due-paid'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Due', data: months.map((m) => m.due), backgroundColor: '#94a3b8' },
                { label: 'Paid', data: months.map((m) => m.paid), backgroundColor: '#3b82f6' },
            ],
        },
        options: { ...base, scales: { y: { beginAtZero: true } } },
    });
}
