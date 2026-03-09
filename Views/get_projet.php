<?php
/**
 * get_projet.php
 * Endpoint AJAX — retourne les données d'un projet par son ID (pour le modal de modification)
 *
 * Paramètre GET :
 *   id = identifiant du projet (idPro)
 */

require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=UTF-8');

try {
    if (!isset($_GET['id']) || !intval($_GET['id'])) {
        echo json_encode(['success' => false, 'message' => 'معرف المشروع مفقود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projetId = intval($_GET['id']);

    $database = new Database();
    $db = $database->getConnection();

    // Récupérer les données du projet
    $sql = "SELECT p.*
            FROM projet p
            WHERE p.idPro = :projetId";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmt->execute();
    $projet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$projet) {
        echo json_encode(['success' => false, 'message' => 'المشروع غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Récupérer le document المقترح (type = 1)
    $sqlMuqtarah = "SELECT idDoc, libDoc, cheminAcces
                    FROM document
                    WHERE idPro = :projetId AND type = 1
                    LIMIT 1";
    $stmtMuqtarah = $db->prepare($sqlMuqtarah);
    $stmtMuqtarah->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtMuqtarah->execute();
    $docMuqtarah = $stmtMuqtarah->fetch(PDO::FETCH_ASSOC);

    // Récupérer le document التقرير الرقابي (type = 11)
    $sqlTaqrir = "SELECT idDoc, libDoc, cheminAcces
                  FROM document
                  WHERE idPro = :projetId AND type = 11
                  LIMIT 1";
    $stmtTaqrir = $db->prepare($sqlTaqrir);
    $stmtTaqrir->bindParam(':projetId', $projetId, PDO::PARAM_INT);
    $stmtTaqrir->execute();
    $docTaqrir = $stmtTaqrir->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'projet'      => $projet,
        'docMuqtarah' => $docMuqtarah ?: null,
        'docTaqrir'   => $docTaqrir   ?: null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}