<?php
/**
 * SabrySQL - Web Database Management Tool v3
 * Multi-connection, resizable columns, privilege-aware
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================================
// ENV PARSER — Multi-connection
// ============================================================
function loadEnv($path = __DIR__ . '/.env') {
    if (!file_exists($path)) die("File .env non trovato in: $path");
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $config[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $config;
}

function getConnections($env) {
    $connections = [];
    // Check numbered connections (1-99)
    for ($i = 1; $i <= 99; $i++) {
        $host = $env["DB_HOST_$i"] ?? null;
        if ($host === null) {
            if ($i === 1) {
                // Fallback: check non-numbered keys
                $host = $env['DB_HOST'] ?? null;
                if ($host) {
                    $connections[1] = [
                        'host' => $host,
                        'port' => (int)($env['DB_PORT'] ?? 3306),
                        'user' => $env['DB_USERNAME'] ?? 'root',
                        'pass' => $env['DB_PASSWORD'] ?? '',
                        'charset' => $env['DB_CHARSET'] ?? 'utf8mb4',
                        'label' => $env['DB_LABEL'] ?? $host,
                    ];
                }
            }
            continue;
        }
        $connections[$i] = [
            'host' => $host,
            'port' => (int)($env["DB_PORT_$i"] ?? 3306),
            'user' => $env["DB_USERNAME_$i"] ?? 'root',
            'pass' => $env["DB_PASSWORD_$i"] ?? '',
            'charset' => $env["DB_CHARSET_$i"] ?? 'utf8mb4',
            'label' => $env["DB_LABEL_$i"] ?? "$host:".($env["DB_PORT_$i"] ?? 3306),
        ];
    }
    return $connections;
}

$env = loadEnv();
$allConnections = getConnections($env);

if (empty($allConnections)) {
    die("Nessuna connessione configurata nel file .env");
}

// Current connection ID (from session or default to first)
$connId = $_GET['conn'] ?? $_POST['conn'] ?? $_SESSION['sabrysql_conn'] ?? array_key_first($allConnections);
$connId = (int)$connId;
if (!isset($allConnections[$connId])) $connId = array_key_first($allConnections);
$_SESSION['sabrysql_conn'] = $connId;

$conn = $allConnections[$connId];

function quoteIdentifier($id) { return '`' . str_replace('`', '``', $id) . '`'; }

// ============================================================
// DATABASE CONNECTION
// ============================================================
$pdo = null;
$connError = null;
try {
    $pdo = new PDO(
        "mysql:host={$conn['host']};port={$conn['port']};charset={$conn['charset']}",
        $conn['user'], $conn['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    $connError = $e->getMessage();
}

function getUserPrivileges($pdo) {
    $privs = [
        'SELECT'=>false,'INSERT'=>false,'UPDATE'=>false,'DELETE'=>false,
        'CREATE'=>false,'DROP'=>false,'ALTER'=>false,'INDEX'=>false,
        'PROCESS'=>false,'SUPER'=>false,
        'CREATE VIEW'=>false, 'CREATE ROUTINE'=>false, 'ALTER ROUTINE'=>false
    ];
    if (!$pdo) return $privs;
    try {
        $grants = $pdo->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($grants as $grant) {
            $upper = strtoupper($grant);
            if (str_contains($upper, 'ALL PRIVILEGES')) { foreach ($privs as $k => &$v) $v = true; return $privs; }
            foreach ($privs as $k => &$v) { if (str_contains($upper, strtoupper($k))) $v = true; }
        }
    } catch (Exception $e) { $privs['SELECT'] = true; }
    return $privs;
}

// ============================================================
// AJAX API HANDLER
// ============================================================
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $isFile = in_array($action, ['export', 'export_database']);
    if (!$isFile) header('Content-Type: application/json; charset=utf-8');

    if ($action === 'connections') {
        $list = [];
        foreach ($allConnections as $id => $c) {
            $list[] = ['id' => $id, 'label' => $c['label'], 'host' => $c['host'], 'port' => $c['port'], 'user' => $c['user'], 'active' => ($id === $connId)];
        }
        echo json_encode(['connections' => $list, 'current' => $connId]);
        exit;
    }

    if ($action === 'switch_connection') {
        $newId = (int)($_GET['id'] ?? $connId);
        if (isset($allConnections[$newId])) {
            $_SESSION['sabrysql_conn'] = $newId;
            echo json_encode(['success' => true, 'id' => $newId]);
        } else {
            echo json_encode(['error' => 'Connessione non trovata']);
        }
        exit;
    }

    if ($action === 'conn_status') {
        if ($pdo) {
            $cid = $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
            echo json_encode(['connected'=>true, 'connection_id'=>$cid, 'error'=>null]);
        } else {
            echo json_encode(['connected'=>false, 'connection_id'=>null, 'error'=>$connError]);
        }
        exit;
    }

    if ($action === 'kill_connection') {
        $target = (int)$_POST['connection_id'];
        $pdo->exec("KILL $target");
        echo json_encode(['success'=>true]);
        exit;
    }

    if (!$pdo) {
        if (!$isFile) echo json_encode(['error' => 'Connessione non attiva: ' . $connError]);
        exit;
    }

    try {
        $privileges = getUserPrivileges($pdo);

        switch ($action) {
            case 'privileges':
                echo json_encode(['privileges' => $privileges]); break;

            case 'databases':
                echo json_encode(['databases' => $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN)]); break;

            case 'tables':
                $db = $_GET['db'] ?? '';
                $pdo->exec("USE " . quoteIdentifier($db));
                $stmt = $pdo->query("SHOW FULL TABLES");
                $tables = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) $tables[] = ['name' => $row[0], 'type' => $row[1]];
                echo json_encode(['tables' => $tables]); break;

            case 'columns':
                $db = $_GET['db'] ?? ''; $table = $_GET['table'] ?? '';
                $pdo->exec("USE " . quoteIdentifier($db));
                echo json_encode(['columns' => $pdo->query("SHOW FULL COLUMNS FROM " . quoteIdentifier($table))->fetchAll()]); break;

            case 'primary_keys':
                $db = $_GET['db'] ?? ''; $table = $_GET['table'] ?? '';
                $pdo->exec("USE " . quoteIdentifier($db));
                $stmt = $pdo->query("SHOW KEYS FROM " . quoteIdentifier($table) . " WHERE Key_name = 'PRIMARY'");
                $pks = [];
                while ($row = $stmt->fetch()) $pks[] = $row['Column_name'];
                echo json_encode(['primary_keys' => $pks]); break;

            case 'indexes':
                $db = $_GET['db'] ?? ''; $table = $_GET['table'] ?? '';
                $pdo->exec("USE " . quoteIdentifier($db));
                echo json_encode(['indexes' => $pdo->query("SHOW INDEX FROM " . quoteIdentifier($table))->fetchAll()]); break;

            case 'data':
                if (!$privileges['SELECT']) { echo json_encode(['error' => 'SELECT non concesso']); break; }
                $db=$_GET['db']??'';$table=$_GET['table']??'';$offset=(int)($_GET['offset']??0);$limit=(int)($_GET['limit']??100);
                $sort=$_GET['sort']??'';$sortDir=strtoupper($_GET['sortdir']??'ASC')==='DESC'?'DESC':'ASC';$filter=$_GET['filter']??'';
                $pdo->exec("USE ".quoteIdentifier($db));
                $where='';
                if($filter){$cols=$pdo->query("SHOW COLUMNS FROM ".quoteIdentifier($table))->fetchAll(PDO::FETCH_COLUMN);$conds=[];foreach($cols as $col)$conds[]=quoteIdentifier($col)." LIKE ".$pdo->quote("%$filter%");$where=" WHERE ".implode(' OR ',$conds);}
                $total=(int)$pdo->query("SELECT COUNT(*) FROM ".quoteIdentifier($table).$where)->fetchColumn();
                $orderBy=$sort?" ORDER BY ".quoteIdentifier($sort)." $sortDir":'';
                echo json_encode(['rows'=>$pdo->query("SELECT * FROM ".quoteIdentifier($table).$where.$orderBy." LIMIT $limit OFFSET $offset")->fetchAll(),'total'=>$total]); break;

            case 'query':
                $db=$_POST['db']??$_GET['db']??'';$sql=$_POST['sql']??'';
                if($db)$pdo->exec("USE ".quoteIdentifier($db));
                $fw=strtoupper(strtok(trim($sql)," \t\n\r"));
                $pm=[
                    'SELECT'=>'SELECT','INSERT'=>'INSERT','UPDATE'=>'UPDATE','DELETE'=>'DELETE',
                    'CREATE'=>'CREATE','DROP'=>'DROP','ALTER'=>'ALTER','SHOW'=>'SELECT',
                    'DESCRIBE'=>'SELECT','EXPLAIN'=>'SELECT','TRUNCATE'=>'DROP',
                    'CREATE VIEW'=>'CREATE', 'CREATE PROCEDURE'=>'CREATE', 'CREATE FUNCTION'=>'CREATE',
                    'DROP VIEW'=>'DROP', 'ALTER ROUTINE'=>'ALTER'
                ];
                $nd=$pm[$fw]??null;
                if($nd&&!$privileges[$nd]){echo json_encode(['error'=>"$nd non concesso"]);break;}
                $results=[];
                $queries=preg_split('/;\s*(?=(?:[^\']*\'[^\']*\')*[^\']*$)/',$sql);
                foreach($queries as $q){$q=trim($q);if(!$q)continue;
                    $st=microtime(true);$stmt=$pdo->prepare($q);$stmt->execute();$dur=round((microtime(true)-$st)*1000,2);
                    $f=strtoupper(strtok(trim($q)," \t\n\r"));
                    if(in_array($f,['SELECT','SHOW','DESCRIBE','EXPLAIN'])){$rows=$stmt->fetchAll();$results[]=['type'=>'resultset','rows'=>$rows,'rowCount'=>count($rows),'duration'=>$dur,'sql'=>$q];}
                    else{$results[]=['type'=>'affected','affected'=>$stmt->rowCount(),'duration'=>$dur,'sql'=>$q];}
                }
                echo json_encode(['results'=>$results]); break;

            case 'serverinfo':
                $vars=[];$stmt=$pdo->query("SHOW VARIABLES LIKE 'version%'");while($row=$stmt->fetch())$vars[$row['Variable_name']]=$row['Value'];
                $vars['current_user']=$pdo->query("SELECT CURRENT_USER()")->fetchColumn();
                $row=$pdo->query("SHOW VARIABLES LIKE 'hostname'")->fetch();$vars['hostname']=$row['Value']??$conn['host'];
                $status=[];$stmt=$pdo->query("SHOW GLOBAL STATUS");while($row=$stmt->fetch())$status[$row['Variable_name']]=$row['Value'];
                echo json_encode(['variables'=>$vars,'status'=>$status]); break;

            case 'dbinfo':
                $db=$_GET['db']??'';$pdo->exec("USE ".quoteIdentifier($db));$tables=$pdo->query("SHOW TABLE STATUS")->fetchAll();
                $ts=0;$tr=0;foreach($tables as $t){$ts+=($t['Data_length']??0)+($t['Index_length']??0);$tr+=$t['Rows']??0;}
                echo json_encode(['tables'=>$tables,'totalSize'=>$ts,'totalRows'=>$tr,'tableCount'=>count($tables)]); break;

            case 'create_table':
                if(!$privileges['CREATE']){echo json_encode(['error'=>'CREATE non concesso']);break;}
                $db=$_POST['db']??'';$tn=$_POST['table_name']??'';$columns=json_decode($_POST['columns']??'[]',true);$engine=$_POST['engine']??'InnoDB';$collation=$_POST['collation']??'utf8mb4_general_ci';
                $pdo->exec("USE ".quoteIdentifier($db));$cd=[];$pk=[];
                foreach($columns as $col){$d=quoteIdentifier($col['name']).' '.$col['type'];if(!empty($col['length']))$d.='('.(int)$col['length'].')';if(!empty($col['notnull']))$d.=' NOT NULL';if(isset($col['default'])&&$col['default']!==''){$u=strtoupper($col['default']);if($u==='NULL')$d.=' DEFAULT NULL';elseif($u==='CURRENT_TIMESTAMP')$d.=' DEFAULT CURRENT_TIMESTAMP';else $d.=' DEFAULT '.$pdo->quote($col['default']);}if(!empty($col['auto_increment']))$d.=' AUTO_INCREMENT';if(!empty($col['primary']))$pk[]=quoteIdentifier($col['name']);$cd[]=$d;}
                if($pk)$cd[]='PRIMARY KEY ('.implode(', ',$pk).')';
                $pdo->exec("CREATE TABLE ".quoteIdentifier($tn)." (\n".implode(",\n",$cd)."\n) ENGINE=$engine DEFAULT CHARSET=utf8mb4 COLLATE=$collation");
                echo json_encode(['success'=>true]); break;

            case 'drop_table':
                if(!$privileges['DROP']){echo json_encode(['error'=>'DROP non concesso']);break;}
                $pdo->exec("USE ".quoteIdentifier($_POST['db']??''));$pdo->exec("DROP TABLE ".quoteIdentifier($_POST['table']??''));
                echo json_encode(['success'=>true]); break;

            case 'truncate_table':
                if(!$privileges['DROP']){echo json_encode(['error'=>'DROP non concesso']);break;}
                $pdo->exec("USE ".quoteIdentifier($_POST['db']??''));$pdo->exec("TRUNCATE TABLE ".quoteIdentifier($_POST['table']??''));
                echo json_encode(['success'=>true]); break;

            case 'create_database':
                if(!$privileges['CREATE']){echo json_encode(['error'=>'CREATE non concesso']);break;}
                $dn=$_POST['db_name']??'';$co=$_POST['collation']??'utf8mb4_general_ci';$cs=explode('_',$co)[0];
                $pdo->exec("CREATE DATABASE ".quoteIdentifier($dn)." CHARACTER SET $cs COLLATE $co");
                echo json_encode(['success'=>true]); break;

            case 'drop_database':
                if(!$privileges['DROP']){echo json_encode(['error'=>'DROP non concesso']);break;}
                $pdo->exec("DROP DATABASE ".quoteIdentifier($_POST['db_name']??''));
                echo json_encode(['success'=>true]); break;

            case 'insert_row':
                if(!$privileges['INSERT']){echo json_encode(['error'=>'INSERT non concesso']);break;}
                $db=$_POST['db']??'';$table=$_POST['table']??'';$data=json_decode($_POST['data']??'{}',true);
                $pdo->exec("USE ".quoteIdentifier($db));$cs=[];$vs=[];$ps=[];
                foreach($data as $c=>$v){$cs[]=quoteIdentifier($c);$vs[]='?';$ps[]=$v===''?null:$v;}
                $stmt=$pdo->prepare("INSERT INTO ".quoteIdentifier($table)." (".implode(',',$cs).") VALUES (".implode(',',$vs).")");$stmt->execute($ps);
                echo json_encode(['success'=>true,'lastInsertId'=>$pdo->lastInsertId()]); break;

            case 'update_row':
                if(!$privileges['UPDATE']){echo json_encode(['error'=>'UPDATE non concesso']);break;}
                $db=$_POST['db']??'';$table=$_POST['table']??'';$data=json_decode($_POST['data']??'{}',true);$where=json_decode($_POST['where']??'{}',true);
                $pdo->exec("USE ".quoteIdentifier($db));$ss=[];$ps=[];
                foreach($data as $c=>$v){$ss[]=quoteIdentifier($c)." = ?";$ps[]=$v===''?null:$v;}
                $ws=[];foreach($where as $c=>$v){if($v===null)$ws[]=quoteIdentifier($c)." IS NULL";else{$ws[]=quoteIdentifier($c)." = ?";$ps[]=$v;}}
                $stmt=$pdo->prepare("UPDATE ".quoteIdentifier($table)." SET ".implode(', ',$ss)." WHERE ".implode(' AND ',$ws)." LIMIT 1");$stmt->execute($ps);
                echo json_encode(['success'=>true,'affected'=>$stmt->rowCount()]); break;

            case 'delete_row':
                if(!$privileges['DELETE']){echo json_encode(['error'=>'DELETE non concesso']);break;}
                $db=$_POST['db']??'';$table=$_POST['table']??'';$where=json_decode($_POST['where']??'{}',true);
                $pdo->exec("USE ".quoteIdentifier($db));$ws=[];$ps=[];
                foreach($where as $c=>$v){if($v===null)$ws[]=quoteIdentifier($c)." IS NULL";else{$ws[]=quoteIdentifier($c)." = ?";$ps[]=$v;}}
                $stmt=$pdo->prepare("DELETE FROM ".quoteIdentifier($table)." WHERE ".implode(' AND ',$ws)." LIMIT 1");$stmt->execute($ps);
                echo json_encode(['success'=>true,'affected'=>$stmt->rowCount()]); break;

            case 'export':
                $db=$_GET['db']??'';$table=$_GET['table']??'';$format=$_GET['format']??'sql';
                $pdo->exec("USE ".quoteIdentifier($db));
                if($format==='csv'){
                    header('Content-Type: text/csv; charset=utf-8');header("Content-Disposition: attachment; filename=\"{$table}.csv\"");
                    $out=fopen('php://output','w');fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));$stmt=$pdo->query("SELECT * FROM ".quoteIdentifier($table));$first=true;
                    while($row=$stmt->fetch()){if($first){fputcsv($out,array_keys($row),';','"','\\');$first=false;}fputcsv($out,$row,';','"','\\');}fclose($out);
                }else{
                    header('Content-Type: application/sql; charset=utf-8');header("Content-Disposition: attachment; filename=\"{$table}.sql\"");
                    echo "-- SabrySQL Export\n-- ".date('Y-m-d H:i:s')."\n\n";
                    $cr=$pdo->query("SHOW CREATE TABLE ".quoteIdentifier($table))->fetch();
                    echo "DROP TABLE IF EXISTS ".quoteIdentifier($table).";\n\n".($cr['Create Table']??'').";\n\n";
                    $stmt=$pdo->query("SELECT * FROM ".quoteIdentifier($table));
                    while($row=$stmt->fetch()){echo "INSERT INTO ".quoteIdentifier($table)." VALUES (".implode(', ',array_map(fn($v)=>$v===null?'NULL':$pdo->quote($v),$row)).");\n";}
                }exit;

            case 'export_database':
                $db=$_GET['db']??'';$incData=($_GET['include_data']??'1')==='1';$tp=$_GET['tables']??'';$st=$tp?explode(',',$tp):[];
                $pdo->exec("USE ".quoteIdentifier($db));
                header('Content-Type: application/sql; charset=utf-8');header("Content-Disposition: attachment; filename=\"{$db}_".date('Ymd_His').".sql\"");
                echo "-- SabrySQL Export: $db\n-- ".date('Y-m-d H:i:s')."\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
                if(empty($st))$st=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach($st as $table){$table=trim($table);if(!$table)continue;
                    echo "DROP TABLE IF EXISTS ".quoteIdentifier($table).";\n";
                    $cr=$pdo->query("SHOW CREATE TABLE ".quoteIdentifier($table))->fetch();echo ($cr['Create Table']??'').";\n\n";
                    if($incData){$stmt=$pdo->query("SELECT * FROM ".quoteIdentifier($table));while($row=$stmt->fetch()){echo "INSERT INTO ".quoteIdentifier($table)." VALUES (".implode(', ',array_map(fn($v)=>$v===null?'NULL':$pdo->quote($v),$row)).");\n";}echo "\n";}
                }echo "SET FOREIGN_KEY_CHECKS = 1;\n";exit;

            case 'show_create':
                $db=$_GET['db']??'';$table=$_GET['table']??'';$pdo->exec("USE ".quoteIdentifier($db));
                $row=$pdo->query("SHOW CREATE TABLE ".quoteIdentifier($table))->fetch();
                echo json_encode(['sql'=>$row['Create Table']??$row['Create View']??'']); break;

            case 'foreign_keys':
                $db=$_GET['db']??'';$table=$_GET['table']??'';
                $stmt=$pdo->prepare("SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL");
                $stmt->execute([$db,$table]);echo json_encode(['foreign_keys'=>$stmt->fetchAll()]); break;

            case 'processlist':
                if(!$privileges['PROCESS']){echo json_encode(['error'=>'PROCESS non concesso']);break;}
                echo json_encode(['processes'=>$pdo->query("SHOW FULL PROCESSLIST")->fetchAll()]); break;

            case 'kill_process':
                $pdo->exec("KILL ".(int)($_POST['id']??0));echo json_encode(['success'=>true]); break;

            case 'add_column':
                if(!$privileges['ALTER']){echo json_encode(['error'=>'ALTER non concesso']);break;}
                $db=$_POST['db']??'';$table=$_POST['table']??'';$col=json_decode($_POST['column']??'{}',true);
                $pdo->exec("USE ".quoteIdentifier($db));$def=quoteIdentifier($col['name']).' '.$col['type'];
                if(!empty($col['length']))$def.='('.(int)$col['length'].')';if(!empty($col['notnull']))$def.=' NOT NULL';
                if(isset($col['default'])&&$col['default']!=='')$def.=' DEFAULT '.$pdo->quote($col['default']);
                $pdo->exec("ALTER TABLE ".quoteIdentifier($table)." ADD COLUMN $def");echo json_encode(['success'=>true]); break;

            case 'drop_column':
                if(!$privileges['ALTER']){echo json_encode(['error'=>'ALTER non concesso']);break;}
                $pdo->exec("USE ".quoteIdentifier($_POST['db']??''));
                $pdo->exec("ALTER TABLE ".quoteIdentifier($_POST['table']??'')." DROP COLUMN ".quoteIdentifier($_POST['column']??''));
                echo json_encode(['success'=>true]); break;

            case 'rename_table':
                if(!$privileges['ALTER']){echo json_encode(['error'=>'ALTER non concesso']);break;}
                $pdo->exec("USE ".quoteIdentifier($_POST['db']??''));
                $pdo->exec("RENAME TABLE ".quoteIdentifier($_POST['old_name']??'')." TO ".quoteIdentifier($_POST['new_name']??''));
                echo json_encode(['success'=>true]); break;

            default: echo json_encode(['error'=>'Azione sconosciuta']);
        }
    } catch (PDOException $e) {
        if(!$isFile)echo json_encode(['error'=>$e->getMessage()]);
        else{header('Content-Type: text/plain');echo $e->getMessage();}
    }
    exit;
}

$connLabel = $conn['label'];
$connUser = $conn['user'];
$connHost = $conn['host'];
$connPort = $conn['port'];

$serverHost = $_SERVER['SERVER_NAME'];
$serverPort = $_SERVER['SERVER_PORT'];
?>
<!DOCTYPE html>
<html lang="it" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SabrySQL</title>
<style>
[data-theme="dark"]{--bg-primary:#1e1e2e;--bg-secondary:#181825;--bg-tertiary:#11111b;--bg-panel:#1e1e2e;--bg-input:#313244;--bg-hover:#313244;--bg-selected:#45475a;--bg-toolbar:#181825;--bg-tab-active:#1e1e2e;--bg-tab-inactive:#11111b;--border-color:#45475a;--text-primary:#cdd6f4;--text-secondary:#a6adc8;--text-muted:#7f849c;--accent:#89b4fa;--accent-hover:#74c7ec;--success:#a6e3a1;--warning:#f9e2af;--danger:#f38ba8;--scrollbar-bg:#181825;--scrollbar-thumb:#585b70;--row-alt:rgba(69,71,90,.15);--row-sel:#45475a;--row-msel:rgba(137,180,250,.18);--resize-handle:rgba(137,180,250,.4)}
[data-theme="light"]{--bg-primary:#eff1f5;--bg-secondary:#e6e9ef;--bg-tertiary:#dce0e8;--bg-panel:#eff1f5;--bg-input:#ccd0da;--bg-hover:#dce0e8;--bg-selected:#bcc0cc;--bg-toolbar:#e6e9ef;--bg-tab-active:#eff1f5;--bg-tab-inactive:#dce0e8;--border-color:#bcc0cc;--text-primary:#4c4f69;--text-secondary:#5c5f77;--text-muted:#8c8fa1;--accent:#1e66f5;--accent-hover:#2a6ef5;--success:#40a02b;--warning:#df8e1d;--danger:#d20f39;--scrollbar-bg:#e6e9ef;--scrollbar-thumb:#acb0be;--row-alt:rgba(188,192,204,.18);--row-sel:#bcc0cc;--row-msel:rgba(30,102,245,.12);--resize-handle:rgba(30,102,245,.4)}

*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:13px;color:var(--text-primary);background:var(--bg-primary);overflow:hidden;height:100vh}

select {
    appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%237f849c'><path d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/></svg>");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 14px;
    padding-right: 32px !important;
}

::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:var(--scrollbar-bg)}
::-webkit-scrollbar-thumb{background:var(--scrollbar-thumb);border-radius:5px;border:2px solid var(--scrollbar-bg)}
::-webkit-scrollbar-thumb:hover{background:var(--accent)}

input[type="checkbox"],input[type="radio"]{accent-color:var(--accent);width:14px;height:14px;vertical-align:middle}

#app{display:flex;flex-direction:column;height:100vh}

/* Toolbar */
#toolbar{background:var(--bg-toolbar);border-bottom:1px solid var(--border-color);padding:6px 8px;display:flex;align-items:center;gap:4px;flex-shrink:0;height:44px;overflow-x:auto}
.tb{background:transparent;border:1px solid transparent;color:var(--text-secondary);padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:6px;transition:all .15s;white-space:nowrap;height:30px;line-height:1}
.tb:hover{background:var(--bg-hover);border-color:var(--border-color);color:var(--text-primary)}.tb:disabled{opacity:.35;cursor:default;pointer-events:none}
.tb.active-conn{background:var(--accent);color:#fff;border-color:var(--accent)}
[data-theme="dark"] .tb.active-conn{color:var(--bg-primary)}
.ic{font-size:14px}.sep{width:1px;height:22px;background:var(--border-color);margin:0 6px}.tl{color:var(--text-muted);font-size:11px;margin:0 4px}

/* Main */
#main{display:flex;flex:1;overflow:hidden}
#sidebar{width:280px;min-width:180px;background:var(--bg-secondary);border-right:1px solid var(--border-color);display:flex;flex-direction:column;flex-shrink:0}

#sidebar-header{
    padding:12px 12px;
    background:var(--bg-toolbar);
    border-bottom:1px solid var(--border-color);
    display:flex;flex-direction:column;
    gap:10px;
}
#sidebar-header .logo{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:15px;
    font-weight:600;
}
#sidebar-header .logo-icon{
    font-size:22px;
}

