<?php
/**
 * Script pour créer le dossier uploads
 * À exécuter une seule fois
 */

// Créer le dossier uploads
$uploadDir = __DIR__ . '/uploads/documents/';

if (!file_exists($uploadDir)) {
    if (mkdir($uploadDir, 0777, true)) {
        echo "✓ Dossier créé avec succès: {$uploadDir}\n";
        
        // Créer un fichier .htaccess pour sécuriser les fichiers
        $htaccess = $uploadDir . '../.htaccess';
        $htaccessContent = "# Protéger les fichiers sensibles\n";
        $htaccessContent .= "<Files *.php>\n";
        $htaccessContent .= "    deny from all\n";
        $htaccessContent .= "</Files>\n";
        
        file_put_contents($htaccess, $htaccessContent);
        echo "✓ Fichier .htaccess créé\n";
        
        // Créer un index.php vide pour empêcher le listing
        $index = $uploadDir . 'index.php';
        file_put_contents($index, '<?php // Accès interdit ?>');
        echo "✓ Fichier index.php créé\n";
        
        echo "\n✅ Configuration des uploads terminée avec succès!\n";
    } else {
        echo "✗ Erreur lors de la création du dossier\n";
    }
} else {
    echo "✓ Le dossier existe déjà: {$uploadDir}\n";
}
?>