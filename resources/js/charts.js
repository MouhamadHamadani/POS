/**
 * Chart bundle for the reports screens.
 *
 * Deliberately a separate Vite entry rather than an import in app.js:
 * chart.js is roughly twice the size of the whole current app bundle, and
 * only the reports use it. Pulling it into app.js would load it on the sell
 * screen too, which is the one screen that has to stay snappy.
 *
 * Data comes off the canvas as data-* attributes so this stays a real
 * bundled module — an inline <script type="module"> in a Blade view is not
 * processed by Vite, so a bare "chart.js/auto" import there never resolves.
 */
import Chart from 'chart.js/auto';

const canvas = document.getElementById('salesChart');

if (canvas) {
    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [{
                label: 'Revenue (USD)',
                data: JSON.parse(canvas.dataset.values),
                borderColor: '#124F4A',
                backgroundColor: 'rgba(93,179,173,0.15)',
                tension: 0.2,
                fill: true,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } },
        },
    });
}