.conn-selector{
    width:100%;
    box-sizing:border-box;
}
.conn-selector select{
    width:100%;
    box-sizing:border-box;
    padding:7px 10px;
    background:var(--bg-input);
    border:1px solid var(--border-color);
    border-radius:6px;
    color:var(--text-primary);
    font-size:13px;
    outline:none;
    cursor:pointer;
    height:34px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.conn-selector select:focus{border-color:var(--accent)}

.conn-status{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    color:var(--text-muted);
    min-height:24px;
}
.conn-status .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.conn-status .dot.ok{background:var(--success)}
.conn-status .dot.err{background:var(--danger)}
.conn-status .dot.unk{background:var(--warning)}
.conn-status .cid{margin-left:auto;font-family:monospace}
.conn-kill-btn{
    background:none;
    border:1px solid transparent;
    color:var(--danger);
    padding:3px 7px;
    border-radius:4px;
    cursor:pointer;
    font-size:11px;
    transition:all .15s;
    margin-left:6px;
}
.conn-kill-btn:hover{
    background:rgba(243,139,168,.15);
    border-color:var(--danger);
}

#sidebar-footer {
    padding:10px 12px;
    background:var(--bg-toolbar);
    border-top:1px solid var(--border-color);
    font-size:11px;
    color:var(--text-muted);
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:6px;
}

