<?php
/**
 * Script pour créer le dossier d'upload des appels d'offres
 * À exécuter une seule fois
 */

$uploadDir = __DIR__ . '/../uploads/appels_offres/';

if (!is_dir($uploadDir)) {
    if (mkdir($uploadDir, 0755, true)) {
        echo "✓ Dossier créé avec succès: $uploadDir\n";
        echo "✓ Permissions définies: 0755\n";
    } else {
        echo "✗ Erreur lors de la création du dossier\n";
    }
} else {
    echo "ℹ Le dossier existe déjà: $uploadDir\n";
}

// Créer un fichier .htaccess pour sécuriser le dossier
$htaccessContent = "# Empêcher l'exécution de scripts PHP
<Files *.php>
    deny from all
</Files>

# Autoriser uniquement certains types de fichiers
<FilesMatch \"\\.(pdf|doc|docx|xls|xlsx)$\">
    Order Allow,Deny
    Allow from all
</FilesMatch>
";

$htaccessFile = $uploadDir . '.htaccess';
if (file_put_contents($htaccessFile, $htaccessContent)) {
    echo "✓ Fichier .htaccess créé pour sécuriser le dossier\n";
} else {
    echo "✗ Erreur lors de la création du .htaccess\n";
}

echo "\nTerminé!\n";
?>