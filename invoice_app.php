<?php
/**
 
 * Author: Abdur Rahman Roky
 * Email: abdurrahmanroky.bd@gmail.com
 * Created: 2025-08-17
 
**/

// ===============APP Description:=================
// invoice_app.php — Single-file App
// Invoices + Inventory + Payments + Returns (CRUD)
// PHP + HTML + CSS + JavaScript — No external libraries
// DB: SQLite (data.sqlite created automatically)
// Print/PDF: Use browser Print dialog (print stylesheet provided)
// ================================

// ---------- CONFIG ----------
const APP_TITLE = 'Invoice & Inventory (Single-File)';
const APP_LOGO = 'assets/img/logo-github.png';
const BUSINESS_NAME = 'Your Shop Name';
const CURRENCY = '৳'; // change as needed
const RETURN_WINDOW_DAYS = 7; // Return policy window (days)
const DEFAULT_TAX_RATE = 0; // % e.g., 5 = 5%

// ---------- DB ----------
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . __DIR__ . '/data.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('PRAGMA foreign_keys = ON;');

  // Schema
  $pdo->exec(<<<SQL
  CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT UNIQUE,
    name TEXT NOT NULL,
    price REAL NOT NULL DEFAULT 0,
    stock REAL NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
  SQL);
  $pdo->exec(<<<SQL
  CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number TEXT UNIQUE,
    customer_name TEXT,
    customer_phone VARCHAR(15),
    customer_address TEXT,
    issue_date TEXT NOT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    discount REAL NOT NULL DEFAULT 0, -- invoice-level discount amount
    tax_rate REAL NOT NULL DEFAULT 0, -- percent
    tax_amount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    paid REAL NOT NULL DEFAULT 0,
    due REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open', -- open|paid|canceled
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
  SQL);
  $pdo->exec(<<<SQL
  CREATE TABLE IF NOT EXISTS invoice_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id),
    description TEXT,
    qty REAL NOT NULL,
    unit_price REAL NOT NULL,
    line_discount REAL NOT NULL DEFAULT 0, -- amount per line
    line_total REAL NOT NULL
  );
  SQL);
  $pdo->exec(<<<SQL
  CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    amount REAL NOT NULL,
    method TEXT,
    paid_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note TEXT
  );
  SQL);
  $pdo->exec(<<<SQL
  CREATE TABLE IF NOT EXISTS returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    item_id INTEGER NOT NULL REFERENCES invoice_items(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id),
    qty REAL NOT NULL,
    reason TEXT,
    restocked INTEGER NOT NULL DEFAULT 1,
    returned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  SQL);
  return $pdo;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function route(string $view, array $params=[]){ return '?' . http_build_query(array_merge(['view'=>$view], $params)); }
function post($k, $d=null){ return $_POST[$k] ?? $d; }
function getv($k, $d=null){ return $_GET[$k] ?? $d; }