#tree-filter{margin:6px 8px;padding:7px 10px;background:var(--bg-input);border:1px solid var(--border-color);border-radius:6px;color:var(--text-primary);font-size:12px;outline:none;height:34px}
#tree-filter:focus{border-color:var(--accent)}#tree-filter::placeholder{color:var(--text-muted)}
#tree-container{flex:1;overflow-y:auto;padding:4px 0}
.ti{display:flex;align-items:center;padding:5px 8px 5px 12px;cursor:pointer;user-select:none;white-space:nowrap;font-size:12px;color:var(--text-secondary);transition:background .1s;min-height:26px}
.ti:hover{background:var(--bg-hover)}.ti.sel{background:var(--bg-selected);color:var(--text-primary)}
.ti .tic{width:18px;text-align:center;margin-right:6px;font-size:12px;flex-shrink:0}
.ti .tit{width:16px;text-align:center;margin-right:4px;font-size:10px;color:var(--text-muted);flex-shrink:0;transition:transform .15s}.ti .tit.open{transform:rotate(90deg)}
.ti .til{flex:1;overflow:hidden;text-overflow:ellipsis}.ti .tib{font-size:10px;color:var(--text-muted);margin-left:6px}
.tc{display:none}.tc.open{display:block}.ti.db .tic{color:var(--accent)}.ti.tbi{padding-left:36px}.ti.tbi .tic{color:var(--success)}.ti.vw .tic{color:var(--warning)}
#resize-handle{width:4px;cursor:col-resize;background:transparent;transition:background .2s;flex-shrink:0}#resize-handle:hover,#resize-handle.active{background:var(--accent)}
#content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
#content-tabs{display:flex;background:var(--bg-toolbar);border-bottom:1px solid var(--border-color);flex-shrink:0;overflow-x:auto}
.ct{padding:9px 16px;cursor:pointer;font-size:12px;color:var(--text-muted);border-right:1px solid var(--border-color);background:var(--bg-tab-inactive);white-space:nowrap;transition:all .15s;display:flex;align-items:center;gap:6px}
.ct:hover{color:var(--text-secondary);background:var(--bg-hover)}.ct.active{color:var(--text-primary);background:var(--bg-tab-active);border-bottom:2px solid var(--accent);margin-bottom:-1px}
.tp{display:none;flex:1;overflow:auto;min-height:0}.tp.active{display:flex;flex-direction:column}

/* DATA GRID — resizable columns */
.dt{display:flex;align-items:center;gap:6px;padding:8px 10px;background:var(--bg-toolbar);border-bottom:1px solid var(--border-color);flex-shrink:0;flex-wrap:wrap;min-height:40px}
.dt input,.dt select{background:var(--bg-input);border:1px solid var(--border-color);color:var(--text-primary);padding:6px 8px;border-radius:4px;font-size:12px;outline:none;height:28px}
.gc{flex:1;overflow:auto;position:relative}
.dg{border-collapse:collapse;font-size:12px;font-family:'Consolas','Monaco','Courier New',monospace;table-layout:fixed;width:max-content;min-width:100%}
.dg thead th{position:sticky;top:0;background:var(--bg-toolbar);color:var(--text-secondary);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;padding:8px 12px;border-bottom:2px solid var(--border-color);border-right:1px solid var(--border-color);cursor:pointer;user-select:none;white-space:nowrap;z-index:10;overflow:hidden;text-overflow:ellipsis;position:relative}
.dg thead th:hover{background:var(--bg-hover);color:var(--text-primary)}.dg thead th.sorted{color:var(--accent)}.dg thead th .sa{margin-left:4px;font-size:10px}

/* Column resize handle */
.col-resize-handle{position:absolute;right:0;top:0;bottom:0;width:5px;cursor:col-resize;background:transparent;z-index:20}
.col-resize-handle:hover,.col-resize-handle.active{background:var(--resize-handle)}

/* Checkbox column — fixed narrow width */
.dg .col-cb{width:36px!important;min-width:36px!important;max-width:36px!important;text-align:center;padding:4px 2px!important;resize:none}
.dg thead th.col-cb{cursor:default}
.dg thead th.col-cb:hover{background:var(--bg-toolbar)}

.dg tbody td{padding:6px 12px;border-bottom:1px solid var(--border-color);border-right:1px solid var(--border-color);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:default}
.dg tbody tr:nth-child(even) td{background:var(--row-alt)}.dg tbody tr:hover td{background:var(--bg-hover)!important}
.dg tbody tr.sel td{background:var(--row-sel)!important}.dg tbody tr.msel td{background:var(--row-msel)!important}
.dg tbody td.nv{color:var(--text-muted);font-style:italic}
.dg tbody td.pk-cell{color:var(--text-muted);cursor:not-allowed}
.dg tbody td.editing{padding:0;background:var(--bg-input)}.dg tbody td.editing input{width:100%;padding:6px 12px;background:var(--bg-input);border:2px solid var(--accent);color:var(--text-primary);font-family:inherit;font-size:12px;outline:none}

.df{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-toolbar);border-top:1px solid var(--border-color);font-size:11px;color:var(--text-muted);flex-shrink:0;gap:10px;height:34px}
.df .pg{display:flex;align-items:center;gap:4px}.df .pg button{background:var(--bg-input);border:1px solid var(--border-color);color:var(--text-secondary);padding:4px 8px;border-radius:3px;cursor:pointer;font-size:11px;height:24px}.df .pg button:hover{background:var(--bg-hover)}.df .pg button:disabled{opacity:.4;cursor:default}

/* Query */
#qec{display:flex;flex-direction:column;flex:0 0 220px;min-height:80px}
#qt{display:flex;align-items:center;gap:4px;padding:6px 8px;background:var(--bg-toolbar);border-bottom:1px solid var(--border-color)}
#qe{flex:1;width:100%;resize:none;background:var(--bg-tertiary);color:var(--text-primary);border:none;padding:14px;font-family:'Consolas',monospace;font-size:13px;line-height:1.5;outline:none;tab-size:4}
#qe::placeholder{color:var(--text-muted)}
#qrh{height:4px;cursor:row-resize;background:var(--border-color);flex-shrink:0}#qrh:hover{background:var(--accent)}
#qr{flex:1;overflow:auto;min-height:0}
.qri{padding:10px 12px;font-size:12px;color:var(--text-secondary);background:var(--bg-toolbar);border-bottom:1px solid var(--border-color)}.qri .dur{color:var(--accent)}.qri .rc{color:var(--success)}.qri.err{color:var(--danger);background:rgba(210,15,57,.08)}

