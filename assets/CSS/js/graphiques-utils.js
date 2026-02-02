// ========================================
// Fonctions utilitaires pour les graphiques
// ========================================

/**
 * Configuration globale des graphiques
 */
const ChartConfig = {
    // Couleurs du thème
    colors: {
        primary: '#FF6B35',
        secondary: '#F7931E',
        accent: '#4ECDC4',
        info: '#45B7D1',
        success: '#96CEB4',
        warning: '#FFEAA7',
        danger: '#FF7675'
    },
    
    // Palette de couleurs en dégradé
    gradientColors: [
        'rgba(255, 107, 53, 0.8)',
        'rgba(247, 147, 30, 0.8)',
        'rgba(78, 205, 196, 0.8)',
        'rgba(69, 183, 209, 0.8)',
        'rgba(150, 206, 180, 0.8)',
        'rgba(255, 234, 167, 0.8)',
        'rgba(255, 118, 117, 0.8)',
        'rgba(168, 118, 255, 0.8)',
        'rgba(255, 177, 193, 0.8)',
        'rgba(119, 221, 119, 0.8)'
    ],
    
    // Options par défaut pour tous les graphiques
    defaultOptions: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    font: {
                        size: 14,
                        family: "'Cairo', sans-serif"
                    },
                    padding: 15,
                    usePointStyle: true,
                    pointStyle: 'circle'
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                titleFont: {
                    size: 14,
                    weight: 'bold'
                },
                bodyFont: {
                    size: 13
                },
                borderColor: 'rgba(255, 107, 53, 0.5)',
                borderWidth: 1,
                displayColors: true,
                cornerRadius: 8
            }
        },
        animation: {
            duration: 1500,
            easing: 'easeInOutQuart'
        }
    }
};

/**
 * Créer un dégradé pour le graphique
 */
function createGradient(ctx, color1, color2, height = 400) {
    const gradient = ctx.createLinearGradient(0, 0, 0, height);
    gradient.addColorStop(0, color1);
    gradient.addColorStop(1, color2);
    return gradient;
}

/**
 * Formater les grands nombres
 */
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    } else if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
}

/**
 * Créer un graphique en ligne avec animation
 */
function createLineChart(canvasId, labels, data, label, color = ChartConfig.colors.primary) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    const gradient = createGradient(ctx, color + '33', color + '05');
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: label,
                data: data,
                borderColor: color,
                backgroundColor: gradient,
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: color,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: color,
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            ...ChartConfig.defaultOptions,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 12 },
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                x: {
                    ticks: {
                        font: { size: 11 },
                        maxRotation: 45,
                        minRotation: 45
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                ...ChartConfig.defaultOptions.plugins,
                tooltip: {
                    ...ChartConfig.defaultOptions.plugins.tooltip,
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.dataset.label + ': ' + formatNumber(context.parsed.y);
                        }
                    }
                }
            }
        }
    });
}

/**
 * Créer un graphique en barres horizontales
 */
function createHorizontalBarChart(canvasId, labels, data, label) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: label,
                data: data,
                backgroundColor: ChartConfig.gradientColors,
                borderColor: ChartConfig.gradientColors.map(color => color.replace('0.8', '1')),
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false
            }]
        },
        options: {
            ...ChartConfig.defaultOptions,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 12 },
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                y: {
                    ticks: {
                        font: { size: 12 }
                    },
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                ...ChartConfig.defaultOptions.plugins,
                tooltip: {
                    ...ChartConfig.defaultOptions.plugins.tooltip,
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.dataset.label + ': ' + formatNumber(context.parsed.x);
                        }
                    }
                }
            }
        }
    });
}

/**
 * Créer un graphique circulaire (Pie/Doughnut)
 */
