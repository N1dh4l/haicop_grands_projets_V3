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

    // Requête : fournisseurs avec nombre de projets ET somme des montants (via lot)
    $sql_four = "
        SELECT 
            f.nomFour               AS fournisseur,
            COUNT(DISTINCT ao.idPro) AS nombre_projets,
            SUM(l.somme)            AS total_cout
        FROM fournisseur f
        INNER JOIN lot l         ON l.idFournisseur = f.idFour
        INNER JOIN appeloffre ao ON ao.idApp = l.idAppelOffre
        INNER JOIN projet p      ON p.idPro = ao.idPro
        GROUP BY f.idFour, f.nomFour
        ORDER BY nombre_projets DESC
    ";
    $stmt_four = $db->prepare($sql_four);
    $stmt_four->execute();
    $fournisseurs_avec_cout = $stmt_four->fetchAll(PDO::FETCH_ASSOC);

    // Requête : 5 secteurs avec leurs gouvernorats et nombre de projets
    $sql_regions = "
        SELECT 
            s.idSecteur,
            s.numSecteur,
            g.idGov,
            g.libGov            AS gouvernorat,
            COUNT(p.id_Gov)     AS nombre_projets
        FROM secteur s
        INNER JOIN gouvernorat g ON g.idSecteur = s.idSecteur
        LEFT JOIN projet p ON p.id_Gov = g.idGov
        GROUP BY s.idSecteur, s.numSecteur, g.idGov, g.libGov
        ORDER BY s.idSecteur ASC, nombre_projets DESC
    ";
    $stmt_regions = $db->prepare($sql_regions);
    $stmt_regions->execute();
    $rows_regions = $stmt_regions->fetchAll(PDO::FETCH_ASSOC);

    // Construire la structure : [ idSecteur => ['label'=>..., 'govs'=>[...]] ]
    $regions_data = array();
    foreach ($rows_regions as $row) {
        $sid = $row['idSecteur'];
        if (!isset($regions_data[$sid])) {
            $regions_data[$sid] = array(
                'label' => 'الإقليم ' . $row['numSecteur'],
                'govs'  => array()
            );
        }
        $regions_data[$sid]['govs'][] = array(
            'gouvernorat'    => $row['gouvernorat'],
            'nombre_projets' => (int)$row['nombre_projets'],
            'idGov'          => $row['idGov'],
        );
    }

    // Total global pour les pourcentages et la barre max
    $grand_total_govs = 0;
    $grand_max_govs   = 1;
    foreach ($regions_data as $sect) {
        foreach ($sect['govs'] as $g) {
            $grand_total_govs += $g['nombre_projets'];
            if ($g['nombre_projets'] > $grand_max_govs) $grand_max_govs = $g['nombre_projets'];
        }
    }

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
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
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
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
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

            .fournisseur-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 15px;
            }

            .fournisseur-table thead tr {
                background: #335F8A;
                color: white;
            }

            .fournisseur-table th {
                padding: 14px 20px;
                text-align: center;
                font-weight: 600;
                font-size: 15px;
                border: none;
                background: #335F8A;
                color: white;
            }

            .fournisseur-table tbody tr {
                border-bottom: 1px solid #eef0f3;
                transition: background 0.2s;
            }

            .fournisseur-table tbody tr:hover {
                background: #f0f6ff;
            }

            .fournisseur-table tbody tr:nth-child(even) {
                background: #f8fafd;
            }

            .fournisseur-table tbody tr:nth-child(even):hover {
                background: #f0f6ff;
            }

            .fournisseur-table td {
                padding: 13px 20px;
                text-align: center;
                border: none;
            }

            .fournisseur-table td.rank {
                font-weight: bold;
                color: #335F8A;
                font-size: 16px;
                width: 60px;
            }

            .fournisseur-table td.name {
                text-align: right;
                font-weight: 500;
                color: #333;
            }

            .fournisseur-table td.count {
                font-weight: bold;
                font-size: 18px;
                color: #5784BA;
                width: 120px;
                direction: ltr;
            }

            .fournisseur-table td.montant {
                font-weight: 600;
                font-size: 14px;
                color: #2e7d32;
                white-space: nowrap;
            }

            .inline-bar {
                display: flex;
                align-items: center;
                gap: 10px;
                min-width: 200px;
            }

            .inline-bar-fill {
                height: 12px;
                background: linear-gradient(90deg, #335F8A, #9AC8EB);
                border-radius: 6px;
                transition: width 0.8s ease;
            }

            .inline-bar-pct {
                font-size: 12px;
                color: #888;
                white-space: nowrap;
                direction: ltr;
            }

            /* ===== ACCORDÉON RÉGIONS / GOUVERNORATS ===== */
            .regions-accordion {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-top: 10px;
            }

            .region-card {
                border: 1.5px solid #d0e4f7;
                border-radius: 12px;
                overflow: hidden;
                transition: box-shadow 0.25s;
            }

            .region-card:hover {
                box-shadow: 0 4px 18px rgba(53,122,189,0.13);
            }

            .region-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 20px;
                background: linear-gradient(90deg, #e8f1fb 0%, #f4f8fd 100%);
                cursor: pointer;
                user-select: none;
                gap: 12px;
                transition: background 0.2s;
            }

            .region-header:hover { background: linear-gradient(90deg, #d6e8f7 0%, #eaf3fb 100%); }

            .region-header.open { background: linear-gradient(90deg, #4a90e2 0%, #357abd 100%); }
            .region-header.open .region-name,
            .region-header.open .region-total,
            .region-header.open .region-chevron { color: white; }

            .region-name {
                font-size: 16px;
                font-weight: 700;
                color: #335F8A;
                flex: 1;
            }

            .region-total {
                font-size: 13px;
                font-weight: 600;
                color: #5784BA;
                background: rgba(74,144,226,0.12);
                padding: 3px 12px;
                border-radius: 20px;
                white-space: nowrap;
                direction: ltr;
            }

            .region-header.open .region-total {
                background: rgba(255,255,255,0.25);
                color: white;
            }

            .region-chevron {
                color: #4a90e2;
                font-size: 18px;
                transition: transform 0.3s;
                flex-shrink: 0;
            }

            .region-header.open .region-chevron { transform: rotate(180deg); }

            .region-body {
                display: none;
                padding: 18px 22px;
                background: #fff;
                border-top: 1px solid #e4eff9;
            }

            .region-body.open { display: block; animation: fadeSlide 0.25s ease; }

            @keyframes fadeSlide {
                from { opacity: 0; transform: translateY(-6px); }
                to   { opacity: 1; transform: translateY(0); }
            }

            .gov-row {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 7px 0;
                border-bottom: 1px dashed #edf2f9;
                cursor: pointer;
                border-radius: 6px;
                transition: background 0.15s;
                padding-left: 6px;
                padding-right: 6px;
            }

            .gov-row:last-child { border-bottom: none; }
            .gov-row:hover { background: #f0f6ff; }

            .gov-name {
                width: 130px;
                min-width: 100px;
                font-size: 13.5px;
                font-weight: 600;
                color: #444;
                text-align: right;
                flex-shrink: 0;
            }

            .gov-bar-wrap {
                flex: 1;
                background: #edf2f9;
                border-radius: 6px;
                height: 14px;
                overflow: hidden;
            }

            .gov-bar-fill {
                height: 100%;
                border-radius: 6px;
                background: linear-gradient(90deg, #4a90e2, #9AC8EB);
                transition: width 0.7s ease;
            }

            .gov-count {
                font-size: 13px;
                font-weight: 700;
                color: #357abd;
                width: 36px;
                text-align: center;
                flex-shrink: 0;
                direction: ltr;
            }

            .gov-pct {
                font-size: 11px;
                color: #999;
                width: 42px;
                text-align: left;
                flex-shrink: 0;
                direction: ltr;
            }

            .no-gov-data {
                text-align: center;
                color: #bbb;
                font-size: 13px;
                padding: 10px 0;
            }

            /* ===== MODAL STYLES ===== */
            .modal-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 9999;
                justify-content: center;
                align-items: center;
                backdrop-filter: blur(3px);
                animation: fadeIn 0.2s ease;
            }
            .modal-overlay.active {
                display: flex;
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to   { opacity: 1; }
            }
            .modal-box {
                background: #fff;
                border-radius: 18px;
                box-shadow: 0 20px 60px rgba(53,122,189,0.25);
                width: 90%;
                max-width: 750px;
                max-height: 85vh;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                animation: slideUp 0.3s ease;
            }
            @keyframes slideUp {
                from { transform: translateY(40px); opacity: 0; }
                to   { transform: translateY(0);    opacity: 1; }
            }
            .modal-header {
                background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
                color: white;
                padding: 20px 28px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }
            .modal-header h4 {
                margin: 0;
                font-size: 20px;
                font-weight: 700;
            }
            .modal-header .modal-badge {
                background: rgba(255,255,255,0.25);
                border-radius: 20px;
                padding: 4px 14px;
                font-size: 14px;
                font-weight: 600;
            }
            .modal-close {
                background: rgba(255,255,255,0.2);
                border: none;
                color: white;
                font-size: 22px;
                cursor: pointer;
                border-radius: 50%;
                width: 36px;
                height: 36px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.2s;
                flex-shrink: 0;
            }
            .modal-close:hover { background: rgba(255,255,255,0.35); }
            .modal-body {
                overflow-y: auto;
                padding: 24px 28px;
            }
            .modal-loading {
                text-align: center;
                padding: 40px;
                color: #4a90e2;
                font-size: 16px;
            }
            .modal-loading::after {
                content: '';
                display: block;
                width: 40px;
                height: 40px;
                border: 4px solid #e0eaf6;
                border-top-color: #4a90e2;
                border-radius: 50%;
                margin: 16px auto 0;
                animation: spin 0.8s linear infinite;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
            .modal-projects-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
            }
            .modal-projects-table thead tr {
                background: #335F8A;
                color: white;
            }
            .modal-projects-table th {
                padding: 12px 16px;
                text-align: center;
                font-weight: 600;
            }
            .modal-projects-table th:nth-child(2),
            .modal-projects-table td:nth-child(2) {
                width: 45%;
                min-width: 220px;
            }
            .modal-projects-table tbody tr {
                border-bottom: 1px solid #eef0f3;
                transition: background 0.15s;
            }
            .modal-projects-table tbody tr:hover { background: #f0f6ff; }
            .modal-projects-table tbody tr:nth-child(even) { background: #f8fafd; }
            .modal-projects-table tbody tr:nth-child(even):hover { background: #f0f6ff; }
            .modal-projects-table td {
                padding: 11px 16px;
                text-align: center;
            }
            .status-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .status-attente  { background: #fff3cd; color: #856404; }
            .status-encours  { background: #cfe2ff; color: #084298; }
            .status-approuve { background: #d1e7dd; color: #0a3622; }
            .status-rejete   { background: #f8d7da; color: #842029; }
            .modal-empty {
                text-align: center;
                padding: 40px;
                color: #888;
                font-size: 15px;
            }
            /* Chart cursor pointer */
            canvas { cursor: pointer; }
            .fournisseur-table tbody tr { cursor: pointer; }
            /* Chiffres toujours LTR */
            .stat-number, .gov-count, .gov-pct, .region-total,
            .fournisseur-table td.count, .fournisseur-table td.montant,
            .inline-bar-pct, .modal-badge {
                direction: ltr;
                unicode-bidi: embed;
            }
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
                    <div class="stat-label">موافقة وقتية</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Charts Section -->
    <section class="charts-section">
        <div class="container">
            <div class="charts-grid">
                <!-- Accordéon: 5 Secteurs / Gouvernorats depuis la BDD -->
                <div class="chart-container">
                    <h3 class="chart-title">📈 المشاريع المعروضة حسب الأقاليم والولايات</h3>
                    <div class="regions-accordion" id="regionsAccordion">
                        <?php foreach ($regions_data as $sid => $sect):
                            $region_name  = $sect['label'];
                            $govs         = $sect['govs'];
                            $region_total = array_sum(array_column($govs, 'nombre_projets'));
                        ?>
                        <div class="region-card">
                            <div class="region-header" onclick="toggleRegion(this)">
                                <span class="region-name">🗺️ <?php echo htmlspecialchars($region_name); ?></span>
                                <span class="region-total"><?php echo $region_total; ?> مشروع</span>
                                <span class="region-chevron">▼</span>
                            </div>
                            <div class="region-body">
                                <?php if ($region_total == 0): ?>
                                    <div class="no-gov-data">لا توجد مشاريع في هذا الإقليم</div>
                                <?php else: ?>
                                    <?php foreach ($govs as $gov):
                                        $count = $gov['nombre_projets'];
                                        $bar_w = $grand_max_govs > 0 ? round(($count / $grand_max_govs) * 100) : 0;
                                        $pct   = $grand_total_govs > 0 ? round(($count / $grand_total_govs) * 100, 1) : 0;
                                    ?>
                                    <div class="gov-row" onclick="openModal('gouvernorat','<?php echo addslashes($gov['gouvernorat']); ?>',<?php echo $count; ?>)" title="انقر لرؤية مشاريع <?php echo htmlspecialchars($gov['gouvernorat']); ?>">
                                        <span class="gov-name"><?php echo htmlspecialchars($gov['gouvernorat']); ?></span>
                                        <div class="gov-bar-wrap">
                                            <div class="gov-bar-fill" style="width:<?php echo $bar_w; ?>%"></div>
                                        </div>
                                        <span class="gov-count"><?php echo $count; ?></span>
                                        <span class="gov-pct"><?php echo $pct; ?>%</span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Graphique 1: Projets par Gouvernorat -->
                <div class="chart-container">
                    <h3 class="chart-title">📊 المشاريع المعروضة حسب الولايات</h3>
                    <div class="chart-wrapper">
                        <canvas id="gouvernoratChart"></canvas>
                    </div>
                </div>
                
                <!-- Graphique 4: Établissements -->
                <div class="chart-container">
                    <h3 class="chart-title">🏛️ المشاريع المعروضة حسب المؤسسات</h3>
                    <div class="chart-wrapper">
                        <canvas id="etablissementChart"></canvas>
                    </div>
                </div>

                <!-- Graphique 5: Ministères -->
                <div class="chart-container">
                    <h3 class="chart-title">🏛️ المشاريع المعروضة حسب الوزارات</h3>
                    <div class="chart-wrapper">
                        <canvas id="ministereChart"></canvas>
                    </div>
                </div>

                <!-- Tableau: Fournisseurs (Full Width) -->
                <div class="chart-container chart-full">
                    <h3 class="chart-title">🏢 أهم أصحاب الصفقة حسب عدد المشاريع</h3>
                    <div class="table-responsive">
                        <table class="fournisseur-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>صاحب الصفقة</th>
                                    <th>عدد المشاريع</th>
                                    <th>مبالغ الصفقات (مليون دينار)</th>
                                    <th>التمثيل البياني</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $data_table = !empty($fournisseurs_avec_cout) ? $fournisseurs_avec_cout : $fournisseurs_projets;
                                    $total = array_sum(array_column($data_table, 'nombre_projets'));
                                    $max   = $total > 0 ? max(array_column($data_table, 'nombre_projets')) : 1;
                                    foreach ($data_table as $index => $row):
                                        $pct_total = $total > 0 ? round(($row['nombre_projets'] / $total) * 100, 1) : 0;
                                        $bar_width = $max > 0 ? round(($row['nombre_projets'] / $max) * 100) : 0;
                                        $montant   = isset($row['total_cout']) && $row['total_cout'] > 0
                                                     ? number_format($row['total_cout'], 3, '.', ' ')
                                                     : '—';
                                ?>
                                <tr onclick="openModal('fournisseur', '<?php echo addslashes($row['fournisseur']); ?>', <?php echo $row['nombre_projets']; ?>)" title="انقر لرؤية المشاريع">
                                    <td class="rank"><?php echo $index + 1; ?></td>
                                    <td class="name"><?php echo htmlspecialchars($row['fournisseur']); ?></td>
                                    <td class="count"><?php echo $row['nombre_projets']; ?></td>
                                    <td class="montant"><?php echo $montant; ?></td>
                                    <td class="bar-cell">
                                        <div class="inline-bar">
                                            <div class="inline-bar-fill" style="width: <?php echo $bar_width; ?>%"></div>
                                            <span class="inline-bar-pct">
                                               <strong>(<?php echo $pct_total; ?>%)</strong>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <!-- ===== MODAL DÉTAILS PROJETS ===== -->
    <div class="modal-overlay" id="projectsModal">
        <div class="modal-box">
            <div class="modal-header">
                <h4 id="modalTitle">قائمة المشاريع</h4>
                <span class="modal-badge" id="modalBadge">0 مشروع</span>
                <button class="modal-close" onclick="closeModal()" title="إغلاق">✕</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="modal-loading">جاري التحميل...</div>
            </div>
        </div>
    </div>

    <script>
        // Configuration des couleurs (palette bleue)
        const blueColors = [
            'rgba(53, 122, 189, 0.85)',
            'rgba(87, 132, 186, 0.85)',
            'rgba(154, 200, 235, 0.85)',
            'rgba(140, 172, 211, 0.85)',
            'rgba(49, 122, 193, 0.85)',
            'rgba(100, 149, 200, 0.85)',
            'rgba(70, 110, 170, 0.85)',
            'rgba(120, 170, 220, 0.85)',
            'rgba(80, 130, 190, 0.85)',
            'rgba(60, 100, 160, 0.85)'
        ];

        // Plugin pour afficher les chiffres au-dessus des barres
        const dataLabelsPlugin = {
            id: 'dataLabels',
            afterDatasetsDraw(chart) {
                const { ctx } = chart;
                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    meta.data.forEach((bar, index) => {
                        const value = dataset.data[index];
                        ctx.save();
                        ctx.fillStyle = '#333';
                        ctx.font = 'bold 12px Segoe UI';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(value, bar.x, bar.y - 4);
                        ctx.restore();
                    });
                });
            }
        };

        // Options communes pour tous les graphiques en barres verticales
        function getBarOptions(chartType, labels) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                onClick(evt, elements) {
                    if (elements.length > 0) {
                        const idx = elements[0].index;
                        const label = labels[idx];
                        const count = this.data.datasets[0].data[idx];
                        openModal(chartType, label, count);
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: { size: 14, family: "'Cairo', sans-serif" },
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        padding: 12,
                        titleFont: { size: 14 },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return ' عدد المشاريع: ' + context.parsed.y + ' (انقر للتفاصيل)';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 12 } },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        ticks: {
                            font: { size: 11 },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: { display: false }
                    }
                }
            };
        }

        // Données PHP → JS
        const gouvernoratLabels = <?php echo $gouvernorat_labels; ?>;
        const gouvernoratData   = <?php echo $gouvernorat_data; ?>;
        const etablissementLabels = <?php echo $etablissement_labels; ?>;
        const etablissementData   = <?php echo $etablissement_data; ?>;
        const ministereLabels   = <?php echo $ministere_labels; ?>;
        const ministereData     = <?php echo $ministere_data; ?>;

        // ===== ACCORDÉON RÉGIONS =====
        function toggleRegion(header) {
            const body    = header.nextElementSibling;
            const isOpen  = header.classList.contains('open');
            // Fermer tous les ouverts
            document.querySelectorAll('.region-header.open').forEach(h => {
                h.classList.remove('open');
                h.nextElementSibling.classList.remove('open');
            });
            // Ouvrir celui cliqué (sauf si déjà ouvert)
            if (!isOpen) {
                header.classList.add('open');
                body.classList.add('open');
            }
        }

        // ===== GRAPHIQUES =====

        // Graphique 1: Projets par Gouvernorat
        const ctxGouvernorat = document.getElementById('gouvernoratChart').getContext('2d');
        new Chart(ctxGouvernorat, {
            type: 'bar',
            plugins: [dataLabelsPlugin],
            data: {
                labels: gouvernoratLabels,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: gouvernoratData,
                    backgroundColor: 'rgba(53, 122, 189, 0.8)',
                    borderColor: 'rgba(53, 122, 189, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: getBarOptions('gouvernorat', gouvernoratLabels)
        });

        // Graphique 4: Projets par Établissement
        const ctxEtablissement = document.getElementById('etablissementChart').getContext('2d');
        new Chart(ctxEtablissement, {
            type: 'bar',
            plugins: [dataLabelsPlugin],
            data: {
                labels: etablissementLabels,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: etablissementData,
                    backgroundColor: 'rgba(154, 200, 235, 0.85)',
                    borderColor: 'rgba(154, 200, 235, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: getBarOptions('etablissement', etablissementLabels)
        });

        // Graphique 5: Projets par Ministère
        const ctxMinistere = document.getElementById('ministereChart').getContext('2d');
        new Chart(ctxMinistere, {
            type: 'bar',
            plugins: [dataLabelsPlugin],
            data: {
                labels: ministereLabels,
                datasets: [{
                    label: 'عدد المشاريع',
                    data: ministereData,
                    backgroundColor: 'rgba(140, 172, 211, 0.85)',
                    borderColor: 'rgba(140, 172, 211, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: getBarOptions('ministere', ministereLabels)
        });

        // ===== MODAL LOGIC =====
        function openModal(type, label, count) {
            const modal   = document.getElementById('projectsModal');
            const title   = document.getElementById('modalTitle');
            const badge   = document.getElementById('modalBadge');
            const body    = document.getElementById('modalBody');

            const typeLabels = {
                gouvernorat:   'المشاريع في الولاية: ',
                secteur:       'المشاريع في الإقليم: ',
                etablissement: 'المشاريع في المؤسسة: ',
                ministere:     'المشاريع في الوزارة: ',
                fournisseur:   'المشاريع لصاحب الصفقة: '
            };

            title.textContent = (typeLabels[type] || '') + label;
            badge.textContent = count + ' مشروع';
            body.innerHTML    = '<div class="modal-loading">جاري التحميل...</div>';
            modal.classList.add('active');

            // Appel AJAX vers l'endpoint PHP
        fetch(`get_projets_par_filtre.php?type=${encodeURIComponent(type)}&value=${encodeURIComponent(label)}`)
                .then(r => r.json())
                .then(data => renderProjects(data, body))
                .catch(err => {
                    console.warn('Endpoint not ready, showing demo data.', err);
                    renderProjects(getDemoProjects(type, label, count), body);
                });
        }

        function renderProjects(projects, body) {
            if (!projects || projects.length === 0) {
                body.innerHTML = '<div class="modal-empty">⚠️ لا توجد مشاريع لعرضها</div>';
                return;
            }

            
            let rows = projects.map((p, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td style="text-align:right">${p.sujet || '-'}</td>
                    <td>${p.montant ? Number(p.montant).toLocaleString('fr-TN') + ' مليون دينار ' : '-'}</td>
                </tr>
            `).join('');

            body.innerHTML = `
                <table class="modal-projects-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th> المشروع</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;
        }

        // Données de démonstration si l'endpoint n'est pas encore créé
        function getDemoProjects(type, label, count) {
            const statuts = ['بصدد الدرس', 'الإحالة على اللجنة', 'الموافقة', 'عدم الموافقة'];
            return Array.from({ length: Math.min(count, 8) }, (_, i) => ({
                titre:   `مشروع ${i + 1} - ${label}`,
                montant: Math.round(Math.random() * 500000 + 50000),
                statut:  statuts[i % statuts.length]
            }));
        }

        function closeModal() {
            document.getElementById('projectsModal').classList.remove('active');
        }

        // Fermer en cliquant en dehors
        document.getElementById('projectsModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Fermer avec Escape
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

        // Timeout automatique après 30 minutes d'inactivité
        let inactivityTime = function () {
            let time;
            window.onload = resetTimer;
            document.onmousemove = resetTimer;
            document.onkeypress = resetTimer;
            document.onclick = resetTimer;
            document.onscroll = resetTimer;
            function logout() { window.location.href = '../logout.php'; }
            function resetTimer() { clearTimeout(time); time = setTimeout(logout, 1800000); }
        };
        inactivityTime();

        window.addEventListener('load', function() {
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                setTimeout(() => { card.style.animation = 'slideUp 0.5s ease forwards'; }, index * 100);
            });
        });
</script>
</body>
</html>