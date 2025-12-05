<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';
require_once '../Config/Permissions.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'معرف المشروع مفقود'], JSON_UNESCAPED_UNICODE);
    exit();
}

$projetId = intval($_GET['id']);

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer le projet
    $sql = "SELECT * FROM projet WHERE idPro = :projetId";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmt->execute();
    
    $projet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$projet) {
        echo json_encode(['success' => false, 'message' => 'المشروع غير موجود'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Vérifier les permissions
    if (!Permissions::canEditProjet($projet['idUser'])) {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل هذا المقترح'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Récupérer les documents
    $sqlDoc = "SELECT * FROM document WHERE idPro = :projetId AND type = 1";
    $stmtDoc = $db->prepare($sqlDoc);
    $stmtDoc->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtDoc->execute();
    $docMuqtarah = $stmtDoc->fetch(PDO::FETCH_ASSOC);
    
    $sqlTaqrir = "SELECT * FROM document WHERE idPro = :projetId AND type = 11";
    $stmtTaqrir = $db->prepare($sqlTaqrir);
    $stmtTaqrir->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtTaqrir->execute();
    $docTaqrir = $stmtTaqrir->fetch(PDO::FETCH_ASSOC);

    // Dans get_projet.php, la requête SQL doit inclure id_Gov
    $sql = "SELECT p.*, m.libMinistere, e.libEtablissement, g.libGov
    FROM projet p
    LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
    LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
    LEFT JOIN gouvernorat g ON p.id_Gov = g.idGov
    WHERE p.idPro = :id";
    
    echo json_encode([
        'success' => true,
        'projet' => $projet,
        'docMuqtarah' => $docMuqtarah,
        'docTaqrir' => $docTaqrir
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

?>