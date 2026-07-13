<?php
/* ===== API/UTILS/CERTIFICAT_GENERATOR.PHP ===== */
/* Génère un certificat PDF de fin de cours avec FPDF.
   Installation requise (une seule fois, à la racine du projet) :
       composer require setasign/fpdf
   Puis un require de vendor/autoload.php ci-dessous.

   Utilisation :
       require_once __DIR__ . '/certificat_generator.php';
       $chemin = genererCertificatPdf($nomEtudiant, $matricule, $titreCours, $dateObtention);
       // $chemin est le chemin relatif à stocker dans certificats.url_pdf
*/

require_once __DIR__ . "/../../vendor/autoload.php";

use Fpdf\Fpdf;
// Si le namespace de votre version diffère, remplacez par : require '../../fpdf/fpdf.php'; et utilisez FPDF directement.

function genererCertificatPdf($nomEtudiant, $matricule, $titreCours, $dateObtention) {
    $dossierSortie = __DIR__ . "/../../assets/certificats/";
    if (!is_dir($dossierSortie)) {
        mkdir($dossierSortie, 0755, true);
    }

    $nomFichier = "cert_" . preg_replace("/[^a-zA-Z0-9]/", "_", $matricule) . "_" . time() . ".pdf";
    $cheminComplet = $dossierSortie . $nomFichier;

    $pdf = new Fpdf("L", "mm", "A4"); // Paysage
    $pdf->AddPage();

    // Couleurs de la charte GoldoLearning237 (violet)
    $violetR = 124; $violetG = 58; $violetB = 237;
    $noirR = 26; $noirG = 26; $noirB = 46;

    // Bordure décorative
    $pdf->SetDrawColor($violetR, $violetG, $violetB);
    $pdf->SetLineWidth(1.2);
    $pdf->Rect(10, 10, 277, 190);
    $pdf->SetLineWidth(0.4);
    $pdf->Rect(14, 14, 269, 182);

    // Logo / nom plateforme
    $pdf->SetFont("Helvetica", "B", 22);
    $pdf->SetTextColor($violetR, $violetG, $violetB);
    $pdf->SetY(30);
    $pdf->Cell(0, 12, utf8_decode("GoldoLearning237"), 0, 1, "C");

    // Titre du certificat
    $pdf->SetFont("Helvetica", "B", 30);
    $pdf->SetTextColor($noirR, $noirG, $noirB);
    $pdf->SetY(55);
    $pdf->Cell(0, 16, utf8_decode("CERTIFICAT DE RÉUSSITE"), 0, 1, "C");

    // Sous-texte
    $pdf->SetFont("Helvetica", "", 14);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetY(78);
    $pdf->Cell(0, 10, utf8_decode("Ce certificat est décerné à"), 0, 1, "C");

    // Nom de l'étudiant
    $pdf->SetFont("Helvetica", "B", 26);
    $pdf->SetTextColor($violetR, $violetG, $violetB);
    $pdf->SetY(92);
    $pdf->Cell(0, 16, utf8_decode($nomEtudiant), 0, 1, "C");

    // Matricule
    $pdf->SetFont("Helvetica", "I", 11);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->SetY(109);
    $pdf->Cell(0, 8, utf8_decode("Matricule : " . $matricule), 0, 1, "C");

    // Texte cours
    $pdf->SetFont("Helvetica", "", 14);
    $pdf->SetTextColor($noirR, $noirG, $noirB);
    $pdf->SetY(122);
    $pdf->Cell(0, 10, utf8_decode("pour avoir complété avec succès le cours"), 0, 1, "C");

    $pdf->SetFont("Helvetica", "B", 18);
    $pdf->SetTextColor($violetR, $violetG, $violetB);
    $pdf->SetY(134);
    $pdf->Cell(0, 12, utf8_decode($titreCours), 0, 1, "C");

    // Date
    $pdf->SetFont("Helvetica", "", 12);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetY(155);
    $dateFormatee = date("d/m/Y", strtotime($dateObtention));
    $pdf->Cell(0, 8, utf8_decode("Délivré le " . $dateFormatee), 0, 1, "C");

    // Signature (ligne + libellé)
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(110, 178, 187, 178);
    $pdf->SetFont("Helvetica", "I", 10);
    $pdf->SetY(180);
    $pdf->Cell(0, 6, utf8_decode("GoldoLearning237 - Plateforme de formation en ligne"), 0, 1, "C");

    $pdf->Output("F", $cheminComplet);

    // Retourne le chemin relatif à stocker en base
    return "assets/certificats/" . $nomFichier;
}