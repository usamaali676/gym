/**
 * Chart.js wrappers with the app's neon theme. Chart.js is code-split: it is only downloaded by pages that call these.
 * Only the controllers and scales we use are registered, which keeps the chunk small.
 */

let chartPromise = null;

async function chartLib() {
    chartPromise ??= import('chart.js').then((m) => {
        m.Chart.register(m.BarController, m.LineController, m.BarElement, m.LineElement, m.PointElement, m.CategoryScale, m.LinearScale, m.Tooltip, m.Legend, m.Filler);
        m.Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        m.Chart.defaults.color = '#8FA39A';
        m.Chart.defaults.borderColor = 'rgba(57, 255, 20, 0.10)';
        return m.Chart;
    });

    return chartPromise;
}

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const shortDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

/** Calories per day (bars) against that day's target (stepped line), plus protein on its own axis. */
export async function nutritionChart(canvas, history) {
    const Chart = await chartLib();
    const labels = history.map((h) => shortDate(h.date));
    const hasTargets = history.some((h) => h.target_calories !== null);

    return new Chart(canvas, {
        data: {
            labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Calories',
                    data: history.map((h) => Math.round(h.calories)),
                    backgroundColor: history.map((h) => (h.target_calories && h.calories > h.target_calories * 1.1 ? 'rgba(255, 200, 61, 0.65)' : 'rgba(57, 255, 20, 0.55)')),
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 2,
                },
                ...(hasTargets ? [{
                    type: 'line',
                    label: 'Calorie target',
                    data: history.map((h) => h.target_calories),
                    borderColor: 'rgba(232, 245, 233, 0.7)',
                    borderDash: [5, 4],
                    borderWidth: 2,
                    pointRadius: 0,
                    stepped: true,
                    yAxisID: 'y',
                    order: 1,
                }] : []),
                {
                    type: 'line',
                    label: 'Protein (g)',
                    data: history.map((h) => Math.round(h.protein_g)),
                    borderColor: '#FFC83D',
                    backgroundColor: '#FFC83D',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.25,
                    yAxisID: 'y1',
                    order: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion() ? false : { duration: 600 },
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } } },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
                y: { beginAtZero: true, title: { display: true, text: 'kcal' }, grid: { color: 'rgba(57, 255, 20, 0.08)' } },
                y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'protein g' }, grid: { drawOnChartArea: false } },
            },
        },
    });
}
