<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$config = require_once 'config.php';

function isAuthenticated($config) {
    return isset($_COOKIE[$config['cookie_name']]) && $_COOKIE[$config['cookie_name']] === 'authenticated';
}

function authenticate($config, $code) {
    if ($code === $config['auth_code']) {
        setcookie($config['cookie_name'], 'authenticated', time() + $config['cookie_duration'], '/');
        return true;
    }
    return false;
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function getReports($config, $year = null, $search = null) {
    $reports = [];
    $dataPath = $config['data_path'];
    
    if ($year) {
        $yearPath = $dataPath . '/' . $year;
        if (is_dir($yearPath)) {
            $files = glob($yearPath . '/*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $data['uuid'] = basename($file, '.json');
                    $reports[] = $data;
                }
            }
        }
    } else {
        // Carica tutti gli anni
        $years = glob($dataPath . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
        foreach ($years as $yearDir) {
            $files = glob($yearDir . '/*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $data['uuid'] = basename($file, '.json');
                    $reports[] = $data;
                }
            }
        }
    }
    
    // Filtro per ricerca
    if ($search) {
        $search = strtolower($search);
        $reports = array_filter($reports, function($report) use ($search) {
            $searchIn = strtolower(json_encode($report));
            return strpos($searchIn, $search) !== false;
        });
    }
    
    // Ordina per data
    usort($reports, function($a, $b) {
        return strcmp($b['data_esame'], $a['data_esame']);
    });
    
    return $reports;
}

function saveReport($config, $reportData, $uuid) {
    $year = date('Y', strtotime($reportData['data_esame']));
    $yearPath = $config['data_path'] . '/' . $year;
    
    if (!is_dir($yearPath)) {
        mkdir($yearPath, 0755, true);
    }
    
    $filePath = $yearPath . '/' . $uuid . '.json';
    file_put_contents($filePath, json_encode($reportData, JSON_PRETTY_PRINT));
    
    // Aggiorna search.json
    updateSearchIndex($config, $reportData['tags'], $uuid);
    
    return true;
}

function updateSearchIndex($config, $tags, $uuid) {
    $searchFile = $config['data_path'] . '/search.json';
    $searchData = [];
    
    if (file_exists($searchFile)) {
        $searchData = json_decode(file_get_contents($searchFile), true) ?: [];
    }
    
    foreach ($tags as $tag) {
        $tag = strtolower(trim($tag));
        if (!isset($searchData[$tag])) {
            $searchData[$tag] = [];
        }
        if (!in_array($uuid, $searchData[$tag])) {
            $searchData[$tag][] = $uuid;
        }
    }
    
    file_put_contents($searchFile, json_encode($searchData, JSON_PRETTY_PRINT));
}

function deleteReport($config, $uuid) {
    $found = false;
    $dataPath = $config['data_path'];
    
    // Cerca il file in tutte le cartelle anno
    $years = glob($dataPath . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
    foreach ($years as $yearDir) {
        $filePath = $yearDir . '/' . $uuid . '.json';
        if (file_exists($filePath)) {
            // Leggi i dati del referto prima di eliminarlo per aggiornare l'indice
            $reportData = json_decode(file_get_contents($filePath), true);
            
            // Elimina il file JSON
            if (unlink($filePath)) {
                $found = true;
                
                // Rimuovi dall'indice di ricerca
                if ($reportData && isset($reportData['tags'])) {
                    removeFromSearchIndex($config, $reportData['tags'], $uuid);
                }
                
                // Elimina la cartella uploads se esiste
                $uploadDir = $dataPath . '/uploads/' . $uuid;
                if (is_dir($uploadDir)) {
                    deleteDirectory($uploadDir);
                }
                
                break;
            }
        }
    }
    
    return $found;
}

function removeFromSearchIndex($config, $tags, $uuid) {
    $searchFile = $config['data_path'] . '/search.json';
    $searchData = [];
    
    if (file_exists($searchFile)) {
        $searchData = json_decode(file_get_contents($searchFile), true) ?: [];
    }
    
    foreach ($tags as $tag) {
        $tag = strtolower(trim($tag));
        if (isset($searchData[$tag])) {
            $searchData[$tag] = array_filter($searchData[$tag], function($id) use ($uuid) {
                return $id !== $uuid;
            });
            
            // Rimuovi il tag se non ha più referti associati
            if (empty($searchData[$tag])) {
                unset($searchData[$tag]);
            } else {
                // Reindex array
                $searchData[$tag] = array_values($searchData[$tag]);
            }
        }
    }
    
    file_put_contents($searchFile, json_encode($searchData, JSON_PRETTY_PRINT));
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $filePath = $dir . '/' . $file;
        if (is_dir($filePath)) {
            deleteDirectory($filePath);
        } else {
            unlink($filePath);
        }
    }
    
    return rmdir($dir);
}

