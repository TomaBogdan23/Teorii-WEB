<?php
// ==========================================
// CONFIGURARE DOCKER
// ==========================================
$host = 'db';        // Numele serviciului din docker-compose
$user = 'admin';     // Userul definit in docker-compose.yml
$pass = 'bogdan';    // Parola definita in docker-compose.yml
$db   = 'proiecttw'; // Baza de date definita in docker-compose.yml

// ==========================================
// CONNECT TO DATABASE (PDO)
// ==========================================
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // If DB not available, show friendly message
    die("Conexiune la baza de date eșuată: " . htmlspecialchars($e->getMessage()));
}

// ==========================================
// INITIAL SETUP: CREATE TABLES IF NOT EXISTS
// ==========================================
$queries = [
"CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(50) UNIQUE,
    type VARCHAR(50),
    balance DECIMAL(15,2) DEFAULT 0,
    iban VARCHAR(34),
    interest DECIMAL(5,2) DEFAULT NULL,
    credit_limit DECIMAL(15,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50),
    last4 VARCHAR(4),
    valid VARCHAR(7),
    status VARCHAR(20),
    contactless DECIMAL(10,2) DEFAULT 0,
    atm DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT,
    type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'RON',
    description TEXT,
    counterparty VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100),
    name VARCHAR(255),
    info VARCHAR(255),
    min_amount DECIMAL(15,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255),
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS faq (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(255),
    answer TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];
foreach ($queries as $q) $pdo->exec($q);

// ==========================================
// SEED DEMO DATA (only when empty)
// ==========================================
function tableCount($pdo, $table){
    $stmt = $pdo->query("SELECT COUNT(*) FROM `".str_replace('`','', $table)."`");
    return (int)$stmt->fetchColumn();
}

if (tableCount($pdo,'accounts') === 0) {
    $insert = $pdo->prepare("INSERT INTO accounts (external_id,type,balance,iban,interest,credit_limit) VALUES (?,?,?,?,?,?)");
    $insert->execute(['RON-1','Curent',5243.45,'RO09BNCC000000000001',NULL,NULL]);
    $insert->execute(['SAV-1','Economii',12000.00,'RO09BNCC000000000002',6.00,NULL]);
    $insert->execute(['FX-1','Valută USD',1500.50,'RO09BNCC000000000003',NULL,NULL]);
    $insert->execute(['CR-1','Credit',-4000.00,'RO09BNCC000000000004',NULL,10000.00]);
}

if (tableCount($pdo,'cards') === 0) {
    $insert = $pdo->prepare("INSERT INTO cards (type,last4,valid,status,contactless,atm) VALUES (?,?,?,?,?,?)");
    $insert->execute(['Visa','1234','12/26','Activ',500,2000]);
    $insert->execute(['Mastercard','5678','05/27','Activ',700,1500]);
    $insert->execute(['Visa Electron','9012','08/25','Blocat',300,1000]);
}

if (tableCount($pdo,'offers') === 0){
    $insert = $pdo->prepare("INSERT INTO offers (category,name,info,min_amount) VALUES (?,?,?,?)");
    $insert->execute(['Depozit','Depozit Flex 6% / 12 luni',NULL,1000]);
    $insert->execute(['Credit','Credit personal promo','Documentație minimă',NULL]);
    $insert->execute(['Investiții','Fond mutual Acțiuni','Randament estimat 8%',NULL]);
}

if (tableCount($pdo,'faq') === 0){
    $insert = $pdo->prepare("INSERT INTO faq (question,answer) VALUES (?,?)");
    $insert->execute(['Cum blochez cardul?','Din secțiunea Carduri — click pe "Blochează card" sau sună la +40 21 000 000.']);
    $insert->execute(['Care sunt limitele de retragere?','Limitele sunt afișate în pagina Carduri și pot fi modificate la cerere.']);
    $insert->execute(['Cum setez plăți recurente?','Din Plăți & Transfer: alege beneficiarul și activează opțiunea recurente.']);
    $insert->execute(['Cum pot solicita extrase bancare?','În secțiunea Conturi, click pe "Descarcă extras".']);
}

