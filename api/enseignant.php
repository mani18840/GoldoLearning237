<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'enseignant') {
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

$user_id = $_SESSION['user_id'];

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (empty($contentType) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    }

    if (strpos($contentType, 'application/json') !== false) {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = []; // évite le null si le JSON est vide/invalide
        $action = $data['action'] ?? '';
    } else {
        $data   = $_POST;
        $action = $_POST['action'] ?? '';
    }
}

switch ($action) {
    case 'stats':           getStats($pdo, $user_id);             break;
    case 'modules':         getModules($pdo);                     break;
    case 'mes_cours':       getMesCours($pdo, $user_id);          break;
    case 'creer_cours':     creerCours($pdo, $user_id, $data);    break;
    case 'supprimer_cours': supprimerCours($pdo, $user_id, $data);break;
    case 'lecons':          getLecons($pdo, $user_id);            break;
    case 'ajouter_lecon':   ajouterLecon($pdo, $user_id, $data);  break;
    default: echo json_encode(['success' => false, 'message' => 'Action inconnue']);
}

function getStats($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cours WHERE id_enseignant = ?");
    $stmt->execute([$user_id]);
    $nbCours = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lecons l JOIN cours c ON l.id_cours = c.id WHERE c.id_enseignant = ?");
    $stmt->execute([$user_id]);
    $nbLecons = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT i.id_etudiant) FROM inscriptions i JOIN cours c ON i.id_cours = c.id WHERE c.id_enseignant = ?");
    $stmt->execute([$user_id]);
    $nbEtudiants = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM evaluations e JOIN lecons l ON e.id_lecon = l.id JOIN cours c ON l.id_cours = c.id WHERE c.id_enseignant = ?");
    $stmt->execute([$user_id]);
    $nbEvals = $stmt->fetchColumn();

    echo json_encode(['success' => true, 'stats' => [
        'cours' => (int)$nbCours, 'lecons' => (int)$nbLecons,
        'etudiants' => (int)$nbEtudiants, 'evals' => (int)$nbEvals
    ]]);
}

