<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

$database = new Database();
$db = $database->getConnection();

$idAppel = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($idAppel <= 0) {
    header('Location: appels_d_offres.php');
    exit();
}

// Récupérer les informations de l'appel d'offre
$sqlAppel = "SELECT ao.*, p.sujet, m.libMinistere, e.libEtablissement
             FROM appeloffre ao
             INNER JOIN projet p ON ao.idPro = p.idPro
             LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
             LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
             WHERE ao.idApp = :idApp";
$stmtAppel = $db->prepare($sqlAppel);
$stmtAppel->bindParam(':idApp', $idAppel);
$stmtAppel->execute();
$appelOffre = $stmtAppel->fetch(PDO::FETCH_ASSOC);

if (!$appelOffre) {
    header('Location: appels_d_offres.php');
    exit();
}

// Récupérer le document associé
$sqlDoc = "SELECT idDoc, libDoc, cheminAcces 
           FROM document 
           WHERE idExterne = :idApp AND type = 30 
           LIMIT 1";
$stmtDoc = $db->prepare($sqlDoc);
$stmtDoc->bindParam(':idApp', $idAppel);
$stmtDoc->execute();
$document = $stmtDoc->fetch(PDO::FETCH_ASSOC);

// Récupérer les lots
$sqlLots = "SELECT l.*, f.nomFour
            FROM lot l
            INNER JOIN fournisseur f ON l.idFournisseur = f.idFour
            WHERE l.idAppelOffre = :idApp
            ORDER BY l.lidLot";
$stmtLots = $db->prepare($sqlLots);
$stmtLots->bindParam(':idApp', $idAppel);
$stmtLots->execute();
$lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

$montantTotal = array_sum(array_column($lots, 'somme'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل الصفقة رقم <?php echo $idAppel; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .details-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .info-label {
            font-weight: 600;
            color: #666;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 16px;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        th, td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
        }
        .total-row {
            background: #f0f0f0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <h2 class="section-title">تفاصيل الصفقة</h2>
            
            <div class="details-container">
                <h3>معلومات الصفقة</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">المشروع</div>
                        <div class="info-value"><?php echo htmlspecialchars($appelOffre['sujet']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">تاريخ الإنشاء</div>
                        <div class="info-value"><?php echo date('Y-m-d', strtotime($appelOffre['dateCreation'])); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">الوزارة</div>
                        <div class="info-value"><?php echo htmlspecialchars($appelOffre['libMinistere']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">المؤسسة</div>
                        <div class="info-value"><?php echo htmlspecialchars($appelOffre['libEtablissement']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">عدد الأقساط</div>
                        <div class="info-value"><?php echo count($lots); ?></div>
                    </div>
                    <?php if ($document): ?>
                    <div class="info-item">
                        <div class="info-label">ملف الإسناد</div>
                        <div class="info-value">
                            <a href="<?php echo htmlspecialchars($document['cheminAcces']); ?>" 
                               target="_blank" 
                               style="color: #667eea; text-decoration: none; font-weight: 600;">
                                📄 تحميل الملف
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <h3 style="margin-top: 30px;">قائمة الصفقات</h3>
                <table>
                    <thead>
                        <tr>
                            <th>رقم</th>
                            <th>موضوع الصفقة</th>
                            <th>صاحب الصفقة</th>
                            <th>المبلغ (دينار)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lots as $index => $lot): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($lot['sujetLot']); ?></td>
                            <td><?php echo htmlspecialchars($lot['nomFour']); ?></td>
                            <td><?php echo number_format($lot['somme'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3">المجموع الإجمالي</td>
                            <td><?php echo number_format($montantTotal, 2); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 30px; text-align: center;">
                    <a href="appels_d_offres.php" class="btn btn-primary">← العودة إلى القائمة</a>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>
</body>
</html>