<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_GET['id'])) {
        echo json_encode(['success' => false, 'message' => 'معرف المشروع مفقود'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $projetId = intval($_GET['id']);
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les détails du projet
    $sql = "SELECT 
                p.*, 
                m.libMinistere, 
                g.libGov,
                e.libEtablissement,
                u.nomUser,
                CASE 
                    WHEN p.etat = 0 THEN 'قيد الانتظار'
                    WHEN p.etat = 1 THEN 'قيد المعالجة'
                    WHEN p.etat = 2 THEN 'مقبول'
                    WHEN p.etat = 3 THEN 'مرفوض'
                    ELSE 'غير محدد'
                END as etatLib
            FROM projet p
            LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
            LEFT JOIN gouvernorat g ON p.id_Gov = g.idGov
            LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
            LEFT JOIN user u ON p.idUser = u.idUser
            WHERE p.idPro = :projetId";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmt->execute();
    $projet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$projet) {
        echo json_encode(['success' => false, 'message' => 'المشروع غير موجود'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Récupérer les documents avec leurs types
    $sqlDocs = "SELECT 
                    idDoc, 
                    libDoc, 
                    cheminAcces, 
                    type,
                    CASE 
                        WHEN type = 1 THEN 'مقترح'
                        WHEN type = 11 THEN 'تقرير رقابي'
                        WHEN type = 12 THEN 'م.إدراج'
                        WHEN type = 13 THEN 'ت.ر.إدراج'
                        WHEN type = 14 THEN 'م.إسناد'
                        WHEN type = 15 THEN 'ت.ر.إسناد'
                        WHEN type = 16 THEN 'مراسلة'
                        WHEN type = 17 THEN 'أخرى'
                        ELSE 'غير محدد'
                    END as nom_type
                FROM document 
                WHERE idPro = :projetId
                ORDER BY type, idDoc";
    
    $stmtDocs = $db->prepare($sqlDocs);
    $stmtDocs->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtDocs->execute();
    $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les commissions
    $sqlCommissions = "SELECT c.numCommission, c.dateCommission
                       FROM projetcommission pc
                       INNER JOIN commission c ON pc.idCom = c.idCom
                       WHERE pc.idPro = :projetId
                       ORDER BY c.dateCommission DESC";
    
    $stmtCommissions = $db->prepare($sqlCommissions);
    $stmtCommissions->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtCommissions->execute();
    $commissions = $stmtCommissions->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les appels d'offre (si nécessaire)
    $sqlAppels = "SELECT a.idApp, a.dateCreation
                  FROM appeloffre a
                  WHERE a.idPro = :projetId
                  ORDER BY a.dateCreation DESC";
    
    $stmtAppels = $db->prepare($sqlAppels);
    $stmtAppels->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtAppels->execute();
    $appels = $stmtAppels->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'projet' => $projet,
        'documents' => $documents,
        'commissions' => $commissions,
        'appels' => $appels
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>