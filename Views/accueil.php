<?php
    require_once '../Config/Database.php';
    require_once '../Config/Security.php';
    require_once '../Models/Dashboard.php';

    Security::startSecureSession();
    Security::requireLogin();

    // Timeout session (30 minutes)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        Security::logout();
    }
    $_SESSION['last_activity'] = time();

    $database = new Database();
    $db = $database->getConnection();
    $dashboard = new Dashboard($db);

    // Récupération de toutes les statistiques
    $stats = $dashboard->getStats();
    $commission_data = $dashboard->getCommissionProjects();
    $projets_gouvernorat = $dashboard->getProjetsByGouvernorat();
    $projets_secteur = $dashboard->getProjetsBySecteur();
    $fournisseurs_projets = $dashboard->getFournisseursProjets();
    $projets_etablissement = $dashboard->getProjetsByEtablissement();
    $projets_ministere = $dashboard->getProjetsByMinistere();

    // Préparation des données pour les graphiques en JSON
    $gouvernorat_labels = json_encode(array_column($projets_gouvernorat, 'gouvernorat'));
    $gouvernorat_data = json_encode(array_column($projets_gouvernorat, 'nombre_projets'));

    $secteur_labels = json_encode(array_column($projets_secteur, 'secteur'));
    $secteur_data = json_encode(array_column($projets_secteur, 'nombre_projets'));

    $fournisseur_labels = json_encode(array_column($fournisseurs_projets, 'fournisseur'));
    $fournisseur_data = json_encode(array_column($fournisseurs_projets, 'nombre_projets'));

    $etablissement_labels = json_encode(array_column($projets_etablissement, 'etablissement'));
    $etablissement_data = json_encode(array_column($projets_etablissement, 'nombre_projets'));

    $ministere_labels = json_encode(array_column($projets_ministere, 'ministere'));
    $ministere_data = json_encode(array_column($projets_ministere, 'nombre_projets'));

    $page_title = "لوحة التحكم - نظام إدارة المشاريع";
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .admin-header {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(255, 107, 53, 0.3);
        }
        
        .admin-header h2 {
            font-size: 32px;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .admin-header p {
            font-size: 16px;
            opacity: 0.95;
        }

        .submenu {
            position: relative;
            display: inline-block;
        }
        
        .submenu-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(255, 107, 53, 0.2);
            border-radius: 8px;
            z-index: 1;
            top: 100%;
            right: 0;
            margin-top: 5px;
            border-top: 3px solid #FF6B35;
        }
        
        .submenu:hover .submenu-content {
            display: block;
        }
        
        .submenu-content a {
            color: #333;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            transition: background 0.3s;
        }
        
        .submenu-content a:hover {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
        }

        /* Styles pour les graphiques */
        .charts-section {
            padding: 40px 0;
            background: #f8f9fa;
        }

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .chart-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .chart-title {
            font-size: 24px;
            font-weight: bold;
            color: #FF6B35;
            margin-bottom: 20px;
            text-align: center;
            padding-bottom: 15px;
            border-bottom: 3px solid #F7931E;
        }

        .chart-wrapper {
            position: relative;
            height: 400px;
            margin-top: 20px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-full {
            grid-column: 1 / -1;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-wrapper {
                height: 300px;
            }
        }

        /* Animation d'entrée */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .chart-container {
            animation: fadeInUp 0.6s ease-out;
        }

        .chart-container:nth-child(1) { animation-delay: 0.1s; }
        .chart-container:nth-child(2) { animation-delay: 0.2s; }
        .chart-container:nth-child(3) { animation-delay: 0.3s; }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section with Stats -->
    <section class="hero-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-card red">
                    <div class="stat-number"><?php echo $stats['total_projets']; ?></div>
                    <div class="stat-label">العدد الجملي للمقترحات</div>
                </div>
                <div class="stat-card cyan">
                    <div class="stat-number"><?php echo $stats['projets_attente']; ?></div>
                    <div class="stat-label">بصدد الدرس</div>
                </div>
                <div class="stat-card pink">
                    <div class="stat-number"><?php echo $stats['projets_encours']; ?></div>
                    <div class="stat-label">الإحالة على اللجنة</div>
                </div>
                <div class="stat-card yellow">
                    <div class="stat-number"><?php echo $stats['appels_offre']; ?></div>
                    <div class="stat-label">الموافقة</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-number"><?php echo $stats['commissions']; ?></div>
                    <div class="stat-label">عدم الموافقة</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Charts Section -->
    <section class="charts-section">
        <div class="container">
            <div class="charts-grid">
                <!-- Graphique 1: Projets par Gouvernorat -->
                <div class="chart-container">
                    <h3 class="chart-title">📊 المشاريع حسب الولايات</h3>
                    <div class="chart-wrapper">
                        <canvas id="gouvernoratChart"></canvas>
                    </div>
                </div>

                <!-- Graphique 2: Projets par Secteur -->
                <div class="chart-container">
                    <h3 class="chart-title">📈 المشاريع حسب القطاعات</h3>
                    <div class="chart-wrapper">
                        <canvas id="secteurChart"></canvas>
                    </div>
                </div>

                

                <!-- Graphique 4: Établissements -->
                <div class="chart-container">
                    <h3 class="chart-title">🏛️ المشاريع حسب المؤسسات</h3>
                    <div class="chart-wrapper">
                        <canvas id="etablissementChart"></canvas>
                    </div>
                </div>

                <!-- Graphique 5: Ministères -->
                <div class="chart-container">
                    <h3 class="chart-title">🏛️ المشاريع حسب الوزارات</h3>
                    <div class="chart-wrapper">
                        <canvas id="ministereChart"></canvas>
                    </div>
                </div>

                <!-- Graphique 3: Fournisseurs (Full Width) -->
                <div class="chart-container chart-full">
                    <h3 class="chart-title">🏢 أهم الموردين حسب عدد المشاريع</h3>
                    <div class="chart-wrapper">
                        <canvas id="fournisseurChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script>
        // Configuration des couleurs
        const colors = {
            primary: '#FF6B35',
            secondary: '#F7931E',
            accent: '#4ECDC4',
            info: '#45B7D1',
            success: '#96CEB4',
            warning: '#FFEAA7',
            danger: '#FF7675'
        };

        const gradientColors = [
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
        ];

        // Graphique 1: Projets par Gouvernorat (Courbe)
        const ctxGouvernorat = document.getElementById('gouvernoratChart').getContext('2d');
        const gouvernoratChart = new Chart(ctxGouvernorat, {
            type: 'line',
            data: {
                labels: <?php echo $gouvernorat_labels; ?>,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: <?php echo $gouvernorat_data; ?>,
                    borderColor: colors.primary,
                    backgroundColor: 'rgba(255, 107, 53, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: colors.primary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
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
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return ' عدد المشاريع: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 11
                            },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Graphique 2: Projets par Secteur (Courbe avec gradient)
        const ctxSecteur = document.getElementById('secteurChart').getContext('2d');
        const gradient = ctxSecteur.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(247, 147, 30, 0.3)');
        gradient.addColorStop(1, 'rgba(247, 147, 30, 0.05)');

        const secteurChart = new Chart(ctxSecteur, {
            type: 'line',
            data: {
                labels: <?php echo $secteur_labels; ?>,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: <?php echo $secteur_data; ?>,
                    borderColor: colors.secondary,
                    backgroundColor: gradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 6,
                    pointHoverRadius: 9,
                    pointBackgroundColor: colors.secondary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: colors.secondary,
                    pointHoverBorderWidth: 3
                }]
            },
            options: {
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
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return ' عدد المشاريع: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Graphique 3: Fournisseurs (Barres horizontales)
        const ctxFournisseur = document.getElementById('fournisseurChart').getContext('2d');
        const fournisseurChart = new Chart(ctxFournisseur, {
            type: 'bar',
            data: {
                labels: <?php echo $fournisseur_labels; ?>,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: <?php echo $fournisseur_data; ?>,
                    backgroundColor: gradientColors,
                    borderColor: gradientColors.map(color => color.replace('0.8', '1')),
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
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
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return ' عدد المشاريع: ' + context.parsed.x;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y: {
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Graphique 4: Projets par Établissement (Courbe)
        const ctxEtablissement = document.getElementById('etablissementChart').getContext('2d');
        const etablissementChart = new Chart(ctxEtablissement, {
            type: 'line',
            data: {
                labels: <?php echo $etablissement_labels; ?>,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: <?php echo $etablissement_data; ?>,
                    borderColor: '#4ECDC4',
                    backgroundColor: 'rgba(78, 205, 196, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#4ECDC4',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
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
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return ' عدد المشاريع: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 10
                            },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Graphique 5: Projets par Ministère (Courbe)
        const ctxMinistere = document.getElementById('ministereChart').getContext('2d');
        const ministereChart = new Chart(ctxMinistere, {
            type: 'line',
            data: {
                labels: <?php echo $ministere_labels; ?>,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: <?php echo $ministere_data; ?>,
                    borderColor: '#45B7D1',
                    backgroundColor: 'rgba(69, 183, 209, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#45B7D1',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
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
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return ' عدد المشاريع: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 10
                            },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Timeout automatique après 30 minutes d'inactivité
        let inactivityTime = function () {
            let time;
            window.onload = resetTimer;
            document.onmousemove = resetTimer;
            document.onkeypress = resetTimer;
            document.onclick = resetTimer;
            document.onscroll = resetTimer;

            function logout() {
                window.location.href = '../logout.php';
            }

            function resetTimer() {
                clearTimeout(time);
                time = setTimeout(logout, 1800000); // 30 minutes
            }
        };

        inactivityTime();

        // Animation au chargement des cartes statistiques
        window.addEventListener('load', function() {
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                setTimeout(() => {
                    card.style.animation = 'slideUp 0.5s ease forwards';
                }, index * 100);
            });
        });
    </script>
</body>
</html>