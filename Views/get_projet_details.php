<?php
// get_projet_details.php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'معرف المشروع مفقود']);
    exit();
}

$projetId = intval($_GET['id']);

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les informations du projet
    $sql = "SELECT p.*, 
            m.libMinistere, 
            e.libEtablissement,
            g.libGov,
            u.nomUser,
            CASE 
                WHEN p.etat = 0 THEN 'بصدد الدرس'
                WHEN p.etat = 1 THEN 'الإحالة على اللجنة'
                WHEN p.etat = 2 THEN 'الموافقة'
                WHEN p.etat = 3 THEN 'عدم الموافقة'
                ELSE 'غير معروف'
            END as etatLib
            FROM projet p
            LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
            LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
            LEFT JOIN gouvernorat g ON p.id_Gov = g.idGov
            LEFT JOIN user u ON p.idUser = u.idUser
            WHERE p.idPro = :projetId";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmt->execute();
    
    $projet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$projet) {
        echo json_encode(['success' => false, 'message' => 'المشروع غير موجود']);
        exit();
    }
    
    // Récupérer tous les documents du projet
    $sqlDocs = "SELECT idDoc, libDoc, cheminAcces, type,
                CASE 
                    WHEN type = 1 THEN 'المقترح'
                    WHEN type = 11 THEN 'التقرير الرقابي'
                    WHEN type = 20 THEN 'مراسلة'
                    WHEN type = 21 THEN 'اخرى'
                    ELSE 'غير محدد'
                END as typeLib
                FROM document 
                WHERE idPro = :projetId 
                ORDER BY type, idDoc DESC";
    
    $stmtDocs = $db->prepare($sqlDocs);
    $stmtDocs->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtDocs->execute();
    
    $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'projet' => $projet,
        'documents' => $documents
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات'], JSON_UNESCAPED_UNICODE);
}
?>