function gen_invoice_number(PDO $pdo): string {
  $prefix = date('Ymd');
  $seq = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM invoices")->fetchColumn();
  return $prefix . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ---------- ACTIONS ----------
$pdo = db();
$action = getv('action');
if ($_SERVER['REQUEST_METHOD']==='POST' && $action){
  try{
    switch($action){
      case 'save_product': save_product(); break;
      case 'delete_product': delete_product(); break;
      case 'save_invoice': save_invoice(); break;
      case 'cancel_invoice': cancel_invoice(); break;
      case 'add_payment': add_payment(); break;
      case 'update_payment': update_payment(); break;
      case 'delete_payment': delete_payment(); break;
      case 'process_return': process_return(); break;
      case 'update_return': update_return(); break;
      case 'delete_return': delete_return(); break;
    }
  } catch(Throwable $e){
    http_response_code(500);
    echo '<pre style="padding:1rem;color:#b00;">Error: '.h($e->getMessage())."\n".h($e->getFile()).':'.h($e->getLine()).'</pre>';
    exit;
  }
}

function save_product(){
  $pdo = db();
  $id = (int)post('id',0);
  $sku = trim((string)post('sku')) ?: null;
  $name = trim((string)post('name'));
  $price = (float)post('price',0);
  $stock = (float)post('stock',0);
  if ($name==='') throw new RuntimeException('Product name required');
  if ($id>0){
    $st=$pdo->prepare("UPDATE products SET sku=?, name=?, price=?, stock=? WHERE id=?");
    $st->execute([$sku,$name,$price,$stock,$id]);
  } else {
    $st=$pdo->prepare("INSERT INTO products(sku,name,price,stock) VALUES(?,?,?,?)");
    $st->execute([$sku,$name,$price,$stock]);
  }
  header('Location: '.route('products')); exit;
}
function delete_product(){
  db()->prepare("DELETE FROM products WHERE id=?")->execute([(int)post('id')]);
  header('Location: '.route('products')); exit;
}

function save_invoice(){
  $pdo = db();
  $pdo->beginTransaction();
  try{
    $customer = trim((string)post('customer_name'));
    $customer_phone = trim((string)post('customer_phone'));
    $customer_address = trim((string)post('customer_address'));
    $issue_date = post('issue_date') ?: date('Y-m-d');
    $invoice_discount = (float)post('invoice_discount',0); // amount
    $tax_rate = (float)post('tax_rate', DEFAULT_TAX_RATE); // percent
    $notes = trim((string)post('notes'));
    $items = json_decode((string)post('items_json'), true) ?: [];
    if (!$items) throw new RuntimeException('No items in invoice');

    // compute totals
    $subtotal = 0.0; $line_rows = [];
    foreach($items as $row){
      $pid = (int)$row['product_id'];
      $qty = (float)$row['qty'];
      $price = (float)$row['unit_price'];
      $line_disc = (float)$row['line_discount'];
      if ($qty<=0) throw new RuntimeException('Quantity must be > 0');
      // stock check
      $cur = (float)$pdo->query("SELECT stock FROM products WHERE id=".$pid)->fetchColumn();
      if ($cur < $qty) throw new RuntimeException('Insufficient stock for product ID '.$pid);
      $line_total = max(0, $qty*$price - $line_disc);
      $subtotal += $line_total;
      $line_rows[] = compact('pid','qty','price','line_disc','line_total');
    }

    $taxable = max(0, $subtotal - $invoice_discount);
    $tax_amount = round($taxable * ($tax_rate/100), 2);
    $total = max(0, $taxable + $tax_amount);

    // insert invoice — UPDATED to include phone and address
    $number = gen_invoice_number($pdo);
    $st = $pdo->prepare("INSERT INTO invoices(number, customer_name, customer_phone, customer_address, issue_date, subtotal, discount, tax_rate, tax_amount, total, paid, due, status, notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$number,$customer,$customer_phone,$customer_address,$issue_date,$subtotal,$invoice_discount,$tax_rate,$tax_amount,$total,0,$total,'open',$notes]);
    $invoice_id = (int)$pdo->lastInsertId();

    // insert items + adjust stock
    $sti = $pdo->prepare("INSERT INTO invoice_items(invoice_id, product_id, description, qty, unit_price, line_discount, line_total) VALUES(?,?,?,?,?,?,?)");
    $stp = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id=? AND stock >= ?");
    foreach($line_rows as $r){
      $pr = $pdo->prepare("SELECT name FROM products WHERE id=?");
      $pr->execute([$r['pid']]);
      $pname = (string)$pr->fetchColumn();
      if ($pname==='') throw new RuntimeException('Product not found: '.$r['pid']);
      $stp->execute([$r['qty'], $r['pid'], $r['qty']]);
      if ($stp->rowCount()===0){
        throw new RuntimeException("Insufficient stock for product ID {$r['pid']}");
      }
      $sti->execute([$invoice_id,$r['pid'],$pname,$r['qty'],$r['price'],$r['line_disc'],$r['line_total']]);
    }

    // optional initial payment
    $pay_amount = (float)post('pay_amount',0);
    $pay_method = trim((string)post('pay_method'));
    if ($pay_amount>0){
      $sp = $pdo->prepare("INSERT INTO payments(invoice_id, amount, method, note) VALUES(?,?,?,?)");
      $sp->execute([$invoice_id,$pay_amount,$pay_method, 'Initial payment']);
      $pdo->prepare("UPDATE invoices SET paid = paid + ?, due = CASE WHEN due-? < 0 THEN 0 ELSE due-? END, status = CASE WHEN (paid+?) >= total THEN 'paid' ELSE status END WHERE id=?")
          ->execute([$pay_amount,$pay_amount,$pay_amount,$pay_amount,$invoice_id]);
    }

    $pdo->commit();
    header('Location: '.route('invoice_view',['id'=>$invoice_id]));
  } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}

function cancel_invoice(){
  $id = (int)post('invoice_id');
  db()->prepare("UPDATE invoices SET status='canceled' WHERE id=?")->execute([$id]);
  header('Location: '.route('invoice_view',['id'=>$id])); exit;
}

function add_payment(){
  $pdo = db();
  $invoice_id = (int)post('invoice_id');
  $amount = (float)post('amount');
  $method = trim((string)post('method'));
  $note = trim((string)post('note'));
  if ($amount<=0) throw new RuntimeException('Amount must be > 0');
  $pdo->beginTransaction();
  try{
    $pdo->prepare("INSERT INTO payments(invoice_id, amount, method, note) VALUES(?,?,?,?)")
        ->execute([$invoice_id,$amount,$method,$note]);
    $pdo->prepare("UPDATE invoices SET paid = paid + ?, due = CASE WHEN due-? < 0 THEN 0 ELSE due-? END, status = CASE WHEN (paid+?) >= total THEN 'paid' ELSE status END WHERE id=?")
        ->execute([$amount,$amount,$amount,$amount,$invoice_id]);
    $pdo->commit();
    header('Location: '.route('invoice_view',['id'=>$invoice_id]));
  } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}
function update_payment(){
  $pdo = db();
  $id = (int)post('id');
  $invoice_id = (int)post('invoice_id');
  $amount = (float)post('amount');
  $method = trim((string)post('method'));
  $note = trim((string)post('note'));
  if ($amount<=0) throw new RuntimeException('Amount must be > 0');
  $pdo->beginTransaction();
  try{
    // get old amount
    $old = (float)$pdo->prepare("SELECT amount FROM payments WHERE id=?")->execute([$id]);
    $old = (float)$pdo->query("SELECT amount FROM payments WHERE id=".$id)->fetchColumn();
    $pdo->prepare("UPDATE payments SET amount=?, method=?, note=? WHERE id=?")
        ->execute([$amount,$method,$note,$id]);
    $diff = $amount - $old;
    $pdo->prepare("UPDATE invoices SET paid = paid + ?, due = CASE WHEN due-? < 0 THEN 0 ELSE due-? END, status = CASE WHEN paid >= total THEN 'paid' ELSE 'open' END WHERE id=?")
        ->execute([$diff,$diff,$diff,$invoice_id]);
    $pdo->commit();
    header('Location: '.route('invoice_view',['id'=>$invoice_id]));
  } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}
function delete_payment(){
  $pdo = db();
  $id = (int)post('id');
  $invoice_id = (int)post('invoice_id');
  $pdo->beginTransaction();
  try{
    $amt = (float)$pdo->query("SELECT amount FROM payments WHERE id=".$id)->fetchColumn();
    $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE invoices SET paid = CASE WHEN paid-? < 0 THEN 0 ELSE paid-? END, due = due + ?, status = CASE WHEN paid >= total THEN 'paid' ELSE 'open' END WHERE id=?")
        ->execute([$amt,$amt,$amt,$invoice_id]);
    $pdo->commit();
    header('Location: '.route('invoice_view',['id'=>$invoice_id]));
  } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}

function process_return(){
  $pdo = db();
  $invoice_id = (int)post('invoice_id');
  $item_id = (int)post('item_id');
  $qty = (float)post('qty');
  $reason = trim((string)post('reason'));
  if ($qty<=0) throw new RuntimeException('Return qty must be > 0');

  // enforce policy window
  $st = $pdo->prepare("SELECT issue_date FROM invoices WHERE id=?");
  $st->execute([$invoice_id]);
  $issue_date = $st->fetchColumn();
  if (!$issue_date) throw new RuntimeException('Invoice not found');
  $last = new DateTime($issue_date); $last->modify('+' . RETURN_WINDOW_DAYS . ' days');
  if (new DateTime() > $last) throw new RuntimeException('Return window expired');

  $pdo->beginTransaction();
  try{
    $it = $pdo->prepare("SELECT product_id, qty, unit_price FROM invoice_items WHERE id=? AND invoice_id=?");
    $it->execute([$item_id,$invoice_id]);
    $row = $it->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Invoice item not found');
    if ($qty > (float)$row['qty']) throw new RuntimeException('Cannot return more than sold');

    $pdo->prepare("INSERT INTO returns(invoice_id,item_id,product_id,qty,reason,restocked) VALUES(?,?,?,?,?,1)")
        ->execute([$invoice_id,$item_id,(int)$row['product_id'],$qty,$reason]);

    // restock
    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id=?")
        ->execute([$qty,(int)$row['product_id']]);

    // credit calculation uses unit price (ignoring line discount proportion for simplicity)
    $credit = $qty * (float)$row['unit_price'];
    $pdo->prepare("UPDATE invoices SET total = CASE WHEN total-? < 0 THEN 0 ELSE total-? END, due = CASE WHEN due-? < 0 THEN 0 ELSE due-? END, status = CASE WHEN paid >= total THEN 'paid' ELSE 'open' END WHERE id=?")
        ->execute([$credit,$credit,$credit,$credit,$invoice_id]);

    $pdo->commit();
    header('Location: '.route('invoice_view',['id'=>$invoice_id]));
  } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}
function update_return(){
  $pdo = db();
  $id = (int)post('id');
  $invoice_id = (int)post('invoice_id');
  $qty = (float)post('qty');
  $reason = trim((string)post('reason'));
  if ($qty<=0) throw new RuntimeException('Return qty must be > 0');
  $pdo->prepare("UPDATE returns SET qty=?, reason=? WHERE id=?")->execute([$qty,$reason,$id]);
  header('Location: '.route('invoice_view',['id'=>$invoice_id]));
}
function delete_return(){
  $pdo = db();
  $id = (int)post('id');
  $invoice_id = (int)post('invoice_id');
  // simple delete without stock/total reversal (keep it simple)
  $pdo->prepare("DELETE FROM returns WHERE id=?")->execute([$id]);
  header('Location: '.route('invoice_view',['id'=>$invoice_id]));
}

// ---------- VIEWS ----------
$view = getv('view','dashboard');
$products = $pdo->query("SELECT id, sku, name, price, stock FROM products ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_TITLE)?></title>
<style>
  :root { --bg:#0b1020; --card:#121a33; --muted:#93a1c1; --text:#e8ecf6; --accent:#6ea8fe; --ok:#58d68d; --warn:#f5b041; --bad:#e74c3c; --ink:#081021; }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,Segoe UI,Roboto,Arial,sans-serif}
  header{position:sticky;top:0;background:#0f1730;border-bottom:1px solid #233056;padding:.75rem 1rem;display:flex;gap:1rem;align-items:center;z-index:5}
  header a{color:var(--text);text-decoration:none;padding:.4rem .6rem;border-radius:10px}
  header a.active, header a:hover{background:var(--card)}
  .container{max-width:1150px;margin:1rem auto;padding:0 1rem}
  .card{background:var(--card);border:1px solid #233056;border-radius:14px;padding:1rem;margin-bottom:1rem;box-shadow:0 4px 20px rgba(0,0,0,.25)}
  h1,h2{margin:.2rem 0 1rem}
  table{width:100%;border-collapse:collapse;background:#0f1833}
  th,td{padding:.6rem;border-bottom:1px solid #223057}
  th{font-weight:600;text-align:left}
  input,select,button,textarea{background:#0f1833;color:var(--text);border:1px solid #2a3d75;border-radius:10px;padding:.5rem}
  input[type=number]{width:120px}
  .grid{display:grid;gap:1rem}
  .grid-2{grid-template-columns:1fr 1fr}
  .right{display:flex;justify-content:flex-end;gap:.5rem}
  .btn{cursor:pointer}
  .btn.primary{background:var(--accent);color:var(--ink);border-color:transparent}
  .btn.success{background:var(--ok);color:var(--ink);border-color:transparent}
  .btn.danger{background:var(--bad);border-color:transparent}
  .pill{display:inline-block;padding:.2rem .5rem;border-radius:999px;background:#1b2757;color:var(--muted)}
  .muted{color:var(--muted)}
  .flex{display:flex;gap:.5rem;align-items:center}
  .nowrap{white-space:nowrap}
  .hidden{display:none}
  .warn{color:var(--warn)}
  .low{color:#ffda6a;font-weight:600}

  /* print */
  @media print{
    header,.no-print{display:none !important}
    body{background:#fff;color:#000}
    .card{box-shadow:none;border:0}
    table,th,td{border-color:#ddd}
  }
</style>
</head>
<body>
<header>
  <strong><img src="<?=h(APP_LOGO)?>" alt="logo" style="width: 40px;height: 40px;border-radius: 50px;line-height: initial;"></strong>
  <nav class="flex">
    <?php $tabs=['dashboard'=>'Dashboard','products'=>'Products','new_invoice'=>'New Invoice','invoices'=>'Invoices']; foreach($tabs as $k=>$label): ?>
      <a href="<?=h(route($k))?>" class="<?= $view===$k?'active':'' ?>"><?=h($label)?></a>
    <?php endforeach; ?>
  </nav>
</header>
<div class="container">
<?php if($view==='dashboard'): ?>
  <div class="card">
    <h2>Quick stats</h2>
    <div class="grid grid-2">
      <div>
        <div class="pill">Products</div>
        <h1><?=count($products)?></h1>
      </div>
      <div>
        <?php $totalDue = (float)$pdo->query("SELECT COALESCE(SUM(due),0) FROM invoices WHERE status!='canceled'")->fetchColumn(); ?>
        <div class="pill">Total Due</div>
        <h1><?=CURRENCY?> <?=number_format($totalDue,2)?></h1>
      </div>
    </div>
  </div>
  <div class="card">
    <h2>Recent Invoices</h2>
    <?php $invs=$pdo->query("SELECT id, number, customer_name, issue_date, total, due, status FROM invoices ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); ?>
    <table>
      <tr><th>#</th><th>Customer</th><th>Date</th><th>Total</th><th>Due</th><th>Status</th><th></th></tr>
      <?php foreach($invs as $iv): ?>
      <tr>
        <td><?=h($iv['number'])?></td>
        <td><?=h($iv['customer_name'])?></td>
        <td><?=h($iv['issue_date'])?></td>
        <td class="nowrap"><?=CURRENCY?> <?=number_format($iv['total'],2)?></td>
        <td class="nowrap <?= $iv['due']>0?'warn':'' ?>"><?=CURRENCY?> <?=number_format($iv['due'],2)?></td>
        <td><?=h($iv['status'])?></td>
        <td class="right"><a class="btn" href="<?=h(route('invoice_view',['id'=>$iv['id']]))?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php elseif($view==='products'): ?>
  <div class="card">
    <div class="flex" style="justify-content:space-between;align-items:center">
      <h2>Products</h2>
      <button class="btn primary no-print" onclick="toggleProdForm()">+ Add / Edit</button>
    </div>
    <form id="prodForm" class="grid grid-2 hidden no-print" method="post" action="?action=save_product">
      <input type="hidden" name="id" id="p_id" value="0">
      <label>SKU<br><input name="sku" id="p_sku" placeholder="SKU (unique)"></label>
      <label>Name<br><input name="name" id="p_name" placeholder="Product name" required></label>
      <label>Unit Price<br><input type="number" step="0.01" name="price" id="p_price" value="0"></label>
      <label>Stock<br><input type="number" step="0.01" name="stock" id="p_stock" value="0"></label>
      <div class="right"><button class="btn success">Save</button></div>
    </form>
    <table>
      <tr><th>ID</th><th>SKU</th><th>Name</th><th>Price</th><th>Stock</th><th class="no-print"></th></tr>
      <?php foreach($products as $p): ?>
      <tr>
        <td><?=h($p['id'])?></td>
        <td><?=h($p['sku'])?></td>
        <td><?=h($p['name'])?></td>
        <td><?=CURRENCY?> <?=number_format($p['price'],2)?></td>
        <td class="<?= $p['stock']<=5?'low':'' ?>"><?=h($p['stock'])?></td>
        <td class="no-print">
          <button class="btn" onclick='editProd(<?=json_encode($p)?>)'>Edit</button>
          <form style="display:inline" method="post" action="?action=delete_product" onsubmit="return confirm('Delete product?')">
            <input type="hidden" name="id" value="<?=h($p['id'])?>">
            <button class="btn danger">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <script>
    function toggleProdForm(){ document.getElementById('prodForm').classList.toggle('hidden'); }
    function editProd(p){
      document.getElementById('prodForm').classList.remove('hidden');
      p_id.value=p.id; p_sku.value=p.sku||''; p_name.value=p.name; p_price.value=p.price; p_stock.value=p.stock;
    }
  </script>
<?php elseif($view==='new_invoice'): ?>
  <div class="card">
    <h2>New Invoice</h2>
    <form id="invForm" method="post" action="?action=save_invoice">
      <div class="grid grid-2">
        <label>Customer Name<br><input name="customer_name" placeholder="Walk-in"></label>
        <label>Phone Number<br><input name="customer_phone" placeholder="0188***542"></label>
        <label>Address<br><input name="customer_address" placeholder="share your address"></label>
        <label>Date<br><input type="date" name="issue_date" value="<?=h(date('Y-m-d'))?>"></label>
      </div>
      <div class="grid grid-2">
        <label>Invoice Discount (amount)<br><input type="number" step="0.01" name="invoice_discount" id="invoice_discount" value="0"></label>
        <label>Tax Rate (%)<br><input type="number" step="0.01" name="tax_rate" id="tax_rate" value="<?=h(DEFAULT_TAX_RATE)?>"></label>
      </div>
      <div class="grid grid-2">
        <label>Initial Payment (collection)<br><input type="number" step="0.01" name="pay_amount" id="pay_amount" value="0"></label>
        <label>Payment Method<br>
          <select name="pay_method">
            <option value="Cash">Cash</option>
            <option value="Card">Card</option>
            <option value="Bank">Bank</option>
            <option value="BKash">BKash</option>
          </select>
        </label>
      </div>
      <label>Notes<br><input name="notes" placeholder="Optional note"></label>

      <h3>Items</h3>
      <div class="no-print" style="margin:.5rem 0">
        <button type="button" class="btn" onclick="addRow()">+ Add Row</button>
      </div>
      <table id="itemsTbl">
        <thead>
          <tr>
            <th style="width:30%">Product</th>
            <th>Unit Price</th>
            <th>Stock</th>
            <th>Qty</th>
            <th>Unit Discount</th>
            <th>Line Total</th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr><th colspan="5" style="text-align:right">Subtotal</th><th id="subtotal">0.00</th><th></th></tr>
          <tr><th colspan="5" style="text-align:right">Invoice Discount</th><th id="invdisc">0.00</th><th></th></tr>
          <tr><th colspan="5" style="text-align:right">Tax (<span id="taxrateLbl">0</span>%)</th><th id="taxamt">0.00</th><th></th></tr>
          <tr><th colspan="5" style="text-align:right">Grand Total</th><th id="grand">0.00</th><th></th></tr>
          <tr><th colspan="5" style="text-align:right">Paid Now</th><th id="paidnow">0.00</th><th></th></tr>
          <tr><th colspan="5" style="text-align:right">Due</th><th id="due">0.00</th><th></th></tr>
        </tfoot>
      </table>

      <input type="hidden" name="items_json" id="items_json">
      <div class="right no-print" style="margin-top:1rem">
        <button class="btn success">Save Invoice</button>
      </div>
    </form>
  </div>
  <script>
    const PRODUCTS = <?=json_encode($products, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
    const tbody = document.querySelector('#itemsTbl tbody');
    const subtotalEl = document.getElementById('subtotal');
    const invdiscEl = document.getElementById('invdisc');
    const taxamtEl = document.getElementById('taxamt');
    const taxrateLbl = document.getElementById('taxrateLbl');
    const grandEl = document.getElementById('grand');
    const dueEl = document.getElementById('due');
    const paidNowEl = document.getElementById('paidnow');

    function fmt(n){return (Number(n)||0).toFixed(2)}

    function addRow(){
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><select class="prod" onchange="onProdChange(this)"><option value="">— Select Items —</option>${PRODUCTS.map(p=>`<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}">${p.name}${p.sku?` (${p.sku})`:''}</option>`).join('')}</select></td>
        <td class="price">0.00</td>
        <td class="stock">0</td>
        <td><input type="number" step="0.01" value="1" class="qty" oninput="recalc()"></td>
        <td><input type="number" step="0.01" value="0" class="line_discount" oninput="recalc()"></td>
        <td class="line_total">0.00</td>
        <td class="no-print"><button type="button" class="btn danger" onclick="this.closest('tr').remove(); recalc();">×</button></td>
      `;
      tbody.appendChild(tr);
    }

    function onProdChange(sel){
      const opt = sel.options[sel.selectedIndex];
      const price = parseFloat(opt.getAttribute('data-price')||'0');
      const stock = parseFloat(opt.getAttribute('data-stock')||'0');
      sel.closest('tr').querySelector('.price').textContent = fmt(price);
      sel.closest('tr').querySelector('.stock').textContent = fmt(stock);
      recalc();
    }

    function recalc(){
      let subtotal=0;
      const rows = [...tbody.querySelectorAll('tr')];
      rows.forEach(r=>{
        const sel=r.querySelector('.prod');
        const opt= sel && sel.options[sel.selectedIndex];
        const price= parseFloat((opt && opt.getAttribute('data-price'))||r.querySelector('.price').textContent||'0');
        const qty = parseFloat(r.querySelector('.qty').value||'0');
        const disc = parseFloat(r.querySelector('.line_discount').value||'0');
        const lineTotal = Math.max(0, qty*price - disc);
        r.querySelector('.line_total').textContent = fmt(lineTotal);
        subtotal += lineTotal;
      });
      subtotalEl.textContent = fmt(subtotal);
      const invDisc = parseFloat(document.getElementById('invoice_discount').value||'0');
      invdiscEl.textContent = fmt(invDisc);
      const taxRate = parseFloat(document.getElementById('tax_rate').value||'0');
      taxrateLbl.textContent = fmt(taxRate);
      const taxable = Math.max(0, subtotal - invDisc);
      const taxAmt = taxable * (taxRate/100);
      taxamtEl.textContent = fmt(taxAmt);
      const grand = Math.max(0, taxable + taxAmt);
      grandEl.textContent = fmt(grand);
      const paidNow = parseFloat(document.getElementById('pay_amount').value||'0');
      paidNowEl.textContent = fmt(paidNow);
      const due = Math.max(0, grand - paidNow);
      dueEl.textContent = fmt(due);

      // pack items JSON
      const items = rows.map(r=>{
        const sel=r.querySelector('.prod');
        return {
          product_id: Number(sel.value||0),
          qty: Number(r.querySelector('.qty').value||0),
          unit_price: Number((sel.options[sel.selectedIndex]&&sel.options[sel.selectedIndex].getAttribute('data-price'))||r.querySelector('.price').textContent||0),
          line_discount: Number(r.querySelector('.line_discount').value||0)
        }
      }).filter(it=>it.product_id>0 && it.qty>0);
      document.getElementById('items_json').value = JSON.stringify(items);
    }

    document.getElementById('invoice_discount').addEventListener('input', recalc);
    document.getElementById('tax_rate').addEventListener('input', recalc);
    document.getElementById('pay_amount').addEventListener('input', recalc);
    addRow();
  </script>
<?php elseif($view==='invoices'): ?>
  <div class="card">
    <h2>Invoices</h2>
    <?php $invs=$pdo->query("SELECT id, number, customer_name, issue_date, total, paid, due, status FROM invoices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); ?>
    <table>
      <tr><th>#</th><th>Customer</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th></th></tr>
      <?php foreach($invs as $iv): ?>
      <tr>
        <td><?=h($iv['number'])?></td>
        <td><?=h($iv['customer_name'])?></td>
        <td><?=h($iv['issue_date'])?></td>
        <td><?=CURRENCY?> <?=number_format($iv['total'],2)?></td>
        <td><?=CURRENCY?> <?=number_format($iv['paid'],2)?></td>
        <td class="<?= $iv['due']>0?'warn':'' ?>"><?=CURRENCY?> <?=number_format($iv['due'],2)?></td>
        <td><?=h($iv['status'])?></td>
        <td class="right"><a class="btn" href="<?=h(route('invoice_view',['id'=>$iv['id']]))?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php elseif($view==='invoice_view'): $id=(int)getv('id'); $iv=$pdo->prepare("SELECT * FROM invoices WHERE id=?"); $iv->execute([$id]); $inv=$iv->fetch(PDO::FETCH_ASSOC); if(!$inv){ echo '<div class="card">Not found</div>'; } else { $items=$pdo->prepare("SELECT ii.*, p.sku FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE invoice_id=?"); $items->execute([$id]); $rows=$items->fetchAll(PDO::FETCH_ASSOC); $pays=$pdo->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY id DESC"); $pays->execute([$id]); $payments=$pays->fetchAll(PDO::FETCH_ASSOC); $rets=$pdo->prepare("SELECT r.*, p.sku FROM returns r LEFT JOIN products p ON p.id=r.product_id WHERE r.invoice_id=? ORDER BY r.id DESC"); $rets->execute([$id]); $returns=$rets->fetchAll(PDO::FETCH_ASSOC); ?>
  <div class="card">
    <div style="display: flex;align-content: center;justify-content: space-between;align-items: center;">
        <div>
            <strong>Date: &nbsp;&nbsp;</strong> <span><?=h($inv['issue_date'])?></span>
        </div>
        <div>
            <strong>Status: &nbsp;</strong> <span><?=h($inv['status'])?></span>
        </div>
    </div>
    <div class="right no-print">
      <button class="btn primary" onclick="window.print()">Print / Save PDF</button>
      <?php if($inv['status']!=='canceled'): ?>
      <form method="post" action="?action=cancel_invoice" onsubmit="return confirm('Cancel invoice?')">
        <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
        <button class="btn danger">Cancel Invoice</button>
      </form>
      <?php endif; ?>
    </div>
    <div style="text-align: center;">
        <strong style="font-size: 28px;"><?=h(BUSINESS_NAME)?></strong> <br>
        <strong> Wari, Dhaka-1000 </strong> <br>
        <strong>Email:</strong> example@gmail.com <strong>Mobile:</strong> 01856984525  <br>
    </div>
    <h2 style="text-align: center;">Invoice</h2>
        <div style="display: flex;align-content: center;justify-content: center;align-items: center;">
            <div style="text-align: end;">
                <strong>Party: &nbsp;&nbsp;&nbsp;&nbsp;</strong> <br>
                <strong> &nbsp;&nbsp;&nbsp;&nbsp;</strong><br>
                <strong> &nbsp;&nbsp;&nbsp;&nbsp;</strong><br>
            </div>
            <div style="text-align: start;"> 
                <span><?= h(isset($inv['customer_name']) ? $inv['customer_name'] : 'Walk-in') ?></span><br>
                <span><?= h(isset($inv['customer_phone']) ? $inv['customer_phone'] : '') ?></span><br>
                <span><?= h(isset($inv['customer_address']) ? $inv['customer_address'] : '') ?></span><br>
            </div>
      </div>
    <div class="grid grid-2">
        <div>    <h4>Invoice #<?=h($inv['number'])?></h4></div>

      <div class="right">
        <div style="
    display: flex;
    flex-direction: row;
    align-content: center;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
"><span class="pill">Total</span> <h2 style="margin:0; text-align:right;"><?=CURRENCY?> <?=number_format($inv['total'],2)?></h2></div>
      </div>
    </div>

    <table>
      <tr><th>Description</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Unit Discount</th><th>Line Total</th></tr>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=h($r['description'])?></td>
          <td><?=h($r['sku'])?></td>
          <td><?=h($r['qty'])?></td>
          <td><?=CURRENCY?> <?=number_format($r['unit_price'],2)?></td>
          <td><?=CURRENCY?> <?=number_format($r['line_discount'],2)?></td>
          <td><?=CURRENCY?> <?=number_format($r['line_total'],2)?></td>
        </tr>
      <?php endforeach; ?>
      <tr><th colspan="5" style="text-align:right">Subtotal</th><th><?=CURRENCY?> <?=number_format($inv['subtotal'],2)?></th></tr>
      <tr><th colspan="5" style="text-align:right">Invoice Discount</th><th><?=CURRENCY?> <?=number_format($inv['discount'],2)?></th></tr>
      <tr><th colspan="5" style="text-align:right">Tax (<?=number_format($inv['tax_rate'],2)?>%)</th><th><?=CURRENCY?> <?=number_format($inv['tax_amount'],2)?></th></tr>
      <tr><th colspan="5" style="text-align:right">Grand Total</th><th><?=CURRENCY?> <?=number_format($inv['total'],2)?></th></tr>
      <tr><th colspan="5" style="text-align:right">Paid</th><th><?=CURRENCY?> <?=number_format($inv['paid'],2)?></th></tr>
      <tr><th colspan="5" style="text-align:right">Due</th><th><?=CURRENCY?> <?=number_format($inv['due'],2)?></th></tr>
    </table>

    <?php if($inv['notes']): ?><p class="muted"><strong>Notes:</strong> <?=h($inv['notes'])?></p><?php endif; ?>
    <p class="muted">Return policy: Items eligible for return within <?=RETURN_WINDOW_DAYS?> days of the invoice date, in original condition. Returns will credit invoice and restock items.</p>

    <div class="grid grid-2 no-print">
      <div class="card">
        <h3>Add / Edit Payments (Collections)</h3>
        <form method="post" action="?action=add_payment" class="grid">
          <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
          <label>Amount<br><input type="number" step="0.01" name="amount" value="<?=h($inv['due'])?>"></label>
          <label>Method<br>
            <select name="method"><option>Cash</option><option>Card</option><option>Bank</option><option>BKash</option></select>
          </label>
          <label>Note<br><input name="note" placeholder="Optional"></label>
          <div class="right"><button class="btn success">Record Payment</button></div>
        </form>
        <h4>Payments</h4>
        <table>
          <tr><th>Date</th><th>Amount</th><th>Method</th><th>Note</th><th class="no-print"></th></tr>
          <?php foreach($payments as $p): ?>
          <tr>
            <td><?=h($p['paid_at'])?></td>
            <td><?=CURRENCY?> <?=number_format($p['amount'],2)?></td>
            <td><?=h($p['method'])?></td>
            <td><?=h($p['note'])?></td>
            <td class="no-print">
              <details>
                <summary>Edit</summary>
                <form method="post" action="?action=update_payment" class="grid" style="margin-top:.5rem">
                  <input type="hidden" name="id" value="<?=h($p['id'])?>">
                  <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
                  <label>Amount<br><input type="number" step="0.01" name="amount" value="<?=h($p['amount'])?>"></label>
                  <label>Method<br><input name="method" value="<?=h($p['method'])?>"></label>
                  <label>Note<br><input name="note" value="<?=h($p['note'])?>"></label>
                  <div class="right">
                    <button class="btn success">Update</button>
                  </div>
                </form>
                <form method="post" action="?action=delete_payment" onsubmit="return confirm('Delete payment?')" class="right">
                  <input type="hidden" name="id" value="<?=h($p['id'])?>">
                  <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
                  <button class="btn danger">Delete</button>
                </form>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div class="card">
        <h3>Return Items</h3>
        <form method="post" action="?action=process_return" onsubmit="return confirm('Process return?')">
          <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
          <label>Item<br>
            <select name="item_id" required>
              <?php foreach($rows as $r): ?>
                <option value="<?=h($r['id'])?>"><?="#{$r['id']} - ".h($r['description'])." (Sold: ".h($r['qty']).")"?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Quantity to return<br><input type="number" step="0.01" name="qty" value="1" required></label>
          <label>Reason<br><input name="reason" placeholder="Optional"></label>
          <div class="right"><button class="btn danger">Process Return</button></div>
        </form>
        <?php if($returns): ?>
        <h4>Returns</h4>
        <table>
          <tr><th>Date</th><th>Item</th><th>Qty</th><th>Reason</th><th class="no-print"></th></tr>
          <?php foreach($returns as $r): ?>
          <tr>
            <td><?=h($r['returned_at'])?></td>
            <td><?=h($r['sku'] ?: ('#'.$r['product_id']))?></td>
            <td><?=h($r['qty'])?></td>
            <td><?=h($r['reason'])?></td>
            <td class="no-print">
              <details>
                <summary>Edit</summary>
                <form method="post" action="?action=update_return" class="grid" style="margin-top:.5rem">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
                  <label>Qty<br><input type="number" step="0.01" name="qty" value="<?=h($r['qty'])?>"></label>
                  <label>Reason<br><input name="reason" value="<?=h($r['reason'])?>"></label>
                  <div class="right"><button class="btn success">Update</button></div>
                </form>
                <form method="post" action="?action=delete_return" onsubmit="return confirm('Delete return?')" class="right">
                  <input type="hidden" name="id" value="<?=h($r['id'])?>">
                  <input type="hidden" name="invoice_id" value="<?=h($inv['id'])?>">
                  <button class="btn danger">Delete</button>
                </form>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php } endif; ?>
</div>
</body>
</html>