function getModules($pdo) {
    $stmt = $pdo->query("SELECT id, titre FROM modules ORDER BY titre");
    echo json_encode(['success' => true, 'modules' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getMesCours($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.titre, c.description, c.date_creation,
               m.titre AS module_titre,
               (SELECT COUNT(*) FROM lecons l WHERE l.id_cours = c.id) AS nb_lecons,
               (SELECT COUNT(*) FROM inscriptions i WHERE i.id_cours = c.id) AS nb_etudiants
        FROM cours c
        LEFT JOIN modules m ON c.id_module = m.id
        WHERE c.id_enseignant = ?
        ORDER BY c.date_creation DESC
    ");
    $stmt->execute([$user_id]);
    echo json_encode(['success' => true, 'cours' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function creerCours($pdo, $user_id, $data) {
    $titre       = trim($data['titre'] ?? '');
    $description = trim($data['description'] ?? '');
    $id_module   = $data['id_module'] ?? null;

    if (empty($titre))       { echo json_encode(['success'=>false,'message'=>'Titre requis']); return; }
    if (empty($description)) { echo json_encode(['success'=>false,'message'=>'Description requise']); return; }

    $stmt = $pdo->prepare("INSERT INTO cours (titre, description, id_enseignant, id_module) VALUES (?,?,?,?)");
    $stmt->execute([$titre, $description, $user_id, $id_module ?: null]);

    echo json_encode(['success' => true, 'message' => 'Cours créé', 'cours_id' => (int)$pdo->lastInsertId()]);
}

function supprimerCours($pdo, $user_id, $data) {
    $cours_id = (int)($data['cours_id'] ?? 0);
    if (!$cours_id) { echo json_encode(['success'=>false,'message'=>'ID manquant']); return; }

    $stmt = $pdo->prepare("SELECT id FROM cours WHERE id = ? AND id_enseignant = ?");
    $stmt->execute([$cours_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Accès refusé']); return; }

    $pdo->prepare("DELETE r FROM reponses r JOIN questions q ON r.id_question=q.id JOIN evaluations e ON q.id_evaluation=e.id JOIN lecons l ON e.id_lecon=l.id WHERE l.id_cours=?")->execute([$cours_id]);
    $pdo->prepare("DELETE q FROM questions q JOIN evaluations e ON q.id_evaluation=e.id JOIN lecons l ON e.id_lecon=l.id WHERE l.id_cours=?")->execute([$cours_id]);
    $pdo->prepare("DELETE e FROM evaluations e JOIN lecons l ON e.id_lecon=l.id WHERE l.id_cours=?")->execute([$cours_id]);
    $pdo->prepare("DELETE FROM lecons WHERE id_cours=?")->execute([$cours_id]);
    $pdo->prepare("DELETE FROM inscriptions WHERE id_cours=?")->execute([$cours_id]);
    $pdo->prepare("DELETE FROM progression WHERE id_cours=?")->execute([$cours_id]);
    $pdo->prepare("DELETE FROM cours WHERE id=?")->execute([$cours_id]);

    echo json_encode(['success' => true, 'message' => 'Cours supprimé']);
}

function getLecons($pdo, $user_id) {
    $cours_id = (int)($_GET['cours_id'] ?? 0);
    if (!$cours_id) { echo json_encode(['success'=>false,'message'=>'ID cours manquant']); return; }

    $stmt = $pdo->prepare("SELECT id FROM cours WHERE id = ? AND id_enseignant = ?");
    $stmt->execute([$cours_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Accès refusé']); return; }

    $stmt = $pdo->prepare("
        SELECT l.id, l.titre, l.type, l.fichier_url, l.ordre,
               (SELECT COUNT(*) FROM evaluations e WHERE e.id_lecon = l.id) AS a_evaluation
        FROM lecons l WHERE l.id_cours = ? ORDER BY l.ordre ASC
    ");
    $stmt->execute([$cours_id]);
    echo json_encode(['success' => true, 'lecons' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function ajouterLecon($pdo, $user_id, $data) {
    $cours_id = (int)($data['cours_id'] ?? 0);
    $titre    = trim($data['titre'] ?? '');
    $type     = $data['type'] ?? '';
    $ordre    = (int)($data['ordre'] ?? 1);

    if (!$cours_id || empty($titre) || !in_array($type, ['pdf','video'])) {
        echo json_encode(['success'=>false,'message'=>'Données incomplètes']); return;
    }

    $stmt = $pdo->prepare("SELECT id FROM cours WHERE id = ? AND id_enseignant = ?");
    $stmt->execute([$cours_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Accès refusé']); return; }

    $fichier_url = '';

    if ($type === 'pdf') {
        if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'message'=>'Fichier PDF manquant']); return;
        }
        $file = $_FILES['fichier'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') { echo json_encode(['success'=>false,'message'=>'Seuls les PDF sont acceptés']); return; }

        $uploadDir = __DIR__ . '/../assets/uploads/pdf/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $nomFichier  = uniqid('lecon_') . '_' . time() . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $nomFichier)) {
            echo json_encode(['success'=>false,'message'=>'Erreur enregistrement fichier']); return;
        }
        $fichier_url = 'assets/uploads/pdf/' . $nomFichier;
    } else {
        $fichier_url = trim($data['fichier_url'] ?? '');
        if (empty($fichier_url)) { echo json_encode(['success'=>false,'message'=>'URL vidéo manquante']); return; }
    }

    $stmt = $pdo->prepare("INSERT INTO lecons (titre, type, fichier_url, ordre, id_cours) VALUES (?,?,?,?,?)");
    $stmt->execute([$titre, $type, $fichier_url, $ordre, $cours_id]);

    echo json_encode(['success'=>true,'message'=>'Leçon ajoutée','lecon_id'=>(int)$pdo->lastInsertId()]);
}