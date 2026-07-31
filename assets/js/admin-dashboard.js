(function () {
  'use strict';

  var activeStationTab = document.querySelector('.station-tab.is-active');
  if (activeStationTab && activeStationTab.parentElement.scrollWidth > activeStationTab.parentElement.clientWidth) {
    var stationTabs = activeStationTab.parentElement;
    var activeCenter = activeStationTab.offsetLeft + (activeStationTab.offsetWidth / 2);
    stationTabs.scrollLeft = Math.max(0, activeCenter - (stationTabs.clientWidth / 2));
  }

  var dataNode = document.getElementById('dashboard-data');
  if (!dataNode || !window.Chart) return;

  var metric;
  try {
    metric = JSON.parse(dataNode.textContent);
  } catch (_) {
    return;
  }

  var css = getComputedStyle(document.documentElement);
  var color = function (name, fallback) {
    return css.getPropertyValue(name).trim() || fallback;
  };
  var palette = {
    blue: color('--data-1', '#2563EB'),
    cyan: color('--data-2', '#0E9AA7'),
    purple: color('--data-3', '#7C3AED'),
    amber: color('--data-4', '#B45309'),
    slate: color('--data-5', '#64748B'),
    green: color('--success', '#047857'),
    red: color('--danger', '#DC2626'),
    muted: color('--fg-muted', '#55688A'),
    grid: '#E7EEF8'
  };
  var charts = [];

  Chart.defaults.font.family = '"DM Sans","Noto Sans TC","Microsoft JhengHei",system-ui,sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = palette.muted;
  Chart.defaults.animation = matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 320 };

  function rows(key) {
    return Array.isArray(metric[key]) ? metric[key] : [];
  }

  function create(id, type, source, colors, options) {
    var canvas = document.getElementById(id);
    if (!canvas || source.length === 0) return;
    var line = type === 'line';

    charts.push(new Chart(canvas, {
      type: type,
      data: {
        labels: line ? [] : source.map(function (item) { return item.label; }),
        datasets: [{
          data: line
            ? source.map(function (item) { return { x: Number(item.timestamp), y: Number(item.value) }; })
            : source.map(function (item) { return Number(item.value) || 0; }),
          backgroundColor: line ? 'transparent' : colors,
          borderColor: type === 'doughnut' ? '#FFFFFF' : colors,
          borderWidth: type === 'doughnut' ? 2 : (line ? 2 : 0),
          borderRadius: type === 'bar' ? 6 : 0,
          maxBarThickness: 56,
          fill: false,
          pointRadius: line ? 2 : 0,
          pointHoverRadius: line ? 3 : 0,
          spanGaps: line ? 120000 : false,
          tension: line ? 0.2 : 0
        }]
      },
      options: Object.assign({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: type === 'doughnut',
            position: 'bottom',
            labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 14 }
          }
        }
      }, options || {})
    }));
  }

  function barOptions(max) {
    return {
      plugins: { legend: { display: false } },
      scales: {
        y: {
          beginAtZero: true,
          suggestedMax: max,
          ticks: { precision: 0 },
          grid: { color: palette.grid, drawTicks: false },
          border: { display: false }
        },
        x: { grid: { display: false }, border: { color: palette.grid } }
      }
    };
  }

  function lineOptions(suffix) {
    return {
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: function (items) {
              return items.length === 0 ? '' : new Date(Number(items[0].parsed.x)).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { callback: function (value) { return value + suffix; } },
          grid: { color: palette.grid, drawTicks: false },
          border: { display: false }
        },
        x: {
          type: 'linear',
          ticks: {
            autoSkip: true,
            maxRotation: 0,
            maxTicksLimit: 6,
            callback: function (value) {
              return new Date(Number(value)).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
          },
          grid: { display: false },
          border: { color: palette.grid }
        }
      }
    };
  }

  create('vramChart', 'doughnut', rows('vram'), [palette.blue, palette.slate], { cutout: '62%' });
  var ramChart = document.getElementById('ramChart');
  if (metric.ramApplicable && ramChart) {
    create('ramChart', 'doughnut', rows('ram'), [palette.blue, palette.cyan, palette.slate], { cutout: '62%' });
  }
  metric.diskBars = rows('disk');
  var diskChart = document.getElementById('diskChart');
  if (metric.diskBars.length > 0 && diskChart) {
    create('diskChart', 'bar', metric.diskBars, palette.blue, Object.assign(barOptions(100), {
      scales: Object.assign(barOptions(100).scales, {
        y: Object.assign(barOptions(100).scales.y, {
          max: 100,
          ticks: { stepSize: 20, callback: function (value) { return value + '%'; } }
        })
      })
    }));
  }
  create('serviceChart', 'bar', rows('services'), [palette.green, palette.slate, palette.amber, palette.red], barOptions());
  create('gpuTemperatureChart', 'line', rows('gpuTemperatureHistory'), palette.amber, lineOptions('°C'));
  create('gpuVramHistoryChart', 'line', rows('gpuVramHistory'), palette.blue, lineOptions(' MB'));

  if ('ResizeObserver' in window) {
    var observer = new ResizeObserver(function () {
      charts.forEach(function (chart) { chart.resize(); });
    });
    charts.forEach(function (chart) { observer.observe(chart.canvas.parentElement); });
  }
})();