.sg{width:100%;border-collapse:collapse;font-size:12px}.sg th{position:sticky;top:0;background:var(--bg-toolbar);color:var(--text-secondary);font-weight:600;font-size:11px;padding:8px 12px;border-bottom:2px solid var(--border-color);text-align:left;z-index:10}.sg td{padding:6px 12px;border-bottom:1px solid var(--border-color);font-family:'Consolas',monospace;font-size:12px}.sg tr:hover td{background:var(--bg-hover)}
.ip{padding:20px;overflow-y:auto}.is{margin-bottom:20px}.is h3{font-size:13px;color:var(--accent);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border-color)}.ig{display:grid;grid-template-columns:200px 1fr;gap:1px;font-size:12px}.ig .l{color:var(--text-muted);padding:6px 10px;background:var(--bg-toolbar)}.ig .v{color:var(--text-primary);padding:6px 10px;background:var(--bg-secondary);font-family:monospace;word-break:break-all}
#sb{background:var(--bg-toolbar);border-top:1px solid var(--border-color);padding:6px 12px;font-size:11px;color:var(--text-muted);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;height:28px}#sb .sl{display:flex;align-items:center;gap:12px}#sb .si{width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;margin-right:4px}#sb .sr{display:flex;align-items:center;gap:12px}
.mo{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:center;justify-content:center}
.md{background:var(--bg-panel);border:1px solid var(--border-color);border-radius:8px;width:92%;max-width:900px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.mh{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border-color);font-size:14px;font-weight:600}
.mh .cb{background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;padding:4px 8px;border-radius:4px}.mh .cb:hover{background:var(--bg-hover);color:var(--danger)}
.mb{padding:16px;overflow-y:auto;flex:1}.mf{padding:12px 16px;border-top:1px solid var(--border-color);display:flex;justify-content:flex-end;gap:8px}
.fg{margin-bottom:12px}.fg label{display:block;font-size:12px;color:var(--text-secondary);margin-bottom:4px}
.fg input,.fg select{width:100%;padding:7px 10px;background:var(--bg-input);border:1px solid var(--border-color);border-radius:4px;color:var(--text-primary);font-size:12px;outline:none;height:32px}
.fg input:focus,.fg select:focus{border-color:var(--accent)}
.btn{padding:7px 16px;border:1px solid var(--border-color);border-radius:4px;cursor:pointer;font-size:12px;transition:all .15s;height:30px;line-height:1}
.bp{background:var(--accent);color:#fff;border-color:var(--accent);font-weight:600}[data-theme="dark"] .bp{color:var(--bg-primary)}.bp:hover{opacity:.85}
.bs{background:var(--bg-input);color:var(--text-secondary)}.bs:hover{background:var(--bg-hover)}
.bd{background:rgba(210,15,57,.12);color:var(--danger);border-color:var(--danger)}.bd:hover{background:rgba(210,15,57,.25)}
.cm{position:fixed;background:var(--bg-panel);border:1px solid var(--border-color);border-radius:6px;padding:4px 0;z-index:2000;min-width:280px;box-shadow:0 8px 30px rgba(0,0,0,.35);max-height:80vh;overflow-y:auto}
.ci{padding:8px 16px;cursor:pointer;font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:8px;transition:background .1s}
.ci:hover{background:var(--bg-hover);color:var(--text-primary)}.ci.dng{color:var(--danger)}.ci.dng:hover{background:rgba(210,15,57,.08)}
.ci.hdr{font-size:11px;color:var(--text-muted);cursor:default;pointer-events:none;padding:6px 16px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.csp{height:1px;background:var(--border-color);margin:4px 0}
.ws{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;color:var(--text-muted);gap:12px;padding:40px}.ws .logo{font-size:56px}.ws h2{color:var(--text-secondary);font-size:22px}.ws p{font-size:13px;text-align:center;max-width:420px;line-height:1.6}
.ld{display:flex;align-items:center;justify-content:center;padding:40px;color:var(--text-muted);gap:8px}.sp{width:16px;height:16px;border:2px solid var(--border-color);border-top:2px solid var(--accent);border-radius:50%;animation:spin .6s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
.tc2{position:fixed;bottom:40px;right:20px;z-index:3000;display:flex;flex-direction:column-reverse;gap:8px}.to{padding:10px 16px;border-radius:6px;font-size:12px;color:var(--text-primary);box-shadow:0 4px 20px rgba(0,0,0,.25);animation:sIn .3s ease;max-width:420px}.to.ok{background:rgba(64,160,43,.12);border:1px solid var(--success)}.to.er{background:rgba(210,15,57,.12);border:1px solid var(--danger)}.to.inf{background:rgba(30,102,245,.12);border:1px solid var(--accent)}@keyframes sIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
.dc{background:var(--bg-tertiary);padding:16px;font-family:'Consolas',monospace;font-size:13px;line-height:1.6;overflow:auto;white-space:pre-wrap;border-radius:4px;color:var(--text-primary);flex:1}
.etl{max-height:250px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px;padding:8px;background:var(--bg-tertiary)}.eti{display:flex;align-items:center;gap:8px;padding:5px 6px;font-size:12px;border-radius:3px;cursor:pointer}.eti:hover{background:var(--bg-hover)}
.erg{display:flex;flex-direction:column;gap:8px}.eri{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border-color);border-radius:6px;cursor:pointer}.eri:hover{border-color:var(--accent)}
.priv-badge{display:inline-block;font-size:10px;padding:2px 6px;border-radius:3px;margin-left:4px;font-weight:600}.priv-yes{background:rgba(64,160,43,.15);color:var(--success)}.priv-no{background:rgba(210,15,57,.12);color:var(--danger)}

/* Connection selector toolbar */
.conn-btn{padding:3px 8px;font-size:11px;border-radius:3px;cursor:pointer;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-secondary);transition:all .15s}
.conn-btn:hover{background:var(--bg-hover)}.conn-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
[data-theme="dark"] .conn-btn.active{color:var(--bg-primary)}

.code-editor{
    width:100%;
    min-height:350px;
    resize:none;
    background:var(--bg-tertiary);
    color:var(--text-primary);
    border:1px solid var(--border-color);
    padding:12px;
    font-family:'Consolas',monospace;
    font-size:13px;
    line-height:1.5;
    outline:none;
    tab-size:4;
    border-radius:4px;
}
.code-editor:focus{
    border-color:var(--accent);
}
</style>
</head>
<body>
<div id="app">
    <div id="toolbar">
        <div id="conn-bar" style="display:flex;gap:4px;align-items:center;margin-right:4px"></div>
        <div class="sep"></div>
        <button class="tb" onclick="showCreateDbModal()" id="tbNewDb"><span class="ic">🗄️</span> Nuovo DB</button>
        <button class="tb" onclick="showCreateTableModal()" id="tbNewTbl"><span class="ic">📋</span> Nuova Tabella</button>
        <div class="sep"></div>
        <button class="tb" onclick="refreshTree()"><span class="ic">🔄</span> Aggiorna</button>
        <button class="tb" onclick="showProcessList()" id="tbProc"><span class="ic">⚡</span> Processi</button>
        <button class="tb" onclick="showServerInfo()"><span class="ic">ℹ️</span> Info</button>
        <div class="sep"></div>
        <button class="tb" id="tbExpDb" onclick="showExportDbModal()" disabled><span class="ic">📦</span> Export</button>
        <button class="tb" id="tbExpSql" onclick="confirmExportTable('sql')" disabled><span class="ic">💾</span> SQL</button>
        <button class="tb" id="tbExpCsv" onclick="confirmExportTable('csv')" disabled><span class="ic">📊</span> CSV</button>
        <div class="sep"></div>
        <button class="tb" onclick="toggleTheme()" id="tbTheme"><span class="ic">🌓</span> Tema</button>
        <div style="flex:1"></div>
        <span class="tl" id="privInfo"></span>
    </div>
    <div id="main">
        <div id="sidebar">
            <div id="sidebar-header">
                <div class="logo">
                    <span class="logo-icon">🐬</span>
                    SabrySQL
                </div>

                <div class="conn-selector">
                    <select id="connSelect" onchange="switchConnection(this.value)"></select>
                </div>

                <div class="conn-status">
                    <span class="dot unk" id="connDot"></span>
                    <span id="connStatusText">Connettendo...</span>
                    <span class="cid" id="connId"></span>
                    <button class="conn-kill-btn" id="connKillBtn" style="display:none" onclick="killCurrentConnection()" title="Termina questa connessione MySQL">⚡ Kill</button>
                </div>
            </div>

            <input type="text" id="tree-filter" placeholder="🔍 Filtra...">
            <div id="tree-container"></div>

            <div id="sidebar-footer">
                📡 Server: <?=$serverHost?>:<?=$serverPort?>
            </div>

        </div>
        <div id="resize-handle"></div>
        <div id="content">
            <div id="ws" class="ws"><div class="logo">🐬</div><h2>SabrySQL</h2><p>Seleziona un database o tabella.</p></div>
            <div id="dp" style="display:none;flex:1;flex-direction:column;">
                <div id="content-tabs">
                    <div class="ct active" data-tab="data" onclick="switchTab('data')">📊 Dati</div>
                    <div class="ct" data-tab="structure" onclick="switchTab('structure')">🏗️ Struttura</div>
                    <div class="ct" data-tab="indexes" onclick="switchTab('indexes')">🔑 Indici</div>
                    <div class="ct" data-tab="foreignkeys" onclick="switchTab('foreignkeys')">🔗 FK</div>
                    <div class="ct" data-tab="ddl" onclick="switchTab('ddl')">📝 DDL</div>
                    <div class="ct" data-tab="query" onclick="switchTab('query')">▶️ Query</div>
                    <div class="ct" data-tab="info" onclick="switchTab('info')">ℹ️ Info</div>
                </div>
                <div class="tp active" id="p-data">
                    <div class="dt">
                        <button class="tb" onclick="insertRow()" id="tbIns"><span class="ic">➕</span> Nuovo</button>
                        <button class="tb" onclick="deleteSelectedRows()" id="tbDel"><span class="ic">🗑️</span> Elimina</button>
                        <button class="tb" onclick="refreshData()"><span class="ic">🔄</span> Aggiorna</button>
                        <div class="sep"></div>
                        <input type="text" id="data-filter" placeholder="🔍 Filtra..." style="width:180px" onkeydown="if(event.key==='Enter')refreshData()">
                        <div class="sep"></div>
                        <select id="page-size" onchange="refreshData()"><option value="50">50</option><option value="100" selected>100</option><option value="250">250</option><option value="500">500</option><option value="1000">1000</option></select>
                    </div>
                    <div class="gc" id="dgc"><div class="ld"><div class="sp"></div></div></div>
                    <div class="df"><span id="di">-</span><span id="selInfo" style="color:var(--accent)"></span><div class="pg"><button onclick="dataPage('first')" id="pf" disabled>⏮</button><button onclick="dataPage('prev')" id="pp" disabled>◀</button><span id="pi">-</span><button onclick="dataPage('next')" id="pn" disabled>▶</button><button onclick="dataPage('last')" id="pl" disabled>⏭</button></div></div>
                </div>
                <div class="tp" id="p-structure"><div class="dt"><button class="tb" onclick="showAddColumnModal()" id="tbAddCol"><span class="ic">➕</span> Colonna</button><button class="tb" onclick="loadStructure()"><span class="ic">🔄</span> Aggiorna</button></div><div class="gc" id="sc"></div></div>
                <div class="tp" id="p-indexes"><div class="gc" id="ic2"></div></div>
                <div class="tp" id="p-foreignkeys"><div class="gc" id="fkc"></div></div>
                <div class="tp" id="p-ddl"><div id="ddl" class="dc"></div></div>
                <div class="tp" id="p-query"><div id="qec"><div id="qt"><button class="tb" onclick="executeQuery()"><span class="ic">▶️</span> Esegui</button><button class="tb" onclick="executeSelectedQuery()"><span class="ic">⏩</span> Esegui Selezione</button><button class="tb" onclick="clearQE()"><span class="ic">🧹</span> Pulisci</button><div class="sep"></div><button class="tb" onclick="formatQuery()"><span class="ic">✨</span> Formatta</button></div><textarea id="qe" placeholder="-- SQL (F9 esegui)"></textarea></div><div id="qrh"></div><div id="qr"></div></div>
                <div class="tp" id="p-info"><div class="ip" id="ifc"></div></div>
            </div>
        </div>
    </div>
    <div id="sb"><div class="sl"><span><span class="si"></span> Connesso</span> <span id="sdb">-</span></div><div class="sr"><span id="st"></span><span id="sv"></span></div></div>
</div>
<div class="tc2" id="tc"></div>

<script>
const A={db:null,tbl:null,off:0,total:0,sort:'',sortDir:'ASC',selRows:new Set(),cols:[],rows:[],pks:[],expanded:new Set(),dbTables:{},privs:{},qrs:[],connId:<?=$connId?>,connCid:null,colWidths:{},isConnected:false};
const $=id=>document.getElementById(id);

// Utils
function api(a,p={},m='GET'){let u=`?action=${a}&conn=${A.connId}`;if(m==='GET'){Object.entries(p).forEach(([k,v])=>u+=`&${k}=${encodeURIComponent(v)}`);return fetch(u).then(r=>r.json());}const fd=new FormData();fd.append('conn',A.connId);Object.entries(p).forEach(([k,v])=>fd.append(k,v));return fetch(u,{method:'POST',body:fd}).then(r=>r.json());}
function toast(m,t='inf'){const c=$('tc'),e=document.createElement('div');e.className=`to ${t==='success'?'ok':t==='error'?'er':'inf'}`;e.textContent=m;c.appendChild(e);setTimeout(()=>{e.style.opacity='0';setTimeout(()=>e.remove(),300)},4000);}
function esc(s){if(s===null||s===undefined)return'<span class="nv">NULL</span>';const d=document.createElement('div');d.textContent=String(s);return d.innerHTML;}
function fmtB(b){if(!b)return'0 B';const k=1024,s=['B','KB','MB','GB'];const i=Math.floor(Math.log(b)/Math.log(k));return(b/Math.pow(k,i)).toFixed(1)+' '+s[i];}
function fmtN(n){return Number(n).toLocaleString('it-IT');}
function conf(m){return confirm(m);}
function can(p){return A.privs[p]===true;}
function closeModal(el){el.closest('.mo')?.remove();}
function sqlVal(v){return v===null?'NULL':"'"+String(v).replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";}
function genInsert(t,r,c){return`INSERT INTO \`${t}\` (\`${c.join('`, `')}\`) VALUES (${c.map(x=>sqlVal(r[x])).join(', ')});`;}
function genUpdate(t,r,c){return`UPDATE \`${t}\` SET ${c.map(x=>`\`${x}\` = ${sqlVal(r[x])}`).join(', ')} WHERE ${c.map(x=>r[x]===null?`\`${x}\` IS NULL`:`\`${x}\` = ${sqlVal(r[x])}`).join(' AND ')} LIMIT 1;`;}
function genDelete(t,r,c){return`DELETE FROM \`${t}\` WHERE ${c.map(x=>r[x]===null?`\`${x}\` IS NULL`:`\`${x}\` = ${sqlVal(r[x])}`).join(' AND ')} LIMIT 1;`;}
function rowsToCsv(rows,cols){let csv='\uFEFF'+cols.join(';')+'\n';rows.forEach(r=>{csv+=cols.map(c=>{let v=r[c];if(v===null)return'NULL';v=String(v).replace(/"/g,'""');return(v.includes(';')||v.includes('"')||v.includes('\n'))?`"${v}"`:v;}).join(';')+'\n';});return csv;}
function downloadText(c,f,m='text/plain'){const b=new Blob([c],{type:m+';charset=utf-8'}),u=URL.createObjectURL(b),a=document.createElement('a');a.href=u;a.download=f;document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(u);}
function copyClip(t,m){navigator.clipboard.writeText(t).then(()=>toast(m||'Copiato!','success')).catch(()=>{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);toast(m||'Copiato!','success');});}
function extractTable(sql){if(!sql)return A.tbl||'table';const m=sql.match(/\bFROM\s+`?(\w+)`?/i);return m?m[1]:(A.tbl||'table');}

// ============================================================
// CONNECTIONS
// ============================================================
async function loadConnections(){
    const d=await api('connections');if(!d.connections)return;
    const bar=$('conn-bar');bar.innerHTML='';
    const sel=$('connSelect');sel.innerHTML='';
    d.connections.forEach(c=>{
        const b=document.createElement('button');
        b.className=`conn-btn ${c.active?'active':''}`;
        b.textContent=`🔗 ${c.label}`;
        b.title=`${c.user}@${c.host}:${c.port}`;
        b.onclick=()=>switchConnection(c.id);
        bar.appendChild(b);

        const o=document.createElement('option');
        o.value=c.id;
        o.textContent=`${c.label} (${c.user}@${c.host})`;
        if(c.active)o.selected=true;
        sel.appendChild(o);
    });

    await checkConnStatus();
}

async function checkConnStatus(){
    const d=await api('conn_status');
    const dot=$('connDot');
    const txt=$('connStatusText');
    const cid=$('connId');
    const killBtn=$('connKillBtn');

    if(d.connected){
        A.isConnected=true;
        A.connCid=d.connection_id;
        dot.className='dot ok';
        txt.textContent='Connesso';
        cid.textContent=`#${d.connection_id}`;
        killBtn.style.display='inline-block';
    }else{
        A.isConnected=false;
        A.connCid=null;
        dot.className='dot err';
        txt.textContent='Errore';
        txt.title=d.error||'';
        cid.textContent='';
        killBtn.style.display='none';
    }
}

async function killCurrentConnection(){
    if(!A.connCid)return;
    if(!conf(`Terminare la connessione #${A.connCid}?`))return;
    await api('kill_connection', {connection_id:A.connCid}, 'POST');
    toast('Connessione terminata', 'success');
    setTimeout(checkConnStatus, 500);
}

async function switchConnection(id){
    id=parseInt(id);
    if(id===A.connId)return;
    if(!conf('Cambiare connessione? La sessione corrente verrà resettata.')){
        $('connSelect').value=A.connId;
        return;
    }
    const d=await api('switch_connection',{id});
    if(d.error){toast(d.error,'error');$('connSelect').value=A.connId;return;}
    A.connId=id;
    window.location.href=window.location.pathname;
}