function analyzeWithOpenAI($config, $extractedText) {
    $apiKey = $config['openai_api_key'];
    
    $prompt = "Analizza il seguente testo di un referto medico ed estrai le seguenti informazioni in formato JSON:
- titolo: il titolo del referto
- data_esame: data dell'esame in formato YYYY-MM-DD
- nome_medico: nome del medico
- tags: array di tag per la ricerca (parole chiave significative)
- descrizione: breve descrizione del referto
- esiti: array di oggetti con struttura {nome: string, valore_numerico: number|null, valore_stringa: string|null, valore_booleano: boolean} dove valore_booleano è true se OK, false se anomalo

Testo del referto:
$extractedText

Rispondi SOLO con il JSON, senza altri testi:";

    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 1500,
        'temperature' => 0.3
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            $content = trim($result['choices'][0]['message']['content']);
            // Rimuovi eventuali backticks del markdown
            $content = preg_replace('/^```json\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            return json_decode($content, true);
        }
    }
    
    return null;
}

function getReport($config, $uuid) {
    $dataPath = $config['data_path'];
    
    // Cerca il file in tutte le cartelle anno
    $years = glob($dataPath . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
    foreach ($years as $yearDir) {
        $filePath = $yearDir . '/' . $uuid . '.json';
        if (file_exists($filePath)) {
            $data = json_decode(file_get_contents($filePath), true);
            if ($data) {
                $data['uuid'] = $uuid;
                return $data;
            }
        }
    }
    
    return null;
}

function updateReport($config, $reportData, $uuid) {
    $dataPath = $config['data_path'];
    
    // Prima trova il file esistente
    $existingFilePath = null;
    $existingData = null;
    $years = glob($dataPath . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
    foreach ($years as $yearDir) {
        $filePath = $yearDir . '/' . $uuid . '.json';
        if (file_exists($filePath)) {
            $existingFilePath = $filePath;
            $existingData = json_decode(file_get_contents($filePath), true);
            break;
        }
    }
    
    if (!$existingFilePath) {
        return false;
    }
    
    // Verifica se la data dell'esame è cambiata (e quindi serve spostare il file)
    $oldYear = date('Y', strtotime($existingData['data_esame']));
    $newYear = date('Y', strtotime($reportData['data_esame']));
    
    if ($oldYear !== $newYear) {
        // Sposta il file nella cartella dell'anno corretto
        $newYearPath = $dataPath . '/' . $newYear;
        if (!is_dir($newYearPath)) {
            mkdir($newYearPath, 0755, true);
        }
        
        $newFilePath = $newYearPath . '/' . $uuid . '.json';
        
        // Elimina il vecchio file
        unlink($existingFilePath);
        
        // Salva nel nuovo percorso
        file_put_contents($newFilePath, json_encode($reportData, JSON_PRETTY_PRINT));
    } else {
        // Aggiorna il file esistente
        file_put_contents($existingFilePath, json_encode($reportData, JSON_PRETTY_PRINT));
    }
    
    // Aggiorna l'indice di ricerca
    // Prima rimuovi i tag vecchi
    if (isset($existingData['tags'])) {
        removeFromSearchIndex($config, $existingData['tags'], $uuid);
    }
    
    // Poi aggiungi i nuovi tag
    if (isset($reportData['tags'])) {
        updateSearchIndex($config, $reportData['tags'], $uuid);
    }
    
    return true;
}

// Routing delle API
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $code = $_POST['code'] ?? '';
        if (authenticate($config, $code)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Codice non valido']);
        }
        break;
        
    case 'check_auth':
        echo json_encode(['authenticated' => isAuthenticated($config)]);
        break;

    case 'get_reports':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $year = $_GET['year'] ?? null;
        $search = $_GET['search'] ?? null;
        $reports = getReports($config, $year, $search);
        echo json_encode($reports);
        break;
        
    case 'upload_files':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $uuid = generateUUID();
        $uploadDir = $config['data_path'] . '/uploads/' . $uuid;
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Salva il PDF originale
        if (isset($_FILES['pdf'])) {
            $pdfPath = $uploadDir . '/original.pdf';
            move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath);
        }
        
        // Salva il testo estratto
        if (isset($_POST['extracted_text'])) {
            $textPath = $uploadDir . '/extracted.txt';
            file_put_contents($textPath, $_POST['extracted_text']);
        }
        
        echo json_encode(['success' => true, 'uuid' => $uuid]);
        break;
        
    case 'analyze_report':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $uuid = $_POST['uuid'] ?? '';
        $textPath = $config['data_path'] . '/uploads/' . $uuid . '/extracted.txt';
        
        if (!file_exists($textPath)) {
            echo json_encode(['error' => 'File non trovato']);
            exit;
        }
        
        $extractedText = file_get_contents($textPath);
        $analysis = analyzeWithOpenAI($config, $extractedText);
        
        if ($analysis) {
            echo json_encode(['success' => true, 'data' => $analysis, 'uuid' => $uuid]);
        } else {
            echo json_encode(['error' => 'Errore nell\'analisi del referto']);
        }
        break;
        
    case 'save_report':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $uuid = $_POST['uuid'] ?? '';
        $reportData = json_decode($_POST['report_data'], true);
        
        // Se l'uuid è temp, genera un nuovo UUID
        if ($uuid === 'temp' || empty($uuid)) {
            $uuid = generateUUID();
        }
        
        if (saveReport($config, $reportData, $uuid)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Errore nel salvataggio']);
        }
        break;
        
    case 'delete_report':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? '';
        
        if (empty($uuid)) {
            echo json_encode(['error' => 'UUID mancante']);
            exit;
        }
        
        if (deleteReport($config, $uuid)) {
            echo json_encode(['success' => true, 'message' => 'Referto eliminato con successo']);
        } else {
            echo json_encode(['error' => 'Referto non trovato o errore durante l\'eliminazione']);
        }
        break;
        
    case 'download_pdf':
        if (!isAuthenticated($config)) {
            // Per i download restituiamo un header di errore invece del JSON
            http_response_code(401);
            echo 'Non autenticato';
            exit;
        }
        
        $uuid = $_GET['uuid'] ?? '';
        
        if (empty($uuid)) {
            http_response_code(400);
            echo 'UUID mancante';
            exit;
        }
        
        $pdfPath = $config['data_path'] . '/uploads/' . $uuid . '/original.pdf';
        
        if (!file_exists($pdfPath)) {
            http_response_code(404);
            echo 'File PDF non trovato';
            exit;
        }
        
        // Verifica che il file sia effettivamente un PDF
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $pdfPath);
        finfo_close($fileInfo);
        
        if ($mimeType !== 'application/pdf') {
            http_response_code(400);
            echo 'Il file non è un PDF valido';
            exit;
        }
        
        // Trova i dati del referto per ottenere un nome file significativo
        $reportData = null;
        $years = glob($config['data_path'] . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
        foreach ($years as $yearDir) {
            $jsonPath = $yearDir . '/' . $uuid . '.json';
            if (file_exists($jsonPath)) {
                $reportData = json_decode(file_get_contents($jsonPath), true);
                break;
            }
        }
        
        // Genera un nome file significativo
        $filename = $uuid . '.pdf';
        if ($reportData && isset($reportData['titolo']) && isset($reportData['data_esame'])) {
            $safeTitolo = preg_replace('/[^A-Za-z0-9\-_]/', '_', $reportData['titolo']);
            $filename = $reportData['data_esame'] . '_' . $safeTitolo . '.pdf';
        }
        
        // Imposta gli header per il download del PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        // Invia il file
        readfile($pdfPath);
        exit;
        break;
        
    case 'get_report':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $uuid = $_GET['uuid'] ?? $_POST['uuid'] ?? '';
        
        if (empty($uuid)) {
            echo json_encode(['error' => 'UUID mancante']);
            exit;
        }
        
        $report = getReport($config, $uuid);
        
        if ($report) {
            echo json_encode(['success' => true, 'data' => $report]);
        } else {
            echo json_encode(['error' => 'Referto non trovato']);
        }
        break;
        
    case 'update_report':
        if (!isAuthenticated($config)) {
            echo json_encode(['error' => 'Non autenticato']);
            exit;
        }
        
        $uuid = $_POST['uuid'] ?? '';
        $reportData = json_decode($_POST['report_data'], true);
        
        if (empty($uuid)) {
            echo json_encode(['error' => 'UUID mancante']);
            exit;
        }
        
        if (!$reportData) {
            echo json_encode(['error' => 'Dati del referto mancanti']);
            exit;
        }
        
        if (updateReport($config, $reportData, $uuid)) {
            echo json_encode(['success' => true, 'message' => 'Referto aggiornato con successo']);
        } else {
            echo json_encode(['error' => 'Errore nell\'aggiornamento del referto']);
        }
        break;
        
    default:
        echo json_encode(['error' => 'Azione non valida']);
        break;
}
?>
