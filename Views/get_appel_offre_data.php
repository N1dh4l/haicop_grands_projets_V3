<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$idApp = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($idApp <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرف غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Infos appel d'offre + projet + ministère + établissement
    $sql = "SELECT ao.idApp, ao.dateCreation,
                   p.sujet,
                   m.libMinistere,
                   e.libEtablissement
            FROM appeloffre ao
            INNER JOIN projet p      ON ao.idPro = p.idPro
            LEFT JOIN  ministere m   ON p.idMinistere = m.idMinistere
            LEFT JOIN  etablissement e ON p.idEtab = e.idEtablissement
            WHERE ao.idApp = :idApp";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':idApp', $idApp, PDO::PARAM_INT);
    $stmt->execute();
    $appel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appel) {
        echo json_encode(['success' => false, 'message' => 'الصفقة غير موجودة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Document associé
    $sqlDoc = "SELECT idDoc, libDoc, cheminAcces
               FROM document
               WHERE idExterne = :idApp AND type = 30
               LIMIT 1";
    $stmtDoc = $db->prepare($sqlDoc);
    $stmtDoc->bindParam(':idApp', $idApp, PDO::PARAM_INT);
    $stmtDoc->execute();
    $document = $stmtDoc->fetch(PDO::FETCH_ASSOC);

    // Lots avec idFournisseur
    $sqlLots = "SELECT l.lidLot, l.sujetLot, l.somme, l.idFournisseur, f.nomFour
                FROM lot l
                INNER JOIN fournisseur f ON f.idFour = l.idFournisseur
                WHERE l.idAppelOffre = :idApp
                ORDER BY l.lidLot";
    $stmtLots = $db->prepare($sqlLots);
    $stmtLots->bindParam(':idApp', $idApp, PDO::PARAM_INT);
    $stmtLots->execute();
    $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

    // Projets disponibles
    $sqlProjets = "SELECT DISTINCT p.idPro, p.sujet
                   FROM projet p
                   INNER JOIN projetcommission pc ON p.idPro = pc.idPro
                   ORDER BY p.sujet";
    $stmtProjets = $db->prepare($sqlProjets);
    $stmtProjets->execute();
    $projets = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);

    // Fournisseurs disponibles
    $sqlFournisseurs = "SELECT idFour as idFournisseur, nomFour as nomFournisseur FROM fournisseur ORDER BY nomFour";
    $stmtFournisseurs = $db->prepare($sqlFournisseurs);
    $stmtFournisseurs->execute();
    $fournisseurs = $stmtFournisseurs->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer idPro de l'appel d'offre
    $sqlIdPro = "SELECT idPro FROM appeloffre WHERE idApp = :idApp";
    $stmtIdPro = $db->prepare($sqlIdPro);
    $stmtIdPro->bindParam(':idApp', $idApp, PDO::PARAM_INT);
    $stmtIdPro->execute();
    $projetData = $stmtIdPro->fetch(PDO::FETCH_ASSOC);

    // Construire appelOffre au format attendu par renderEditForm
    $appelOffre = [
        'idApp'        => $appel['idApp'],
        'idPro'        => $projetData ? $projetData['idPro'] : null,
        'dateCreation' => $appel['dateCreation'],
        'sujet'        => $appel['sujet'],
        'documentPath' => $document ? $document['cheminAcces'] : null,
        'documentId'   => $document ? $document['idDoc'] : null,
        'documentLib'  => $document ? $document['libDoc'] : null,
    ];

    echo json_encode([
        'success'     => true,
        'appelOffre'  => $appelOffre,
        'lots'        => $lots,
        'projets'     => $projets,
        'fournisseurs'=> $fournisseurs,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>