// ============================================================
// PRIVILEGES / THEME
// ============================================================
async function loadPrivileges(){const d=await api('privileges');A.privs=d.privileges||{};if(!can('CREATE')){$('tbNewDb').disabled=true;$('tbNewTbl').disabled=true;}if(!can('PROCESS'))$('tbProc').disabled=true;if(!can('INSERT'))$('tbIns').disabled=true;if(!can('DELETE'))$('tbDel').disabled=true;if(!can('ALTER'))$('tbAddCol').disabled=true;$('privInfo').innerHTML=['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER'].map(k=>`<span class="priv-badge ${A.privs[k]?'priv-yes':'priv-no'}">${k}</span>`).join('');}
function toggleTheme(){const h=document.documentElement,n=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',n);localStorage.setItem('ss-theme',n);$('tbTheme').querySelector('.ic').textContent=n==='dark'?'🌓':'☀️';}
function initTheme(){const s=localStorage.getItem('ss-theme');if(s){document.documentElement.setAttribute('data-theme',s);$('tbTheme').querySelector('.ic').textContent=s==='dark'?'🌓':'☀️';}}

// ============================================================
// TREE
// ============================================================
async function loadDatabases(){if(!A.isConnected){$('tree-container').innerHTML='<div class="ld" style="color:var(--warning);font-size:12px">⚠️ Non connesso</div>';return;}const d=await api('databases');if(d.error)return toast(d.error,'error');renderTree(d.databases);}
async function renderTree(dbs){const c=$('tree-container');c.innerHTML='';for(const db of dbs){const di=document.createElement('div');di.className='ti db';di.dataset.db=db;di.innerHTML=`<span class="tit ${A.expanded.has(db)?'open':''}">▶</span><span class="tic">🗄️</span><span class="til">${esc(db)}</span>`;const ch=document.createElement('div');ch.className=`tc ${A.expanded.has(db)?'open':''}`;ch.id=`tc-${db}`;di.onclick=()=>toggleDb(db,di,ch);di.oncontextmenu=e=>showDbCtx(e,db);c.appendChild(di);c.appendChild(ch);if(A.expanded.has(db))await loadTbls(db,ch);}}
async function toggleDb(db,di,ch){const t=di.querySelector('.tit');if(ch.classList.contains('open')){ch.classList.remove('open');t.classList.remove('open');A.expanded.delete(db);}else{ch.classList.add('open');t.classList.add('open');A.expanded.add(db);await loadTbls(db,ch);}selDb(db);}
async function loadTbls(db,c){c.innerHTML='<div class="ld" style="padding:8px 32px;font-size:11px"><div class="sp"></div></div>';const d=await api('tables',{db});if(d.error){c.innerHTML=`<div style="padding:8px 32px;color:var(--danger);font-size:11px">${d.error}</div>`;return;}A.dbTables[db]=d.tables;c.innerHTML='';if(!d.tables.length){c.innerHTML='<div style="padding:8px 32px;color:var(--text-muted);font-size:11px;font-style:italic">Vuoto</div>';return;}d.tables.forEach(t=>{const iv=t.type==='VIEW',el=document.createElement('div');el.className=`ti tbi ${iv?'vw':''}`;el.dataset.db=db;el.dataset.table=t.name;el.dataset.type=t.type;el.innerHTML=`<span class="tic">${iv?'👁️':'📋'}</span><span class="til">${esc(t.name)}</span>${iv?'<span class="tib">VIEW</span>':''}`;el.onclick=()=>selTbl(db,t.name,t.type);el.oncontextmenu=e=>showTblCtx(e,db,t.name,t.type);c.appendChild(el);});}
function selDb(db){A.db=db;$('sdb').textContent=`DB: ${db}`;$('tbExpDb').disabled=false;document.querySelectorAll('.ti.db').forEach(e=>e.classList.remove('sel'));document.querySelector(`.ti.db[data-db="${CSS.escape(db)}"]`)?.classList.add('sel');}
async function selTbl(db,tbl,type){A.db=db;A.tbl=tbl;A.off=0;A.sort='';A.sortDir='ASC';A.selRows.clear();A.pks=[];selDb(db);document.querySelectorAll('.ti.tbi').forEach(e=>e.classList.remove('sel'));document.querySelector(`.ti.tbi[data-db="${CSS.escape(db)}"][data-table="${CSS.escape(tbl)}"]`)?.classList.add('sel');$('ws').style.display='none';$('dp').style.display='flex';$('tbExpSql').disabled=false;$('tbExpCsv').disabled=false;$('sdb').textContent=`${db} → ${tbl}`;
    // Load primary keys
    const pkd=await api('primary_keys',{db,table:tbl});A.pks=pkd.primary_keys||[];
    loadTabContent(document.querySelector('.ct.active')?.dataset.tab||'data');}
function switchTab(t){document.querySelectorAll('.ct').forEach(e=>e.classList.remove('active'));document.querySelector(`.ct[data-tab="${t}"]`).classList.add('active');document.querySelectorAll('.tp').forEach(e=>e.classList.remove('active'));$(`p-${t}`).classList.add('active');loadTabContent(t);}
function loadTabContent(t){switch(t){case'data':refreshData();break;case'structure':loadStructure();break;case'indexes':loadIndexes();break;case'foreignkeys':loadFK();break;case'ddl':loadDDL();break;case'query':break;case'info':loadInfo();break;}}

// ============================================================
// VISTE E PROCEDURE
// ============================================================
function showCreateViewModal(db) {
    let h = `<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:900px">
    <div class="mh">👁️ Nuova Vista su ${esc(db)}<button class="cb" onclick="closeModal(this)">✕</button></div>
    <div class="mb">
        <div class="fg">
            <label>Nome Vista</label>
            <input type="text" id="viewName" placeholder="nome_vista">
        </div>
        <div class="fg">
            <label>Definizione</label>
            <textarea id="viewDef" class="code-editor" placeholder="SELECT * FROM mia_tabella WHERE ..."></textarea>
        </div>
    </div>
    <div class="mf">
        <button class="btn bs" onclick="closeModal(this)">Annulla</button>
        <button class="btn bp" onclick="doCreateView('${db}')">Crea Vista</button>
    </div>
    </div></div>`;
    document.body.insertAdjacentHTML('beforeend', h);
}

async function doCreateView(db) {
    const name = $('viewName').value.trim();
    const def = $('viewDef').value.trim();
    if(!name || !def) return toast('Compila tutti i campi', 'error');
    if(!conf(`Creare la vista ${name}?`)) return;

    const sql = `CREATE VIEW \`${name}\` AS\n${def}`;
    const r = await api('query', {db, sql}, 'POST');
    if(r.error) return toast(r.error, 'error');
    if(r.results[0].error) return toast(r.results[0].error, 'error');

    toast('Vista creata!', 'success');
    document.querySelector('.mo')?.remove();
    refreshTree();
}

function showEditViewModal(db, name) {
    api('show_create', {db, table:name}).then(d=>{
        const def = d.sql.replace(/^CREATE.*?VIEW.*?AS\s*/i, '').trim();

        let h = `<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:900px">
        <div class="mh">✏️ Modifica Vista ${esc(name)}<button class="cb" onclick="closeModal(this)">✕</button></div>
        <div class="mb">
            <div class="fg">
                <label>Definizione</label>
                <textarea id="viewDef" class="code-editor">${esc(def)}</textarea>
            </div>
        </div>
        <div class="mf">
            <button class="btn bs" onclick="closeModal(this)">Annulla</button>
            <button class="btn bp" onclick="doEditView('${db}','${name}')">Salva</button>
        </div>
        </div></div>`;
        document.body.insertAdjacentHTML('beforeend', h);
    });
}

async function doEditView(db, name) {
    const def = $('viewDef').value.trim();
    if(!def) return toast('Definizione vuota', 'error');
    if(!conf(`Modificare la vista ${name}?`)) return;

    const sql = `DROP VIEW IF EXISTS \`${name}\`;\nCREATE VIEW \`${name}\` AS\n${def}`;
    const r = await api('query', {db, sql}, 'POST');
    if(r.error) return toast(r.error, 'error');

    toast('Vista modificata!', 'success');
    document.querySelector('.mo')?.remove();
    refreshTree();
}

function showCreateRoutineModal(db) {
    let h = `<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:900px">
    <div class="mh">⚙️ Nuova Procedura su ${esc(db)}<button class="cb" onclick="closeModal(this)">✕</button></div>
    <div class="mb">
        <div style="display:flex;gap:12px">
            <div class="fg" style="flex:1">
                <label>Tipo</label>
                <select id="routineType">
                    <option value="PROCEDURE">PROCEDURE</option>
                    <option value="FUNCTION">FUNCTION</option>
                </select>
            </div>
            <div class="fg" style="flex:2">
                <label>Nome</label>
                <input type="text" id="routineName" placeholder="mia_procedura">
            </div>
        </div>
        <div class="fg">
            <label>Definizione</label>
            <textarea id="routineDef" class="code-editor">BEGIN

END</textarea>
        </div>
    </div>
    <div class="mf">
        <button class="btn bs" onclick="closeModal(this)">Annulla</button>
        <button class="btn bp" onclick="doCreateRoutine('${db}')">Crea</button>
    </div>
    </div></div>`;
    document.body.insertAdjacentHTML('beforeend', h);
}

async function doCreateRoutine(db) {
    const type = $('routineType').value;
    const name = $('routineName').value.trim();
    const def = $('routineDef').value.trim();
    if(!name || !def) return toast('Compila tutti i campi', 'error');
    if(!conf(`Creare ${type} ${name}?`)) return;

    const sql = `CREATE ${type} \`${name}\`()\n${def}`;
    const r = await api('query', {db, sql}, 'POST');
    if(r.error) return toast(r.error, 'error');

    toast(`${type} creata!`, 'success');
    document.querySelector('.mo')?.remove();
    refreshTree();
}

// ============================================================
// DATA GRID — resizable columns, select all, PK protection
// ============================================================
async function refreshData(){if(!A.db||!A.tbl)return;const c=$('dgc');c.innerHTML='<div class="ld"><div class="sp"></div></div>';const lim=parseInt($('page-size').value),fil=$('data-filter').value;const d=await api('data',{db:A.db,table:A.tbl,offset:A.off,limit:lim,sort:A.sort,sortdir:A.sortDir,filter:fil});if(d.error){c.innerHTML=`<div class="ld" style="color:var(--danger)">${d.error}</div>`;return;}A.total=d.total;A.selRows.clear();updSelInfo();renderGrid(d.rows,c);updPag();}

function renderGrid(rows,container){
    if(!rows||!rows.length){container.innerHTML='<div class="ld" style="color:var(--text-muted)">Nessun dato</div>';$('di').textContent='0 righe';return;}
    const cols=Object.keys(rows[0]);A.cols=cols;A.rows=rows;
    const tblKey=`${A.db}.${A.tbl}`;

    let h=`<table class="dg" id="dataTable"><thead><tr>`;
    // Checkbox column — fixed, not resizable
    h+=`<th class="col-cb"><input type="checkbox" id="selAllCb" onclick="toggleSelectAll(this.checked)" title="Seleziona tutto"></th>`;
    // Data columns with resize handles
    cols.forEach((col,ci)=>{
        const s=A.sort===col,ar=s?(A.sortDir==='ASC'?'▲':'▼'):'';
        const isPk=A.pks.includes(col);
        const w=A.colWidths[tblKey+'_'+ci];
        const style=w?`style="width:${w}px"`:'';
        h+=`<th class="${s?'sorted':''}" ${style} data-ci="${ci}" onclick="sortCol('${col.replace(/'/g,"\\'")}')">${isPk?'🔑 ':''}${esc(col)}<span class="sa">${ar}</span><div class="col-resize-handle" onmousedown="startColResize(event,${ci})"></div></th>`;
    });
    h+='</tr></thead><tbody>';

    rows.forEach((row,i)=>{
        h+=`<tr data-idx="${i}" onclick="clickRow(event,${i})" ondblclick="editRow(${i})" oncontextmenu="showDataCtx(event,${i})">`;
        h+=`<td class="col-cb"><input type="checkbox" onclick="event.stopPropagation();toggleRowSel(${i},this.checked)"></td>`;
        cols.forEach(col=>{
            const v=row[col];
            const isPk=A.pks.includes(col);
            h+=v===null?`<td class="nv${isPk?' pk-cell':''}">NULL</td>`:`<td class="${isPk?'pk-cell':''}">${esc(v)}</td>`;
        });
        h+='</tr>';
    });
    h+='</tbody></table>';
    container.innerHTML=h;

    const lim=parseInt($('page-size').value);
    $('di').textContent=`${fmtN(A.total)} righe | ${A.off+1}-${A.off+Math.min(lim,rows.length)}`;
}

// ---- Column Resize ----
function startColResize(ev,ci){
    ev.stopPropagation();ev.preventDefault();
    const th=ev.target.parentElement;
    const startX=ev.clientX;
    const startW=th.offsetWidth;
    const handle=ev.target;
    handle.classList.add('active');
    const tblKey=`${A.db}.${A.tbl}`;

    function onMove(e){
        const newW=Math.max(40,startW+(e.clientX-startX));
        th.style.width=newW+'px';
        A.colWidths[tblKey+'_'+ci]=newW;
    }
    function onUp(){
        handle.classList.remove('active');
        document.removeEventListener('mousemove',onMove);
        document.removeEventListener('mouseup',onUp);
    }
    document.addEventListener('mousemove',onMove);
    document.addEventListener('mouseup',onUp);
}

// ---- Select All ----
function toggleSelectAll(checked){
    if(checked){for(let i=0;i<A.rows.length;i++)A.selRows.add(i);}
    else{A.selRows.clear();}
    hlSel();
}

function clickRow(ev,i){if(ev.target.type==='checkbox')return;if(ev.shiftKey&&A._lr!==undefined){const s=Math.min(A._lr,i),e=Math.max(A._lr,i);for(let r=s;r<=e;r++)A.selRows.add(r);}else if(ev.ctrlKey||ev.metaKey){A.selRows.has(i)?A.selRows.delete(i):A.selRows.add(i);}else{A.selRows.clear();A.selRows.add(i);}A._lr=i;hlSel();}
function toggleRowSel(i,ch){ch?A.selRows.add(i):A.selRows.delete(i);A._lr=i;hlSel();}
function hlSel(){const all=A.rows.length;let selCount=0;document.querySelectorAll('#dataTable tbody tr').forEach(tr=>{const i=parseInt(tr.dataset.idx);tr.classList.remove('sel','msel');if(A.selRows.has(i)){tr.classList.add(A.selRows.size>1?'msel':'sel');selCount++;}tr.querySelector('input[type=checkbox]').checked=A.selRows.has(i);});$('selAllCb').checked=selCount===all&&all>0;$('selAllCb').indeterminate=selCount>0&&selCount<all;updSelInfo();}
function updSelInfo(){$('selInfo').textContent=A.selRows.size?`${A.selRows.size} sel.`:'';}
function getSelRows(){return[...A.selRows].map(i=>A.rows[i]).filter(Boolean);}
function sortCol(c){if(A.sort===c)A.sortDir=A.sortDir==='ASC'?'DESC':'ASC';else{A.sort=c;A.sortDir='ASC';}A.off=0;refreshData();}
function dataPage(d){const l=parseInt($('page-size').value);switch(d){case'first':A.off=0;break;case'prev':A.off=Math.max(0,A.off-l);break;case'next':A.off=Math.min(A.total-l,A.off+l);break;case'last':A.off=Math.max(0,Math.floor((A.total-1)/l)*l);break;}refreshData();}
function updPag(){const l=parseInt($('page-size').value),tp=Math.ceil(A.total/l)||1,cp=Math.floor(A.off/l)+1;$('pi').textContent=`${cp}/${tp}`;$('pf').disabled=A.off===0;$('pp').disabled=A.off===0;$('pn').disabled=A.off+l>=A.total;$('pl').disabled=A.off+l>=A.total;}

// ---- Inline Editing — skip PK columns ----
function editRow(i){if(!can('UPDATE'))return toast('UPDATE non permesso','error');const row=A.rows[i];if(!row)return;const tr=document.querySelector(`#dataTable tbody tr[data-idx="${i}"]`);if(!tr)return;
    const cells=tr.querySelectorAll('td:not(.col-cb)');
    cells.forEach((cell,ci)=>{const col=A.cols[ci],val=row[col];
        if(A.pks.includes(col)){/* skip PK — not editable */return;}
        cell.classList.add('editing');const inp=document.createElement('input');inp.type='text';inp.value=val===null?'':val;inp.placeholder=val===null?'NULL':'';inp.dataset.col=col;
        inp.onkeydown=e=>{if(e.key==='Enter')saveEdit(i,tr);if(e.key==='Escape')refreshData();};
        cell.innerHTML='';cell.appendChild(inp);
    });
    tr.querySelector('td.editing input')?.focus();
}
async function saveEdit(i,tr){if(!conf('Salvare?'))return refreshData();const inputs=tr.querySelectorAll('td.editing input'),where={},data={},orig=A.rows[i];A.cols.forEach(c=>{where[c]=orig[c];});inputs.forEach(inp=>{data[inp.dataset.col]=inp.value;});const r=await api('update_row',{db:A.db,table:A.tbl,data:JSON.stringify(data),where:JSON.stringify(where)},'POST');if(r.error)return toast(r.error,'error');toast(`OK (${r.affected})`,'success');refreshData();}

// ---- Data Context Menu ----
function showDataCtx(ev,idx){ev.preventDefault();ev.stopPropagation();if(!A.selRows.has(idx)){if(!ev.ctrlKey&&!ev.metaKey&&!ev.shiftKey)A.selRows.clear();A.selRows.add(idx);A._lr=idx;hlSel();}hideCtx();const n=A.selRows.size,m=mkCtx(ev);m.innerHTML=`<div class="ci hdr">📋 ${n} selezionata/e</div><div class="ci" onclick="dSI();hideCtx()">📝 Sel. → INSERT (copia)</div><div class="ci" onclick="dSU();hideCtx()">📝 Sel. → UPDATE (copia)</div><div class="ci" onclick="dSD();hideCtx()">📝 Sel. → DELETE (copia)</div><div class="ci" onclick="dSC();hideCtx()">📊 Sel. → CSV</div><div class="csp"></div><div class="ci hdr">📦 Tutte (${fmtN(A.total)})</div><div class="ci" onclick="dAC();hideCtx()">📊 Tutte → CSV</div><div class="ci" onclick="dAI();hideCtx()">📝 Tutte → INSERT</div><div class="ci" onclick="dAU();hideCtx()">📝 Tutte → UPDATE</div><div class="ci" onclick="dAD();hideCtx()">📝 Tutte → DELETE</div><div class="csp"></div><div class="ci" onclick="editRow(${idx});hideCtx()">✏️ Modifica</div>${can('DELETE')?`<div class="ci dng" onclick="deleteSelectedRows();hideCtx()">🗑️ Elimina</div>`:''}`;}
function dSI(){const r=getSelRows();if(!r.length)return;copyClip(r.map(x=>genInsert(A.tbl,x,A.cols)).join('\n'),'INSERT copiati');}
function dSU(){const r=getSelRows();if(!r.length)return;copyClip(r.map(x=>genUpdate(A.tbl,x,A.cols)).join('\n'),'UPDATE copiati');}
function dSD(){const r=getSelRows();if(!r.length)return;copyClip(r.map(x=>genDelete(A.tbl,x,A.cols)).join('\n'),'DELETE copiati');}
function dSC(){const r=getSelRows();if(!r.length||!conf(`CSV per ${r.length} righe?`))return;downloadText(rowsToCsv(r,A.cols),`${A.tbl}_sel.csv`,'text/csv');toast('OK','success');}
async function fetchAll(){const d=await api('data',{db:A.db,table:A.tbl,offset:0,limit:999999999,sort:A.sort,sortdir:A.sortDir,filter:$('data-filter').value});return d.rows||[];}
async function dAC(){if(!conf('CSV tutte?'))return;const r=await fetchAll();if(!r.length)return;downloadText(rowsToCsv(r,Object.keys(r[0])),`${A.tbl}_all.csv`,'text/csv');toast('OK','success');}
async function dAI(){if(!conf('INSERT tutte?'))return;const r=await fetchAll();if(!r.length)return;const c=Object.keys(r[0]);downloadText(r.map(x=>genInsert(A.tbl,x,c)).join('\n'),`${A.tbl}_insert.sql`,'application/sql');toast('OK','success');}
async function dAU(){if(!conf('UPDATE tutte?'))return;const r=await fetchAll();if(!r.length)return;const c=Object.keys(r[0]);downloadText(r.map(x=>genUpdate(A.tbl,x,c)).join('\n'),`${A.tbl}_update.sql`,'application/sql');toast('OK','success');}
async function dAD(){if(!conf('DELETE tutte?'))return;const r=await fetchAll();if(!r.length)return;const c=Object.keys(r[0]);downloadText(r.map(x=>genDelete(A.tbl,x,c)).join('\n'),`${A.tbl}_delete.sql`,'application/sql');toast('OK','success');}

// ---- Insert / Delete ----
async function insertRow(){if(!can('INSERT'))return toast('No INSERT','error');if(!A.db||!A.tbl)return;const cd=await api('columns',{db:A.db,table:A.tbl});if(cd.error)return toast(cd.error,'error');let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:600px"><div class="mh">➕ ${esc(A.tbl)}<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb" style="max-height:400px;overflow-y:auto">`;cd.columns.forEach(c=>{const n=c.Field,x=c.Extra||'';h+=`<div class="fg"><label>${esc(n)} <span style="color:var(--accent);font-size:10px">${esc(c.Type)}</span>${x?` <span style="color:var(--success);font-size:10px">${esc(x)}</span>`:''}</label><input type="text" data-col="${esc(n)}" ${x.includes('auto_increment')?'disabled placeholder="AUTO"':''}></div>`;});h+=`</div><div class="mf"><button class="btn bs" onclick="closeModal(this)">Annulla</button><button class="btn bp" onclick="doIns(this)">Inserisci</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
async function doIns(btn){if(!conf('Inserire?'))return;const mo=btn.closest('.mo'),data={};mo.querySelectorAll('.mb input:not([disabled])').forEach(i=>{if(i.value!=='')data[i.dataset.col]=i.value;});const r=await api('insert_row',{db:A.db,table:A.tbl,data:JSON.stringify(data)},'POST');if(r.error)return toast(r.error,'error');toast(`OK ID:${r.lastInsertId}`,'success');mo.remove();refreshData();}
async function deleteSelectedRows(){if(!can('DELETE'))return toast('No DELETE','error');const rows=getSelRows();if(!rows.length)return toast('Seleziona','inf');if(!conf(`Eliminare ${rows.length}?`))return;let ok=0;for(const row of rows){const r=await api('delete_row',{db:A.db,table:A.tbl,where:JSON.stringify(row)},'POST');if(!r.error)ok+=r.affected;}toast(`Eliminate: ${ok}`,'success');A.selRows.clear();refreshData();}

// ============================================================
// QUERY EDITOR + RESULTS with context menu
// ============================================================
async function executeQuery(){const sql=$('qe').value.trim();if(!sql)return;if(!conf('Eseguire?'))return;await runQ(sql);}
async function executeSelectedQuery(){const ed=$('qe'),sql=ed.value.substring(ed.selectionStart,ed.selectionEnd).trim();if(!sql)return;if(!conf('Eseguire selezione?'))return;await runQ(sql);}
async function runQ(sql){const c=$('qr');c.innerHTML='<div class="ld"><div class="sp"></div></div>';const r=await api('query',{db:A.db||'',sql},'POST');if(r.error){c.innerHTML=`<div class="qri err">❌ ${esc(r.error)}</div>`;return;}A.qrs=[];let h='';
    r.results.forEach((res,ri)=>{if(res.type==='resultset'){const cols=res.rows.length?Object.keys(res.rows[0]):[];const tbl=extractTable(res.sql);A.qrs[ri]={rows:res.rows,cols,selected:new Set(),tableName:tbl};h+=`<div class="qri">✅ ${esc(res.sql.substring(0,100))} | <span class="rc">${res.rowCount}</span> | <span class="dur">${res.duration}ms</span></div>`;if(res.rows.length){h+=`<div class="gc" style="max-height:400px;overflow:auto"><table class="dg" id="qrt-${ri}" style="table-layout:auto;width:100%"><thead><tr><th class="col-cb"><input type="checkbox" onclick="toggleQSelAll(${ri},this.checked)"></th>`;cols.forEach(c2=>h+=`<th>${esc(c2)}</th>`);h+='</tr></thead><tbody>';res.rows.forEach((row,rowI)=>{h+=`<tr data-qi="${rowI}" data-ri="${ri}" onclick="clickQRow(event,${ri},${rowI})" oncontextmenu="showQCtx(event,${ri},${rowI})"><td class="col-cb"><input type="checkbox" onclick="event.stopPropagation();toggleQSel(${ri},${rowI},this.checked)"></td>`;cols.forEach(c2=>h+=row[c2]===null?'<td class="nv">NULL</td>':`<td>${esc(row[c2])}</td>`);h+='</tr>';});h+='</tbody></table></div>';}}else{A.qrs[ri]={rows:[],cols:[],selected:new Set(),tableName:''};h+=`<div class="qri">✅ ${esc(res.sql.substring(0,100))} | <span class="rc">${res.affected} aff.</span> | <span class="dur">${res.duration}ms</span></div>`;}});
    c.innerHTML=h;if(sql.toUpperCase().match(/CREATE|DROP|ALTER|RENAME/))refreshTree();}

function clickQRow(ev,ri,rowI){if(ev.target.type==='checkbox')return;const set=A.qrs[ri]?.selected;if(!set)return;if(ev.ctrlKey||ev.metaKey){set.has(rowI)?set.delete(rowI):set.add(rowI);}else if(ev.shiftKey&&A._qlr!==undefined){const s=Math.min(A._qlr,rowI),e=Math.max(A._qlr,rowI);for(let r=s;r<=e;r++)set.add(r);}else{set.clear();set.add(rowI);}A._qlr=rowI;hlQSel(ri);}
function toggleQSel(ri,rowI,ch){if(!A.qrs[ri])return;ch?A.qrs[ri].selected.add(rowI):A.qrs[ri].selected.delete(rowI);hlQSel(ri);}
function toggleQSelAll(ri,ch){if(!A.qrs[ri])return;if(ch){A.qrs[ri].rows.forEach((_,i)=>A.qrs[ri].selected.add(i));}else{A.qrs[ri].selected.clear();}hlQSel(ri);}
function hlQSel(ri){const all=A.qrs[ri]?.rows?.length||0;let selC=0;document.querySelectorAll(`#qrt-${ri} tbody tr`).forEach(tr=>{const i=parseInt(tr.dataset.qi);tr.classList.remove('sel','msel');if(A.qrs[ri].selected.has(i)){tr.classList.add(A.qrs[ri].selected.size>1?'msel':'sel');selC++;}const cb=tr.querySelector('input[type=checkbox]');if(cb)cb.checked=A.qrs[ri].selected.has(i);});const hcb=document.querySelector(`#qrt-${ri} thead input[type=checkbox]`);if(hcb){hcb.checked=selC===all&&all>0;hcb.indeterminate=selC>0&&selC<all;}}
function getQSel(ri){if(!A.qrs[ri])return[];return[...A.qrs[ri].selected].map(i=>A.qrs[ri].rows[i]).filter(Boolean);}
function showQCtx(ev,ri,rowI){ev.preventDefault();ev.stopPropagation();if(!A.qrs[ri])return;if(!A.qrs[ri].selected.has(rowI)){if(!ev.ctrlKey&&!ev.metaKey&&!ev.shiftKey)A.qrs[ri].selected.clear();A.qrs[ri].selected.add(rowI);A._qlr=rowI;hlQSel(ri);}hideCtx();const n=A.qrs[ri].selected.size,allN=A.qrs[ri].rows.length,m=mkCtx(ev);
    m.innerHTML=`<div class="ci hdr">📋 ${n} sel.</div><div class="ci" onclick="qSI(${ri});hideCtx()">📝 Sel. → INSERT</div><div class="ci" onclick="qSU(${ri});hideCtx()">📝 Sel. → UPDATE</div><div class="ci" onclick="qSD(${ri});hideCtx()">📝 Sel. → DELETE</div><div class="ci" onclick="qSC(${ri});hideCtx()">📊 Sel. → CSV</div><div class="csp"></div><div class="ci hdr">📦 Tutte (${allN})</div><div class="ci" onclick="qAC(${ri});hideCtx()">📊 Tutte → CSV</div><div class="ci" onclick="qAI(${ri});hideCtx()">📝 Tutte → INSERT</div><div class="ci" onclick="qAU(${ri});hideCtx()">📝 Tutte → UPDATE</div><div class="ci" onclick="qAD(${ri});hideCtx()">📝 Tutte → DELETE</div>`;}
function qSI(ri){const r=getQSel(ri),c=A.qrs[ri].cols,t=A.qrs[ri].tableName;if(!r.length)return;copyClip(r.map(x=>genInsert(t,x,c)).join('\n'),`${r.length} INSERT`);}
function qSU(ri){const r=getQSel(ri),c=A.qrs[ri].cols,t=A.qrs[ri].tableName;if(!r.length)return;copyClip(r.map(x=>genUpdate(t,x,c)).join('\n'),`${r.length} UPDATE`);}
function qSD(ri){const r=getQSel(ri),c=A.qrs[ri].cols,t=A.qrs[ri].tableName;if(!r.length)return;copyClip(r.map(x=>genDelete(t,x,c)).join('\n'),`${r.length} DELETE`);}
function qSC(ri){const r=getQSel(ri),c=A.qrs[ri].cols;if(!r.length||!conf(`CSV ${r.length} righe?`))return;downloadText(rowsToCsv(r,c),'query_sel.csv','text/csv');}
function qAC(ri){const r=A.qrs[ri]?.rows||[],c=A.qrs[ri]?.cols||[];if(!r.length||!conf(`CSV tutte ${r.length}?`))return;downloadText(rowsToCsv(r,c),'query_all.csv','text/csv');}
function qAI(ri){const r=A.qrs[ri]?.rows||[],c=A.qrs[ri]?.cols||[],t=A.qrs[ri]?.tableName;if(!r.length||!conf('INSERT tutte?'))return;downloadText(r.map(x=>genInsert(t,x,c)).join('\n'),'query_insert.sql','application/sql');}
function qAU(ri){const r=A.qrs[ri]?.rows||[],c=A.qrs[ri]?.cols||[],t=A.qrs[ri]?.tableName;if(!r.length||!conf('UPDATE tutte?'))return;downloadText(r.map(x=>genUpdate(t,x,c)).join('\n'),'query_update.sql','application/sql');}
function qAD(ri){const r=A.qrs[ri]?.rows||[],c=A.qrs[ri]?.cols||[],t=A.qrs[ri]?.tableName;if(!r.length||!conf('DELETE tutte?'))return;downloadText(r.map(x=>genDelete(t,x,c)).join('\n'),'query_delete.sql','application/sql');}
function clearQE(){if(!conf('Pulire?'))return;$('qe').value='';$('qr').innerHTML='';}
function formatQuery(){const e=$('qe');let s=e.value;['SELECT','FROM','WHERE','AND','OR','JOIN','LEFT JOIN','RIGHT JOIN','INNER JOIN','ON','GROUP BY','ORDER BY','HAVING','LIMIT','INSERT INTO','VALUES','UPDATE','SET','DELETE FROM','UNION ALL','UNION'].forEach(k=>{s=s.replace(new RegExp('\\b'+k.replace(/ /g,'\\s+')+'\\b','gi'),'\n'+k);});e.value=s.trim();}
document.addEventListener('keydown',e=>{if(e.key==='F9'){e.preventDefault();executeQuery();}});

// ---- Context menus ----
function hideCtx(){document.querySelectorAll('.cm').forEach(m=>m.remove());}
document.addEventListener('click',hideCtx);
function mkCtx(ev){const m=document.createElement('div');m.className='cm';m.style.left=ev.clientX+'px';m.style.top=ev.clientY+'px';document.body.appendChild(m);requestAnimationFrame(()=>{const r=m.getBoundingClientRect();if(r.right>window.innerWidth)m.style.left=(window.innerWidth-r.width-5)+'px';if(r.bottom>window.innerHeight)m.style.top=(window.innerHeight-r.height-5)+'px';});return m;}
function showDbCtx(e,db){e.preventDefault();hideCtx();const m=mkCtx(e);

    let items = [];
    items.push(`<div class="ci" onclick="selDb('${db}');hideCtx()">📂 Apri</div>`);

    if(can('CREATE')) {
        items.push(`<div class="ci" onclick="showCreateTableModalForDb('${db}');hideCtx()">📋 Nuova Tabella</div>`);
        if(can('CREATE VIEW')) items.push(`<div class="ci" onclick="showCreateViewModal('${db}');hideCtx()">👁️ Nuova Vista</div>`);
        if(can('CREATE ROUTINE')) items.push(`<div class="ci" onclick="showCreateRoutineModal('${db}');hideCtx()">⚙️ Nuova Procedura</div>`);
    }

    items.push(`<div class="csp"></div>`);
    items.push(`<div class="ci" onclick="showExportDbModalForDb('${db}');hideCtx()">📦 Export</div>`);

    if(can('DROP')) {
        items.push(`<div class="csp"></div>`);
        items.push(`<div class="ci dng" onclick="dropDb('${db}');hideCtx()">🗑️ Elimina</div>`);
    }

    m.innerHTML = items.join('');
}
function showTblCtx(e,db,tbl,type){e.preventDefault();e.stopPropagation();hideCtx();const s=tbl.replace(/'/g,"\\'"),m=mkCtx(e);

    let items = [];
    items.push(`<div class="ci" onclick="selTbl('${db}','${s}','${type}');hideCtx()">📊 Apri</div>`);

    if(type === 'VIEW' && can('ALTER')) {
        items.push(`<div class="ci" onclick="showEditViewModal('${db}','${s}');hideCtx()">✏️ Modifica Vista</div>`);
    }

    if(can('ALTER') && type !== 'VIEW') items.push(`<div class="ci" onclick="showRenameModal('${db}','${s}');hideCtx()">✏️ Rinomina</div>`);

    items.push(`<div class="csp"></div>`);
    items.push(`<div class="ci" onclick="confirmExpDirect('${db}','${s}','sql');hideCtx()">💾 SQL</div>`);
    items.push(`<div class="ci" onclick="confirmExpDirect('${db}','${s}','csv');hideCtx()">📊 CSV</div>`);

    if(can('DROP')) {
        items.push(`<div class="csp"></div>`);
        if(type !== 'VIEW') items.push(`<div class="ci dng" onclick="truncTbl('${db}','${s}');hideCtx()">⚠️ Svuota</div>`);
        items.push(`<div class="ci dng" onclick="dropTbl('${db}','${s}');hideCtx()">🗑️ Elimina</div>`);
    }

    m.innerHTML = items.join('');
}

// ---- Structure etc ----
async function loadStructure(){if(!A.db||!A.tbl)return;const c=$('sc');c.innerHTML='<div class="ld"><div class="sp"></div></div>';const d=await api('columns',{db:A.db,table:A.tbl});if(d.error){c.innerHTML=`<div class="ld" style="color:var(--danger)">${d.error}</div>`;return;}let h='<table class="sg"><thead><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th><th></th></tr></thead><tbody>';d.columns.forEach(c2=>{h+=`<tr><td><strong>${esc(c2.Field)}</strong></td><td style="color:var(--accent)">${esc(c2.Type)}</td><td style="color:var(--warning)">${c2.Null==='YES'?'YES':'NO'}</td><td style="color:var(--danger);font-weight:bold">${esc(c2.Key||'-')}</td><td style="color:var(--text-muted)">${c2.Default===null?'<span class="nv">NULL</span>':esc(c2.Default)}</td><td style="color:var(--success)">${esc(c2.Extra||'-')}</td><td>${can('ALTER')?`<button class="tb" style="padding:2px 6px;font-size:11px" onclick="dropCol('${c2.Field.replace(/'/g,"\\'")}')">🗑️</button>`:''}</td></tr>`;});c.innerHTML=h+'</tbody></table>';}
async function dropCol(col){if(!conf(`Eliminare "${col}"?`))return;const r=await api('drop_column',{db:A.db,table:A.tbl,column:col},'POST');if(r.error)return toast(r.error,'error');toast('OK','success');loadStructure();}
async function loadIndexes(){if(!A.db||!A.tbl)return;const c=$('ic2');c.innerHTML='<div class="ld"><div class="sp"></div></div>';const d=await api('indexes',{db:A.db,table:A.tbl});if(!d.indexes?.length){c.innerHTML='<div class="ld" style="color:var(--text-muted)">Nessun indice</div>';return;}let h='<table class="sg"><thead><tr><th>Nome</th><th>Colonna</th><th>Univoco</th><th>Tipo</th></tr></thead><tbody>';d.indexes.forEach(i=>{h+=`<tr><td><strong>${esc(i.Key_name)}</strong></td><td style="color:var(--accent)">${esc(i.Column_name)}</td><td>${i.Non_unique==0?'Sì':'No'}</td><td>${esc(i.Index_type)}</td></tr>`;});c.innerHTML=h+'</tbody></table>';}
async function loadFK(){if(!A.db||!A.tbl)return;const c=$('fkc');c.innerHTML='<div class="ld"><div class="sp"></div></div>';const d=await api('foreign_keys',{db:A.db,table:A.tbl});if(!d.foreign_keys?.length){c.innerHTML='<div class="ld" style="color:var(--text-muted)">Nessuna FK</div>';return;}let h='<table class="sg"><thead><tr><th>Constraint</th><th>Colonna</th><th>Ref</th><th>Col Ref</th></tr></thead><tbody>';d.foreign_keys.forEach(f=>{h+=`<tr><td><strong>${esc(f.CONSTRAINT_NAME)}</strong></td><td style="color:var(--accent)">${esc(f.COLUMN_NAME)}</td><td>${esc(f.REFERENCED_TABLE_NAME)}</td><td>${esc(f.REFERENCED_COLUMN_NAME)}</td></tr>`;});c.innerHTML=h+'</tbody></table>';}
async function loadDDL(){if(!A.db||!A.tbl)return;$('ddl').textContent='...';const d=await api('show_create',{db:A.db,table:A.tbl});$('ddl').textContent=d.sql||d.error||'-';}
async function loadInfo(){if(!A.db)return;const c=$('ifc');c.innerHTML='<div class="ld"><div class="sp"></div></div>';const d=await api('dbinfo',{db:A.db});if(d.error){c.innerHTML=d.error;return;}let h=`<div class="is"><h3>📊 ${esc(A.db)}</h3><div class="ig"><div class="l">Tabelle</div><div class="v">${d.tableCount}</div><div class="l">Righe</div><div class="v">${fmtN(d.totalRows)}</div><div class="l">Dim.</div><div class="v">${fmtB(d.totalSize)}</div></div></div><div class="is"><h3>Tabelle</h3><table class="sg"><thead><tr><th>Nome</th><th>Engine</th><th>Righe</th><th>Dim.</th></tr></thead><tbody>`;d.tables.forEach(t=>{h+=`<tr><td>${esc(t.Name)}</td><td>${esc(t.Engine||'-')}</td><td style="text-align:right">${fmtN(t.Rows||0)}</td><td style="text-align:right">${fmtB((t.Data_length||0)+(t.Index_length||0))}</td></tr>`;});c.innerHTML=h+'</tbody></table></div>';}

// ---- Modals ----
function showCreateDbModal(){if(!can('CREATE'))return;let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:450px"><div class="mh">🗄️ Crea DB<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb"><div class="fg"><label>Nome</label><input type="text" id="ndn"></div><div class="fg"><label>Collation</label><select id="ndc"><option>utf8mb4_general_ci</option><option>utf8mb4_unicode_ci</option></select></div></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">Annulla</button><button class="btn bp" onclick="doCreateDb()">Crea</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
async function doCreateDb(){const n=$('ndn').value.trim();if(!n||!conf(`Creare "${n}"?`))return;const r=await api('create_database',{db_name:n,collation:$('ndc').value},'POST');if(r.error)return toast(r.error,'error');toast('Creato','success');document.querySelector('.mo')?.remove();refreshTree();}
async function dropDb(db){if(!conf(`Eliminare "${db}"?`))return;await api('drop_database',{db_name:db},'POST');toast('OK','success');if(A.db===db){A.db=null;A.tbl=null;$('ws').style.display='flex';$('dp').style.display='none';}refreshTree();}
function showCreateTableModal(){if(!A.db)return;showCreateTableModalForDb(A.db);}
function showCreateTableModalForDb(db){if(!can('CREATE'))return;let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:800px"><div class="mh">📋 in ${esc(db)}<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb"><div class="fg"><label>Nome</label><input type="text" id="ntn"></div><div style="display:flex;gap:12px"><div class="fg" style="flex:1"><label>Engine</label><select id="nte"><option>InnoDB</option><option>MyISAM</option></select></div><div class="fg" style="flex:1"><label>Collation</label><select id="ntc"><option>utf8mb4_general_ci</option></select></div></div><h4 style="margin:12px 0 8px;color:var(--accent)">Colonne</h4><div id="ctc"><div class="cr" style="display:flex;gap:6px;margin-bottom:6px;align-items:end"><div class="fg" style="flex:2;margin:0"><label>Nome</label><input type="text" data-f="name" value="id"></div><div class="fg" style="flex:1;margin:0"><label>Tipo</label><select data-f="type"><option selected>INT</option><option>BIGINT</option><option>VARCHAR</option><option>TEXT</option><option>DATE</option><option>DATETIME</option><option>TIMESTAMP</option><option>FLOAT</option><option>DOUBLE</option><option>DECIMAL</option><option>BOOLEAN</option><option>JSON</option></select></div><div class="fg" style="flex:.7;margin:0"><label>Len</label><input type="text" data-f="length" value="11"></div><div class="fg" style="margin:0"><label>PK</label><input type="checkbox" data-f="primary" checked></div><div class="fg" style="margin:0"><label>NN</label><input type="checkbox" data-f="notnull" checked></div><div class="fg" style="margin:0"><label>AI</label><input type="checkbox" data-f="auto_increment" checked></div><button class="btn bd" style="padding:4px 8px" onclick="this.closest('.cr').remove()">✕</button></div></div><button class="btn bs" style="margin-top:8px" onclick="addCR()">+ Col</button></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">Annulla</button><button class="btn bp" onclick="doCreateTbl('${db}')">Crea</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
function addCR(){const c=$('ctc'),r=document.createElement('div');r.className='cr';r.style.cssText='display:flex;gap:6px;margin-bottom:6px;align-items:end';r.innerHTML=`<div class="fg" style="flex:2;margin:0"><input type="text" data-f="name"></div><div class="fg" style="flex:1;margin:0"><select data-f="type"><option>INT</option><option selected>VARCHAR</option><option>TEXT</option><option>DATE</option><option>DATETIME</option><option>FLOAT</option><option>DECIMAL</option><option>BOOLEAN</option><option>JSON</option></select></div><div class="fg" style="flex:.7;margin:0"><input type="text" data-f="length" value="255"></div><div class="fg" style="margin:0"><input type="checkbox" data-f="primary"></div><div class="fg" style="margin:0"><input type="checkbox" data-f="notnull"></div><div class="fg" style="margin:0"><input type="checkbox" data-f="auto_increment"></div><button class="btn bd" style="padding:4px 8px" onclick="this.closest('.cr').remove()">✕</button>`;c.appendChild(r);}
async function doCreateTbl(db){const n=$('ntn').value.trim();if(!n)return;const cols=[];document.querySelectorAll('#ctc .cr').forEach(r=>{const c={};r.querySelectorAll('[data-f]').forEach(e=>{c[e.dataset.f]=e.type==='checkbox'?e.checked:e.value;});if(c.name)cols.push(c);});if(!cols.length||!conf(`Creare "${n}"?`))return;const r=await api('create_table',{db,table_name:n,columns:JSON.stringify(cols),engine:$('nte').value,collation:$('ntc').value},'POST');if(r.error)return toast(r.error,'error');toast('OK','success');document.querySelector('.mo')?.remove();A.expanded.add(db);refreshTree();selTbl(db,n,'BASE TABLE');}
async function dropTbl(db,t){if(!conf(`Eliminare "${t}"?`))return;await api('drop_table',{db,table:t},'POST');toast('OK','success');if(A.tbl===t){A.tbl=null;$('ws').style.display='flex';$('dp').style.display='none';}refreshTree();}
async function truncTbl(db,t){if(!conf(`Svuotare "${t}"?`))return;await api('truncate_table',{db,table:t},'POST');toast('OK','success');if(A.tbl===t)refreshData();}
function showRenameModal(db,t){let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:400px"><div class="mh">✏️ Rinomina<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb"><div class="fg"><label>Nuovo nome</label><input type="text" id="rtn" value="${t}"></div></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">Annulla</button><button class="btn bp" onclick="doRename('${db}','${t}')">OK</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
async function doRename(db,old){const n=$('rtn').value.trim();if(!n||n===old){document.querySelector('.mo')?.remove();return;}if(!conf(`Rinominare?`))return;await api('rename_table',{db,old_name:old,new_name:n},'POST');toast('OK','success');document.querySelector('.mo')?.remove();refreshTree();selTbl(db,n,'BASE TABLE');}
function showAddColumnModal(){if(!can('ALTER')||!A.db||!A.tbl)return;let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:500px"><div class="mh">➕ Colonna<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb"><div class="fg"><label>Nome</label><input type="text" id="acn"></div><div class="fg"><label>Tipo</label><select id="act"><option>VARCHAR</option><option>INT</option><option>BIGINT</option><option>TEXT</option><option>DATE</option><option>DATETIME</option><option>FLOAT</option><option>DECIMAL</option><option>BOOLEAN</option><option>JSON</option></select></div><div class="fg"><label>Len</label><input type="text" id="acl" value="255"></div><div class="fg"><label><input type="checkbox" id="acnn"> NOT NULL</label></div><div class="fg"><label>Default</label><input type="text" id="acd"></div></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">Annulla</button><button class="btn bp" onclick="doAddCol()">OK</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
async function doAddCol(){const c={name:$('acn').value.trim(),type:$('act').value,length:$('acl').value,notnull:$('acnn').checked,default:$('acd').value};if(!c.name||!conf(`Aggiungere "${c.name}"?`))return;const r=await api('add_column',{db:A.db,table:A.tbl,column:JSON.stringify(c)},'POST');if(r.error)return toast(r.error,'error');toast('OK','success');document.querySelector('.mo')?.remove();loadStructure();}

// ---- Export ----
function showExportDbModal(){if(!A.db)return;showExportDbModalForDb(A.db);}
async function showExportDbModalForDb(db){if(!A.dbTables[db]){const d=await api('tables',{db});A.dbTables[db]=d.tables||[];}const tbls=A.dbTables[db];let h=`<div class="mo" id="exm" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:600px"><div class="mh">📦 ${esc(db)}<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb"><div class="is"><h3>Contenuto</h3><div class="erg"><label class="eri"><input type="radio" name="exc" value="both" checked><div>📋 Struttura + Dati</div></label><label class="eri"><input type="radio" name="exc" value="struct"><div>🏗️ Solo Struttura</div></label></div></div><div class="is"><h3>Tabelle</h3><div class="erg" style="margin-bottom:12px"><label class="eri"><input type="radio" name="ext" value="all" checked onchange="$('ets').style.display='none'"><div>📦 Tutte (${tbls.length})</div></label><label class="eri"><input type="radio" name="ext" value="sel" onchange="$('ets').style.display='block'"><div>☑️ Seleziona</div></label></div><div id="ets" style="display:none"><div style="margin-bottom:8px;display:flex;gap:8px"><button class="btn bs" style="font-size:11px;padding:3px 10px" onclick="document.querySelectorAll('.etc').forEach(c=>c.checked=true)">✅</button><button class="btn bs" style="font-size:11px;padding:3px 10px" onclick="document.querySelectorAll('.etc').forEach(c=>c.checked=false)">❌</button></div><div class="etl">`;tbls.forEach(t=>{h+=`<label class="eti"><input type="checkbox" class="etc" value="${esc(t.name)}" checked> ${esc(t.name)}</label>`;});h+=`</div></div></div></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">Annulla</button><button class="btn bp" onclick="doExpDb('${db}')">📥</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
function doExpDb(db){const inc=document.querySelector('input[name="exc"]:checked')?.value==='both'?'1':'0';let tbls='';if(document.querySelector('input[name="ext"]:checked')?.value==='sel'){const ch=[];document.querySelectorAll('.etc:checked').forEach(c=>ch.push(c.value));if(!ch.length)return toast('Sel.','inf');tbls=ch.join(',');}if(!conf('Esportare?'))return;let u=`?action=export_database&conn=${A.connId}&db=${encodeURIComponent(db)}&include_data=${inc}`;if(tbls)u+=`&tables=${encodeURIComponent(tbls)}`;window.open(u,'_blank');$('exm')?.remove();}
function confirmExportTable(f){if(!A.db||!A.tbl||!conf('Esportare?'))return;window.open(`?action=export&conn=${A.connId}&db=${encodeURIComponent(A.db)}&table=${encodeURIComponent(A.tbl)}&format=${f}`,'_blank');}
function confirmExpDirect(db,t,f){if(!conf('Esportare?'))return;window.open(`?action=export&conn=${A.connId}&db=${encodeURIComponent(db)}&table=${encodeURIComponent(t)}&format=${f}`,'_blank');}

// ---- Server ----
async function showServerInfo(){const d=await api('serverinfo');if(d.error)return toast(d.error,'error');let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:700px"><div class="mh">🖥️ Server<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb"><div class="is"><h3>Variabili</h3><div class="ig">`;Object.entries(d.variables).forEach(([k,v])=>{h+=`<div class="l">${esc(k)}</div><div class="v">${esc(v)}</div>`;});h+=`</div></div></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">OK</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
async function showProcessList(){if(!can('PROCESS'))return;const d=await api('processlist');if(d.error)return toast(d.error,'error');let h=`<div class="mo" onclick="if(event.target===this)this.remove()"><div class="md" style="max-width:900px"><div class="mh">⚡<button class="cb" onclick="closeModal(this)">✕</button></div><div class="mb" style="padding:0"><table class="sg"><thead><tr><th>ID</th><th>User</th><th>Cmd</th><th>Time</th><th>Info</th><th></th></tr></thead><tbody>`;d.processes.forEach(p=>{h+=`<tr><td>${p.Id}</td><td>${esc(p.User)}</td><td>${esc(p.Command)}</td><td>${p.Time}s</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;font-size:11px">${esc(p.Info||'-')}</td><td><button class="tb" style="padding:2px 6px;font-size:10px" onclick="killProc(${p.Id})">Kill</button></td></tr>`;});h+=`</tbody></table></div><div class="mf"><button class="btn bs" onclick="closeModal(this)">OK</button></div></div></div>`;document.body.insertAdjacentHTML('beforeend',h);}
async function killProc(id){if(!conf(`Kill #${id}?`))return;await api('kill_process',{id},'POST');toast('OK','success');document.querySelector('.mo')?.remove();showProcessList();}

// ---- Misc ----
async function refreshTree(){await loadDatabases();}
(function(){const h=$('resize-handle'),s=$('sidebar');let sx,sw;h.onmousedown=e=>{sx=e.clientX;sw=s.offsetWidth;h.classList.add('active');document.onmousemove=e=>{const w=sw+(e.clientX-sx);if(w>=120&&w<=600)s.style.width=w+'px';};document.onmouseup=()=>{h.classList.remove('active');document.onmousemove=document.onmouseup=null;};e.preventDefault();};})();
(function(){const h=$('qrh'),ec=$('qec');let sy,sh;h.onmousedown=e=>{sy=e.clientY;sh=ec.offsetHeight;document.onmousemove=e=>{const nh=sh+(e.clientY-sy);if(nh>=60&&nh<=600)ec.style.flex=`0 0 ${nh}px`;};document.onmouseup=()=>{document.onmousemove=document.onmouseup=null;};e.preventDefault();};})();
$('tree-filter').oninput=function(){const f=this.value.toLowerCase();document.querySelectorAll('#tree-container .ti').forEach(i=>{const l=i.querySelector('.til')?.textContent?.toLowerCase()||'';const m=!f||l.includes(f);i.style.display=m?'':'none';if(m&&i.classList.contains('tbi')){const db=i.dataset.db;document.querySelector(`.ti.db[data-db="${CSS.escape(db)}"]`).style.display='';document.getElementById(`tc-${db}`)?.classList.add('open');}});};

async function init(){
    initTheme();
    await loadConnections();
    await loadPrivileges();
    await loadDatabases();
    try{const i=await api('serverinfo');if(i.variables)$('sv').textContent=i.variables.version||'';}catch(e){}
    setInterval(()=>{$('st').textContent=new Date().toLocaleTimeString('it-IT');},1000);
}
init();
</script>
</body>
</html>
 
