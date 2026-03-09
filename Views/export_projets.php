<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

// Récupérer le format d'exportation
$format = isset($_GET['format']) ? $_GET['format'] : 'excel';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer TOUS les projets (sans pagination)
    $sql = "SELECT 
                p.idPro,
                p.proposition,
                p.cout,
                p.dateArrive,
                p.procedurePro,
                p.etat,
                m.libMinistere,
                g.libGov,
                e.libEtablissement,
                u.nomUser,
                CASE 
                    WHEN p.etat = 1 THEN 'بصدد الدرس'
                    WHEN p.etat = 11 OR p.etat = 21 THEN 'الإحالة على اللجنة'
                    WHEN p.etat = 30 THEN 'الموافقة'
                    ELSE 'غير معروف'
                END as etatLib
            FROM projet p
            LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
            LEFT JOIN gouvernorat g ON p.id_Gov = g.idGov
            LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
            LEFT JOIN user u ON p.idUser = u.idUser
            ORDER BY p.idPro DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $projets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Exporter selon le format demandé
    switch ($format) {
        case 'excel':
            exportToExcel($projets);
            break;
        case 'word':
            exportToWord($projets);
            break;
        case 'pdf':
            exportToPDF($projets);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Format non supporté'], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function exportToExcel($projets) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="قائمة_المشاريع_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    // Créer un fichier Excel avec PHP (sans bibliothèque externe)
    // Utiliser le format XML pour Excel
    
    $filename = tempnam(sys_get_temp_dir(), 'excel_');
    $handle = fopen($filename, 'w');
    
    // En-tête XML pour Excel
    fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
    fwrite($handle, '<?mso-application progid="Excel.Sheet"?>' . "\n");
    fwrite($handle, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
    fwrite($handle, ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n");
    fwrite($handle, ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n");
    fwrite($handle, ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
    fwrite($handle, ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n");
    
    // Styles
    fwrite($handle, '<Styles>' . "\n");
    fwrite($handle, '<Style ss:ID="Header">' . "\n");
    fwrite($handle, '<Font ss:Bold="1" ss:Size="12"/>' . "\n");
    fwrite($handle, '<Interior ss:Color="#4472C4" ss:Pattern="Solid"/>' . "\n");
    fwrite($handle, '<Font ss:Color="#FFFFFF"/>' . "\n");
    fwrite($handle, '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n");
    fwrite($handle, '</Style>' . "\n");
    fwrite($handle, '<Style ss:ID="Data">' . "\n");
    fwrite($handle, '<Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . "\n");
    fwrite($handle, '<Borders>' . "\n");
    fwrite($handle, '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n");
    fwrite($handle, '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n");
    fwrite($handle, '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n");
    fwrite($handle, '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n");
    fwrite($handle, '</Borders>' . "\n");
    fwrite($handle, '</Style>' . "\n");
    fwrite($handle, '</Styles>' . "\n");
    
    // Feuille de calcul
    fwrite($handle, '<Worksheet ss:Name="المشاريع">' . "\n");
    fwrite($handle, '<Table>' . "\n");
    
    // Largeurs de colonnes
    fwrite($handle, '<Column ss:Width="60"/>' . "\n");  // الرقم
    fwrite($handle, '<Column ss:Width="300"/>' . "\n"); // المقترح
    fwrite($handle, '<Column ss:Width="150"/>' . "\n"); // الوزارة
    fwrite($handle, '<Column ss:Width="150"/>' . "\n"); // المؤسسة
    fwrite($handle, '<Column ss:Width="100"/>' . "\n"); // الولاية
    fwrite($handle, '<Column ss:Width="120"/>' . "\n"); // الكلفة
    fwrite($handle, '<Column ss:Width="100"/>' . "\n"); // التاريخ
    fwrite($handle, '<Column ss:Width="120"/>' . "\n"); // الصيغة
    fwrite($handle, '<Column ss:Width="100"/>' . "\n"); // الحالة
    fwrite($handle, '<Column ss:Width="120"/>' . "\n"); // المقرر
    
    // En-tête
    fwrite($handle, '<Row>' . "\n");
    $headers = ['الرقم', 'المقترح', 'الوزارة', 'المؤسسة', 'الولاية', 'الكلفة التقديرية (م.د.ت)', 'تاريخ التعهد', 'صيغة المشروع', 'الحالة', 'المقرر'];
    foreach ($headers as $header) {
        fwrite($handle, '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($header, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
    }
    fwrite($handle, '</Row>' . "\n");
    
    // Données
    foreach ($projets as $projet) {
        fwrite($handle, '<Row>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="Number">' . $projet['idPro'] . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['proposition'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['libMinistere'] ?? 'غير محدد', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['libEtablissement'] ?? 'الوزارة', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['libGov'] ?? 'غير محدد', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="Number">' . number_format($projet['cout'], 2, '.', '') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['dateArrive'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['procedurePro'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['etatLib'], ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '<Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($projet['nomUser'] ?? 'غير محدد', ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n");
        fwrite($handle, '</Row>' . "\n");
    }
    
    fwrite($handle, '</Table>' . "\n");
    fwrite($handle, '</Worksheet>' . "\n");
    fwrite($handle, '</Workbook>' . "\n");
    
    fclose($handle);
    
    // Envoyer le fichier
    readfile($filename);
    unlink($filename);
    exit();
}

function exportToWord($projets) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="قائمة_المشاريع_' . date('Y-m-d') . '.doc"');
    header('Cache-Control: max-age=0');
    
    // Générer un document Word en HTML compatible
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><style>';
    echo 'body { font-family: Arial, sans-serif; direction: rtl; }';
    echo 'h1 { color: #2c3e50; text-align: center; margin-bottom: 20px; }';
    echo 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    echo 'th { background-color: #4472C4; color: white; padding: 12px; text-align: center; border: 1px solid #ddd; }';
    echo 'td { padding: 10px; border: 1px solid #ddd; text-align: right; }';
    echo 'tr:nth-child(even) { background-color: #f2f2f2; }';
    echo '.header-row { page-break-inside: avoid; }';
    echo '@page { margin: 1cm; }';
    echo '</style></head><body>';
    
    echo '<h1>قائمة المشاريع الكبرى</h1>';
    echo '<p style="text-align: center; color: #666;">تاريخ التصدير: ' . date('Y-m-d H:i:s') . '</p>';
    echo '<p style="text-align: center; color: #666;">عدد المشاريع: ' . count($projets) . '</p>';
    
    echo '<table>';
    echo '<thead class="header-row">';
    echo '<tr>';
    echo '<th>الرقم</th>';
    echo '<th>المقترح</th>';
    echo '<th>الوزارة</th>';
    echo '<th>المؤسسة</th>';
    echo '<th>الولاية</th>';
    echo '<th>الكلفة التقديرية (م.د.ت)</th>';
    echo '<th>تاريخ التعهد</th>';
    echo '<th>صيغة المشروع</th>';
    echo '<th>الحالة</th>';
    echo '<th>المقرر</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($projets as $projet) {
        echo '<tr>';
        echo '<td style="text-align: center;">' . $projet['idPro'] . '</td>';
        echo '<td>' . htmlspecialchars($projet['proposition']) . '</td>';
        echo '<td>' . htmlspecialchars($projet['libMinistere'] ?? 'غير محدد') . '</td>';
        echo '<td>' . htmlspecialchars($projet['libEtablissement'] ?? 'الوزارة') . '</td>';
        echo '<td>' . htmlspecialchars($projet['libGov'] ?? 'غير محدد') . '</td>';
        echo '<td style="text-align: left;">' . number_format($projet['cout'], 2, '.', ' ') . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars($projet['dateArrive']) . '</td>';
        echo '<td>' . htmlspecialchars($projet['procedurePro']) . '</td>';
        echo '<td style="text-align: center;">' . htmlspecialchars($projet['etatLib']) . '</td>';
        echo '<td>' . htmlspecialchars($projet['nomUser'] ?? 'غير محدد') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</body></html>';
    exit();
}

function exportToPDF($projets) {
    // Pour le PDF, on va générer un HTML puis le convertir
    // On utilise la même approche que Word mais avec des styles optimisés pour PDF
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="قائمة_المشاريع_' . date('Y-m-d') . '.pdf"');
    header('Cache-Control: max-age=0');
    
    // Créer un fichier HTML temporaire
    $htmlContent = '<html><head><meta charset="UTF-8"><style>';
    $htmlContent .= 'body { font-family: Arial, sans-serif; direction: rtl; font-size: 10pt; }';
    $htmlContent .= 'h1 { color: #2c3e50; text-align: center; margin-bottom: 10px; font-size: 18pt; }';
    $htmlContent .= 'table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8pt; }';
    $htmlContent .= 'th { background-color: #4472C4; color: white; padding: 8px; text-align: center; border: 1px solid #ddd; font-size: 9pt; }';
    $htmlContent .= 'td { padding: 6px; border: 1px solid #ddd; text-align: right; }';
    $htmlContent .= 'tr:nth-child(even) { background-color: #f9f9f9; }';
    $htmlContent .= '@page { margin: 1.5cm; size: A4 landscape; }';
    $htmlContent .= '.info { text-align: center; color: #666; font-size: 9pt; margin: 5px 0; }';
    $htmlContent .= '</style></head><body>';
    
    $htmlContent .= '<h1>قائمة المشاريع الكبرى</h1>';
    $htmlContent .= '<p class="info">تاريخ التصدير: ' . date('Y-m-d H:i:s') . ' | عدد المشاريع: ' . count($projets) . '</p>';
    
    $htmlContent .= '<table>';
    $htmlContent .= '<thead><tr>';
    $htmlContent .= '<th style="width: 5%;">الرقم</th>';
    $htmlContent .= '<th style="width: 25%;">المقترح</th>';
    $htmlContent .= '<th style="width: 12%;">الوزارة</th>';
    $htmlContent .= '<th style="width: 12%;">المؤسسة</th>';
    $htmlContent .= '<th style="width: 8%;">الولاية</th>';
    $htmlContent .= '<th style="width: 10%;">الكلفة (م.د.ت)</th>';
    $htmlContent .= '<th style="width: 8%;">التاريخ</th>';
    $htmlContent .= '<th style="width: 10%;">الصيغة</th>';
    $htmlContent .= '<th style="width: 7%;">الحالة</th>';
    $htmlContent .= '<th style="width: 10%;">المقرر</th>';
    $htmlContent .= '</tr></thead><tbody>';
    
    foreach ($projets as $projet) {
        $htmlContent .= '<tr>';
        $htmlContent .= '<td style="text-align: center;">' . $projet['idPro'] . '</td>';
        $htmlContent .= '<td>' . htmlspecialchars($projet['proposition']) . '</td>';
        $htmlContent .= '<td>' . htmlspecialchars($projet['libMinistere'] ?? 'غ.م') . '</td>';
        $htmlContent .= '<td>' . htmlspecialchars($projet['libEtablissement'] ?? 'الوزارة') . '</td>';
        $htmlContent .= '<td>' . htmlspecialchars($projet['libGov'] ?? 'غ.م') . '</td>';
        $htmlContent .= '<td style="text-align: left;">' . number_format($projet['cout'], 0, '.', ' ') . '</td>';
        $htmlContent .= '<td style="text-align: center;">' . htmlspecialchars($projet['dateArrive']) . '</td>';
        $htmlContent .= '<td>' . htmlspecialchars($projet['procedurePro']) . '</td>';
        $htmlContent .= '<td style="text-align: center; font-size: 7pt;">' . htmlspecialchars($projet['etatLib']) . '</td>';
        $htmlContent .= '<td>' . htmlspecialchars($projet['nomUser'] ?? 'غ.م') . '</td>';
        $htmlContent .= '</tr>';
    }
    
    $htmlContent .= '</tbody></table></body></html>';
    
    // Sauvegarder le fichier HTML temporaire
    $tempHtml = tempnam(sys_get_temp_dir(), 'html_');
    file_put_contents($tempHtml, $htmlContent);
    
    // Convertir en PDF avec wkhtmltopdf (si disponible)
    $tempPdf = tempnam(sys_get_temp_dir(), 'pdf_');
    
    // Essayer wkhtmltopdf
    $command = "wkhtmltopdf --orientation Landscape --page-size A4 --encoding UTF-8 " . escapeshellarg($tempHtml) . " " . escapeshellarg($tempPdf) . " 2>&1";
    exec($command, $output, $returnVar);
    
    if ($returnVar === 0 && file_exists($tempPdf) && filesize($tempPdf) > 0) {
        readfile($tempPdf);
        unlink($tempHtml);
        unlink($tempPdf);
    } else {
        // Si wkhtmltopdf n'est pas disponible, retourner le HTML
        unlink($tempHtml);
        header('Content-Type: text/html; charset=UTF-8');
        echo $htmlContent;
        echo '<script>window.print();</script>';
    }
    
    exit();
}
?>