// ==========================================
// HANDLE FORM SUBMISSIONS
// ==========================================
$errors = [];
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';

    if ($action === 'add_transaction'){
        $account_id = intval($_POST['account_id'] ?? 0);
        $type = ($_POST['type'] ?? 'debit') === 'credit' ? 'credit' : 'debit';
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $counterparty = trim($_POST['counterparty'] ?? null);

        if ($account_id <= 0 || $amount <= 0) $errors[] = 'Cont invalid sau sumă invalidă.';

        if (empty($errors)){
            $pdo->prepare("INSERT INTO transactions (account_id,type,amount,description,counterparty) VALUES (?,?,?,?,?)")
                ->execute([$account_id,$type,$amount,$description,$counterparty]);

            // update account balance
            $mult = $type === 'credit' ? 1 : -1;
            $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
                ->execute([$mult * $amount, $account_id]);

            $messages[] = 'Tranzacție adăugată cu succes.';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    }

    if ($action === 'add_card'){
        $type = trim($_POST['card_type'] ?? 'Card');
        $last4 = substr(preg_replace('/[^0-9]/','', $_POST['card_number'] ?? '0000'), -4);
        $valid = trim($_POST['card_valid'] ?? '');
        $status = trim($_POST['card_status'] ?? 'Activ');
        $contactless = floatval($_POST['card_contactless'] ?? 0);
        $atm = floatval($_POST['card_atm'] ?? 0);
        $pdo->prepare("INSERT INTO cards (type,last4,valid,status,contactless,atm) VALUES (?,?,?,?,?,?)")
            ->execute([$type,$last4,$valid,$status,$contactless,$atm]);
        $messages[] = 'Card adăugat.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    if ($action === 'support_msg'){
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if (empty($name) || empty($email) || empty($message)) $errors[] = 'Completați toate câmpurile.';
        if (empty($errors)){
            $pdo->prepare("INSERT INTO support_messages (name,email,message) VALUES (?,?,?)")
                ->execute([$name,$email,$message]);
            $messages[] = 'Mesaj trimis către suport.';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    }

    if ($action === 'add_account'){
        $external = trim($_POST['external_id'] ?? uniqid('ACC-'));
        $type = trim($_POST['acc_type'] ?? 'Curent');
        $balance = floatval($_POST['acc_balance'] ?? 0);
        $iban = trim($_POST['acc_iban'] ?? '');
        $interest = ($_POST['acc_interest'] ?? '') !== '' ? floatval($_POST['acc_interest']) : null;
        $limit = ($_POST['acc_limit'] ?? '') !== '' ? floatval($_POST['acc_limit']) : null;
        $pdo->prepare("INSERT INTO accounts (external_id,type,balance,iban,interest,credit_limit) VALUES (?,?,?,?,?,?)")
            ->execute([$external,$type,$balance,$iban,$interest,$limit]);
        $messages[] = 'Cont adăugat.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// ==========================================
// FETCH DATA FOR DISPLAY
// ==========================================
$accounts = $pdo->query('SELECT * FROM accounts ORDER BY id ASC')->fetchAll();
$cards = $pdo->query('SELECT * FROM cards ORDER BY id ASC')->fetchAll();
$offers = $pdo->query('SELECT * FROM offers ORDER BY id ASC')->fetchAll();
$faqItems = $pdo->query('SELECT * FROM faq ORDER BY id ASC')->fetchAll();
// Recent transactions
$txStmt = $pdo->query('SELECT t.*, a.external_id, a.type as account_type FROM transactions t LEFT JOIN accounts a ON t.account_id = a.id ORDER BY t.created_at DESC LIMIT 200');
$transactions = $txStmt->fetchAll();

// totals
$totalBalance = array_reduce($accounts, function($s,$a){return $s + (float)$a['balance'];}, 0.0);

// support contacts (static demo)
$bankInfo = [
    'name' => 'BogBank S.A. (demo)',
    'taxCode' => 'RO00000000',
    'address' => 'Str. Exemplu 1, București',
    'license' => 'Autoritate financiară (demo)',
    'filiale' => [
        ['city'=>'București','addr'=>'Bd. Demo 10','hours'=>'L‑V 09:00‑18:00'],
        ['city'=>'Cluj','addr'=>'Str. Exemplu 2','hours'=>'L‑V 09:00‑17:00'],
        ['city'=>'Iași','addr'=>'Str. Demo 3','hours'=>'L‑V 09:00‑17:00']
    ],
    'support' => [
        ['method'=>'Telefon','info'=>'+40 21 000 000'],
        ['method'=>'Email','info'=>'suport@bogbank.demo'],
        ['method'=>'Chat','info'=>'Chat demo în interfață']
    ]
];

// Simple helper to escape output
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

?>

<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BogBank — Interfață Extinsă (Demo)</title>
<style>
:root{
  --bg:#0f1724; --card:#0b1220; --muted:#94a3b8; --accent:#06b6d4; --accent-2:#7c3aed; --success:#16a34a;
  --glass: rgba(255,255,255,0.04); --text:#e6eef6; --card-border: rgba(255,255,255,0.03);
  font-family: Inter, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;
}
body.light{ --bg:#f6f9fc; --card:#ffffff; --muted:#556074; --text:#0b1220; --glass: rgba(2,16,41,0.04); --card-border: rgba(2,16,41,0.06)}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#071026 0%, #081226 60%);color:var(--text);min-height:100vh}
.container{max-width:1180px;margin:28px auto;padding:20px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.brand{display:flex;gap:12px;align-items:center}
.logo{width:46px;height:46px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#021029}
.title{font-size:18px;font-weight:600}
.profile{display:flex;gap:12px;align-items:center}
.avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#fff2 0%, #fff0 50%);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--accent-2)}
.grid{display:grid;grid-template-columns:260px 1fr;gap:18px}
.sidebar{background:var(--card);padding:16px;border-radius:12px;border:1px solid var(--card-border)}
.nav{display:flex;flex-direction:column;gap:8px}
.nav button{background:transparent;border:none;color:var(--muted);padding:10px;border-radius:8px;text-align:left;cursor:pointer}
.nav button.active{background:var(--glass);color:var(--accent)}
.main{padding:18px;background:linear-gradient(180deg, rgba(255,255,255,0.02), transparent);border-radius:12px;border:1px solid var(--card-border)}
.grid-row{display:grid;grid-template-columns:1fr 360px;gap:18px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:16px;border-radius:12px;border:1px solid var(--card-border)}
.balance{display:flex;flex-direction:column;gap:8px}
.balance h2{margin:0;font-size:14px;color:var(--muted)}
.amount{font-size:28px;font-weight:700}
.actions{display:flex;gap:10px;margin-top:6px}
.btn{padding:10px 14px;border-radius:10px;border:none;cursor:pointer;font-weight:600}
.btn-primary{background:linear-gradient(90deg,var(--accent),var(--accent-2));color:#021029}
.btn-soft{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted)}
.form-row{display:flex;gap:8px;margin-top:8px}
input,select,textarea,button{background:transparent;border:1px solid rgba(255,255,255,0.06);padding:10px;border-radius:8px;color:inherit;width:100%}
textarea{min-height:100px}
.tx-list{margin-top:12px}
.tx{display:flex;justify-content:space-between;padding:10px;border-radius:8px;background:linear-gradient(180deg, transparent, rgba(255,255,255,0.01));margin-bottom:8px}
.tx .meta{display:flex;gap:10px;align-items:center}
.tx .meta .dot{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700}
.tx .right{display:flex;flex-direction:column;align-items:flex-end}
.muted{color:var(--muted);font-size:13px}
.small{font-size:13px}
.accent-pill{padding:6px 10px;border-radius:999px;background:rgba(6,182,212,0.12);color:var(--accent)}
.section-title{display:flex;justify-content:space-between;align-items:center}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.offer{padding:12px;border-radius:10px;background:linear-gradient(180deg, rgba(124,58,237,0.06), rgba(6,182,212,0.03))}
.faq-item{border-top:1px dashed rgba(255,255,255,0.02);padding:10px 0}
.faq-q{cursor:pointer}
@media (max-width:900px){
  .grid{grid-template-columns:1fr;}
  .grid-row{grid-template-columns:1fr}
  .sidebar{display:flex;overflow:auto}
  .two-col{grid-template-columns:1fr}
}
.meta-line{display:flex;gap:8px;align-items:center}
.muted-plain{color:var(--muted)}
.kbd{padding:4px 8px;border-radius:6px;border:1px solid var(--card-border);font-size:13px}
</style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand">
      <img src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='90' height='34'><rect rx='6' width='90' height='34' fill='%2306b6d4'/></svg>" style="width:90px;border-radius:5px;margin-bottom:5px;" alt="logo">
      <div>
        <div class="title">BogBank — Interfață Demo</div>
        <div class="small muted">Cont demo • UX extins</div>
      </div>
    </div>
    <div class="profile">
      <div class="small muted">Bine ai venit, <strong id="userName">Demo User</strong></div>
      <div style="display:flex;gap:10px;align-items:center">
        <button id="themeToggle" class="btn btn-soft" title="Comută temă">🌗</button>
        <div class="avatar">B</div>
      </div>
    </div>
  </div>

  <div class="grid">
    <aside class="sidebar card" aria-label="Meniu lateral">
      <div class="nav" role="navigation" aria-label="Meniu principal">
        <button class="active" data-view="dashboard">Dashboard</button>
        <button data-view="conturi">Conturi</button>
        <button data-view="plati">Plăți & Transfer</button>
        <button data-view="carduri">Carduri</button>
        <button data-view="produse">Produse & Oferte</button>
        <button data-view="despre">Despre banca</button>
        <button data-view="ajutor">Ajutor & Contact</button>
        <button data-view="setari">Setări</button>
      </div>
      <div style="margin-top:14px;border-top:1px dashed rgba(255,255,255,0.02);padding-top:12px">
        <div class="small muted">Contact rapid:</div>
        <div class="small">Suport 24/7 — <strong>+40 21 000 000</strong></div>
        <div style="margin-top:8px" class="muted small">Program filiale: L‑V 09:00‑18:00; Sâmbătă 09:00‑13:00</div>
      </div>
    </aside>

    <main class="main" aria-live="polite">
      <!-- DASHBOARD -->
      <section id="view-dashboard">
        <div class="grid-row">
          <div>
            <div class="card balance">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                  <h2>Sold total</h2>
                  <div class="amount" id="totalBalance"><?=e(number_format((float)$totalBalance,2,',','.'))?> RON</div>
                  <div class="muted small" id="dashboardAccounts"><?=e(implode(' • ', array_column($accounts,'type'))) ?></div>
                </div>
                <div style="text-align:right">
                  <div class="muted small">Ultima actualizare:</div>
                  <div class="small muted" id="lastUpdate"><?=e(date('d.m.Y H:i:s'))?></div>
                  <div style="margin-top:8px"><button id="quickSave" class="btn btn-soft">Mută în economii</button></div>
                </div>
              </div>
              <div class="actions">
                <button class="btn btn-primary" id="transferBtn">Transferă</button>
                <button class="btn btn-soft" id="payBtn">Plătește factură</button>
                <button class="btn btn-soft" id="showSim">Simulează credit</button>
              </div>
              <div class="form-row" style="margin-top:12px">
                <input id="payTo" placeholder="Nume / IBAN" aria-label="Destinatar">
                <input id="amount" placeholder="Sumă" aria-label="Sumă">
              </div>
            </div>

            <div class="card tx-list" style="margin-top:14px">
              <div class="section-title">
                <h3 style="margin:0 0 8px 0">Tranzacții recente</h3>
                <div style="display:flex;gap:8px;align-items:center">
                  <select id="txFilterType"><option value="all">Toate</option><option value="credit">Credit</option><option value="debit">Debit</option></select>
                  <select id="txFilterPeriod"><option value="30">Ultimele 30 zile</option><option value="90">Ultimele 90 zile</option><option value="365">Ultimul an</option></select>
                  <button class="btn btn-soft" id="clearFilters">Reset</button>
                </div>
              </div>
              <div id="txContainer">
                <?php foreach($transactions as $t): ?>
                  <div class="tx">
                    <div class="meta">
                      <div class="dot" style="background:rgba(255,255,255,0.02)"><?=e(strtoupper($t['type'][0]))?></div>
                      <div>
                        <div style="font-weight:600"><?=e($t['description']?:($t['counterparty']?:'Tranzacție'))?></div>
                        <div class="muted small"><?=e($t['created_at'])?> • <?=e($t['external_id'])?></div>
                      </div>
                    </div>
                    <div class="right">
                      <div style="font-weight:700; color:<?= $t['type'] === 'credit' ? 'var(--success)' : 'inherit' ?>;"><?=($t['type']==='credit'?'+':'-')?> <?=e(number_format($t['amount'],2,',','.'))?> RON</div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div style="text-align:center;margin-top:6px"><button class="btn btn-soft" id="moreTx">Încarcă mai multe</button></div>
            </div>

            <div class="card" style="margin-top:14px">
              <div class="section-title"><h3 style="margin:0">Evoluție sold (mock)</h3><div class="muted small">Grafic simplu</div></div>
              <canvas id="balanceChart" width="800" height="220" style="width:100%;height:220px;margin-top:12px;border-radius:8px;background:linear-gradient(180deg, rgba(255,255,255,0.01), transparent)"></canvas>
            </div>
          </div>

          <aside>
            <div class="card" id="dashboardCards">
              <h4>Carduri rapide</h4>
              <?php foreach($cards as $c): ?>
                <div class="small muted-plain"><?=e($c['type'])?> ****<?=e($c['last4'])?> (<?=e($c['status'])?>)</div>
              <?php endforeach; ?>
            </div>
            <div class="card" style="margin-top:12px" id="dashboardOffers">
              <h4>Oferte</h4>
              <?php foreach($offers as $o): ?>
                <div class="small muted-plain"><?=e($o['name'])?> (<?=e($o['category'])?>)</div>
              <?php endforeach; ?>
            </div>
            <div class="card" style="margin-top:12px" id="dashboardQuickActions">
              <h4>Acțiuni rapide</h4>
              <button class="btn btn-primary" onclick="document.querySelector('[data-view=plati]').click();">Transfer rapid</button>
            </div>
          </aside>
        </div>
      </section>

      <!-- CONTURI -->
      <section id="view-conturi" style="display:none">
        <div class="card"><h3>Conturi</h3>
          <div class="two-col" id="accountsContainer">
            <?php foreach($accounts as $acc): ?>
              <div class="card">
                <div class="muted small"><?=e($acc['type'])?></div>
                <div class="amount"><?=e(number_format($acc['balance'],2,',','.'))?></div>
                <div class="muted small">IBAN: <?=e($acc['iban'])?></div>
                <?php if($acc['interest']): ?><div class="muted small">Dobândă: <?=e($acc['interest'])?>%</div><?php endif; ?>
                <?php if($acc['credit_limit']): ?><div class="muted small">Limită credit: <?=e(number_format($acc['credit_limit'],2,',','.'))?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:12px">
            <h4>Adaugă cont</h4>
            <form method="post">
              <input type="hidden" name="action" value="add_account">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
                <input name="external_id" placeholder="ID (opțional)">
                <select name="acc_type"><option>Curent</option><option>Economii</option><option>Valută</option><option>Credit</option></select>
                <input name="acc_balance" placeholder="Sold inițial">
                <input name="acc_iban" placeholder="IBAN">
                <input name="acc_interest" placeholder="Dobândă % (opțional)">
                <input name="acc_limit" placeholder="Limită credit (opțional)">
              </div>
              <div style="margin-top:8px"><button class="btn btn-primary" type="submit">Adaugă cont</button></div>
            </form>
          </div>
        </div>
      </section>

      <!-- PLATI -->
      <section id="view-plati" style="display:none">
        <div class="card"><h3>Plăți & Transfer</h3>
          <form id="transferForm" method="post" style="margin-top:12px">
            <input type="hidden" name="action" value="add_transaction">
            <div style="display:grid;grid-template-columns:1fr 150px;gap:8px">
              <select name="account_id" required>
                <?php foreach($accounts as $a): ?><option value="<?=e($a['id'])?>"><?=e($a['external_id'].' • '. $a['type'] .' • '. number_format($a['balance'],2,',','.'))?></option><?php endforeach; ?>
              </select>
              <select name="type"><option value="debit">Debit</option><option value="credit">Credit</option></select>
              <input name="amount" placeholder="Sumă" required>
              <input name="counterparty" placeholder="Beneficiar / cont">
              <input name="description" placeholder="Descriere">
            </div>
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary" type="submit">Trimite</button>
              <button class="btn btn-soft" type="button" id="saveTemplate">Salvează șablon</button>
            </div>
          </form>

          <div style="margin-top:12px">
            <h4>Istoric tranzacții</h4>
            <div style="max-height:240px;overflow:auto;margin-top:8px">
              <table style="width:100%;border-collapse:collapse">
                <thead><tr><th>#</th><th>Data</th><th>Cont</th><th>Tip</th><th>Suma</th><th>Descriere</th></tr></thead>
                <tbody>
                  <?php foreach($transactions as $tx): ?>
                    <tr style="border-top:1px solid rgba(255,255,255,0.02)">
                      <td><?=e($tx['id'])?></td>
                      <td><?=e($tx['created_at'])?></td>
                      <td><?=e($tx['external_id'])?></td>
                      <td><?=e($tx['type'])?></td>
                      <td><?=e(number_format($tx['amount'],2,',','.'))?> RON</td>
                      <td><?=e($tx['description'])?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </section>

      <!-- CARDURI -->
      <section id="view-carduri" style="display:none">
        <div class="card"><h3>Carduri</h3>
          <div style="margin-top:8px" class="two-col">
            <div>
              <?php foreach($cards as $c): ?>
                <div class="card" style="margin-bottom:10px">
                  <div class="muted small"><?=e($c['type'])?> • **** <?=e($c['last4'])?></div>
                  <div class="muted small">Valid: <?=e($c['valid'])?> • Status: <?=e($c['status'])?></div>
                  <div class="small muted">Contactless: <?=e(number_format($c['contactless'],2,',','.'))?>, ATM: <?=e(number_format($c['atm'],2,',','.'))?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div>
              <h4>Adaugă card</h4>
              <form method="post">
                <input type="hidden" name="action" value="add_card">
                <input name="card_type" placeholder="Tip card (Visa, Mastercard)">
                <input name="card_number" placeholder="Număr card (ultimele 4 cifre recomandate)">
                <input name="card_valid" placeholder="Valid (MM/AA)">
                <select name="card_status"><option>Activ</option><option>Blocat</option></select>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
                  <input name="card_contactless" placeholder="Contactless limit">
                  <input name="card_atm" placeholder="Limită ATM">
                </div>
                <div style="margin-top:8px"><button class="btn btn-primary" type="submit">Adaugă card</button></div>
              </form>
            </div>
          </div>
        </div>
      </section>

      <!-- PRODUSE -->
      <section id="view-produse" style="display:none">
        <div class="card"><h3>Produse & Oferte</h3>
          <div style="margin-top:10px" class="two-col" id="offersContainer">
            <?php foreach($offers as $o): ?>
              <div class="offer">
                <div class="small muted"><?=e($o['category'])?></div>
                <div style="font-weight:600"><?=e($o['name'])?></div>
                <?php if($o['info']): ?><div class="muted small"><?=e($o['info'])?></div><?php endif; ?>
                <?php if($o['min_amount']): ?><div class="muted small">Min: <?=e(number_format($o['min_amount'],2,',','.'))?> RON</div><?php endif; ?>
                <div style="margin-top:6px"><button class="btn btn-primary">Aplică</button></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- DESPRE BANCĂ -->
      <section id="view-despre" style="display:none">
        <div class="card"><h3>Despre banca noastră</h3>
          <div class="muted small" id="bankInfo">
            <div>Nume: <?=e($bankInfo['name'])?></div>
            <div>CUI: <?=e($bankInfo['taxCode'])?></div>
            <div>Adresă: <?=e($bankInfo['address'])?></div>
            <div>Licență: <?=e($bankInfo['license'])?></div>
            <div>Filiale:</div>
            <ul><?php foreach($bankInfo['filiale'] as $f) echo '<li>'.e($f['city']).': '.e($f['addr']).' ('.e($f['hours']).')</li>'; ?></ul>
            <div>Suport:</div>
            <ul><?php foreach($bankInfo['support'] as $s) echo '<li>'.e($s['method']).': '.e($s['info']).'</li>'; ?></ul>
          </div>
        </div>
      </section>

      <!-- AJUTOR & CONTACT -->
      <section id="view-ajutor" style="display:none">
        <div class="card"><h3>Ajutor & Contact</h3>
          <div style="margin-top:10px" class="two-col">
            <div id="faqContainer">
              <?php foreach($faqItems as $f): ?>
                <div class="faq-item"><div class="faq-q"><?=e($f['question'])?></div><div class="faq-a muted" style="display:none;margin-top:4px"><?=e($f['answer'])?></div></div>
              <?php endforeach; ?>
            </div>
            <div>
              <h4>Contact rapid</h4>
              <form method="post">
                <input type="hidden" name="action" value="support_msg">
                <input id="contactName" name="name" placeholder="Nume" required>
                <input id="contactEmail" name="email" placeholder="Email" required>
                <textarea id="contactMsg" name="message" placeholder="Mesaj" required></textarea>
                <div style="display:flex;gap:8px;margin-top:8px">
                  <button class="btn btn-primary" type="submit">Trimite</button>
                  <button class="btn btn-soft" type="button" id="startChat">Chat demo</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </section>

      <!-- SETARI -->
      <section id="view-setari" style="display:none">
        <div class="card"><h3>Setări cont</h3>
          <div style="margin-top:8px" class="two-col">
            <div>
              <label class="small muted">Preferințe</label>
              <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px">
                <label><input type="checkbox" id="notifEmail" checked> Notificări prin email</label>
                <label><input type="checkbox" id="notifSMS"> Notificări SMS</label>
              </div>
            </div>
            <div>
              <label class="small muted">Securitate</label>
              <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px">
                <button class="btn btn-soft">Schimbă parolă</button>
                <button class="btn btn-soft">Activează autentificare multi-factor</button>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</div>

<script>
// THEME TOGGLE
const themeToggle = document.getElementById('themeToggle');
themeToggle.addEventListener('click',()=>{document.body.classList.toggle('light')});

// NAV
document.querySelectorAll('.nav button').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.nav button').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const view = btn.dataset.view;
    document.querySelectorAll('main section').forEach(s=>s.style.display='none');
    document.getElementById('view-'+view).style.display='block';
  });
});

// FAQ toggle
document.querySelectorAll('.faq-q').forEach(q=>q.addEventListener('click',()=>{
  const a = q.nextElementSibling; a.style.display = a.style.display === 'none' ? 'block' : 'none';
}));

// Simple chart draw
const ctx = document.getElementById('balanceChart').getContext('2d');
ctx.fillStyle='#0b1220'; ctx.fillRect(0,0,800,220);
ctx.fillStyle='#06b6d4'; let x=0; const accounts = <?=json_encode(array_map(function($a){return (float)$a['balance'];}, $accounts));?>; accounts.forEach(a=>{ ctx.fillRect(x, 220 - (a/50000*200), 60, a/50000*200); x+=80; });

// tx filter (client side simple)
document.getElementById('txFilterType').addEventListener('change',filterTx);
document.getElementById('clearFilters').addEventListener('click',()=>{document.getElementById('txFilterType').value='all';filterTx();});
function filterTx(){
  const type = document.getElementById('txFilterType').value;
  document.querySelectorAll('#txContainer .tx').forEach(tx=>{
    if(type==='all') tx.style.display='flex';
    else tx.style.display = tx.querySelector('.dot').textContent.toLowerCase() === type.charAt(0) ? 'flex' : 'none';
  });
}

// simple interactivity for transfer button
document.getElementById('transferBtn').addEventListener('click',()=>{document.querySelector('[data-view=plati]').click();});

</script>
</body>
</html>