function createPieChart(canvasId, labels, data, type = 'doughnut') {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: ChartConfig.gradientColors,
                borderColor: '#fff',
                borderWidth: 2,
                hoverOffset: 10
            }]
        },
        options: {
            ...ChartConfig.defaultOptions,
            cutout: type === 'doughnut' ? '60%' : 0,
            plugins: {
                ...ChartConfig.defaultOptions.plugins,
                legend: {
                    ...ChartConfig.defaultOptions.plugins.legend,
                    position: 'right'
                },
                tooltip: {
                    ...ChartConfig.defaultOptions.plugins.tooltip,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return ' ' + label + ': ' + value + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Exporter un graphique en PNG
 */
function exportChart(chartId, filename = 'graphique') {
    const canvas = document.getElementById(chartId);
    const url = canvas.toDataURL('image/png');
    const link = document.createElement('a');
    link.download = filename + '.png';
    link.href = url;
    link.click();
}

/**
 * Imprimer un graphique
 */
function printChart(containerId) {
    const container = document.getElementById(containerId);
    const printWindow = window.open('', '', 'height=600,width=800');
    
    printWindow.document.write('<html><head><title>Impression</title>');
    printWindow.document.write('<style>body{font-family: Arial; margin: 20px;} img{max-width: 100%;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(container.innerHTML);
    printWindow.document.write('</body></html>');
    
    printWindow.document.close();
    printWindow.focus();
    
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

/**
 * Mettre à jour les données d'un graphique
 */
function updateChartData(chart, newLabels, newData) {
    chart.data.labels = newLabels;
    chart.data.datasets[0].data = newData;
    chart.update('active');
}

/**
 * Basculer entre différents types de graphiques
 */
function toggleChartType(chart, newType) {
    chart.config.type = newType;
    chart.update();
}

/**
 * Ajouter un bouton d'export à un conteneur de graphique
 */
function addExportButton(containerId, chartId, filename) {
    const container = document.getElementById(containerId);
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'chart-actions';
    
    const exportBtn = document.createElement('button');
    exportBtn.className = 'chart-btn';
    exportBtn.innerHTML = '📥 تصدير PNG';
    exportBtn.onclick = () => exportChart(chartId, filename);
    
    const printBtn = document.createElement('button');
    printBtn.className = 'chart-btn secondary';
    printBtn.innerHTML = '🖨️ طباعة';
    printBtn.onclick = () => printChart(containerId);
    
    buttonContainer.appendChild(exportBtn);
    buttonContainer.appendChild(printBtn);
    container.insertBefore(buttonContainer, container.firstChild);
}

/**
 * Afficher une info-bulle au-dessus du graphique
 */
function showChartInfo(containerId, items) {
    const container = document.getElementById(containerId);
    const infoDiv = document.createElement('div');
    infoDiv.className = 'chart-info';
    
    items.forEach(item => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'chart-info-item';
        itemDiv.innerHTML = `
            <div class="chart-info-label">${item.label}</div>
            <div class="chart-info-value">${item.value}</div>
        `;
        infoDiv.appendChild(itemDiv);
    });
    
    const chartTitle = container.querySelector('.chart-title');
    chartTitle.insertAdjacentElement('afterend', infoDiv);
}

/**
 * Animer les nombres (compteur animé)
 */
function animateNumber(element, start, end, duration = 2000) {
    const range = end - start;
    const increment = range / (duration / 16);
    let current = start;
    
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
            current = end;
            clearInterval(timer);
        }
        element.textContent = Math.round(current);
    }, 16);
}

/**
 * Créer une légende personnalisée
 */
function createCustomLegend(containerId, labels, colors, values) {
    const container = document.getElementById(containerId);
    const legendDiv = document.createElement('div');
    legendDiv.className = 'custom-legend';
    
    labels.forEach((label, index) => {
        const item = document.createElement('div');
        item.className = 'legend-item';
        item.innerHTML = `
            <div class="legend-color" style="background-color: ${colors[index]}"></div>
            <span class="legend-label">${label}</span>
            <span class="legend-value">${values[index]}</span>
        `;
        legendDiv.appendChild(item);
    });
    
    container.appendChild(legendDiv);
}

/**
 * Afficher un message "Chargement en cours"
 */
function showLoader(containerId) {
    const container = document.getElementById(containerId);
    const wrapper = container.querySelector('.chart-wrapper');
    wrapper.innerHTML = `
        <div class="chart-loader">
            <div class="spinner"></div>
        </div>
    `;
}

/**
 * Afficher un message "Aucune donnée"
 */
function showNoData(containerId, message = 'لا توجد بيانات لعرضها') {
    const container = document.getElementById(containerId);
    const wrapper = container.querySelector('.chart-wrapper');
    wrapper.innerHTML = `
        <div class="no-data-message">
            <i>📊</i>
            <p>${message}</p>
        </div>
    `;
}

/**
 * Charger les données via AJAX et mettre à jour le graphique
 */
async function loadChartData(url, chartId, updateFunction) {
    try {
        showLoader(chartId + '-container');
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.labels && data.values) {
            updateFunction(data.labels, data.values);
        } else {
            showNoData(chartId + '-container');
        }
    } catch (error) {
        console.error('Erreur lors du chargement des données:', error);
        showNoData(chartId + '-container', 'خطأ في تحميل البيانات');
    }
}

/**
 * Ajouter un badge de dernière mise à jour
 */
function addLastUpdateBadge(containerId) {
    const container = document.getElementById(containerId);
    const badge = document.createElement('div');
    badge.className = 'last-update';
    badge.textContent = 'آخر تحديث: ' + new Date().toLocaleString('ar-TN');
    container.appendChild(badge);
}

/**
 * Initialisation au chargement de la page
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('Graphiques initialisés avec succès!');
    
    // Ajouter des animations d'entrée aux conteneurs de graphiques
    const containers = document.querySelectorAll('.chart-container');
    containers.forEach((container, index) => {
        container.style.opacity = '0';
        setTimeout(() => {
            container.classList.add('fade-in', 'slide-up');
            container.style.opacity = '1';
        }, index * 150);
    });
});

// Exporter les fonctions pour une utilisation globale
window.ChartUtils = {
    createLineChart,
    createHorizontalBarChart,
    createPieChart,
    exportChart,
    printChart,
    updateChartData,
    toggleChartType,
    addExportButton,
    showChartInfo,
    animateNumber,
    createCustomLegend,
    showLoader,
    showNoData,
    loadChartData,
    addLastUpdateBadge,
    formatNumber
};
