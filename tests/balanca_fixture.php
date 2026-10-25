<?php
// Fixture exclusiva dos testes locais; nunca publicar em public/.
require __DIR__ . '/../config/database.php';
$db=Database::connect();
if (($argv[1]??'')==='role') {
 $db->prepare("UPDATE usuarios SET perfil='usuario' WHERE id=?")->execute([$argv[2]]); exit;
}
if (($argv[1]??'')==='totals') {
 $q=$db->prepare('SELECT data, quantidade_desperdicada_kg FROM producao_refeicoes WHERE empresa_id=? ORDER BY data');
 $q->execute([$argv[2]]); echo json_encode($q->fetchAll()); exit;
}
if (($argv[1]??'')==='delete') {
 $db->prepare('DELETE FROM usuarios WHERE id=? AND empresa_id=?')->execute([$argv[2], $argv[3]]);
 $db->prepare('DELETE FROM empresas WHERE id=?')->execute([$argv[3]]); exit;
}
$values=[];
foreach ($db->query('DESCRIBE empresas') as $column) {
 if ($column['Field']==='id' || $column['Null']==='YES' || $column['Default']!==null) continue;
 $values[$column['Field']]=str_contains($column['Type'],'int')?1:'Smoke';
}
$values['cnpj']=substr(bin2hex(random_bytes(7)),0,14);
$values['estado']='SP'; $values['cep']='00000000'; $values['criado_em']=date('Y-m-d H:i:s');
$db->prepare('INSERT INTO empresas (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')')->execute(array_values($values));
$company=$db->lastInsertId(); $password=bin2hex(random_bytes(16)); $email='smoke-'.bin2hex(random_bytes(5)).'@example.invalid';
$db->prepare("INSERT INTO usuarios (nome,email,senha,perfil,empresa_id,criado_em) VALUES (?,?,?,'admin',?,NOW())")->execute(['Smoke',$email,password_hash($password,PASSWORD_DEFAULT),$company]);
$userId = $db->lastInsertId();
require __DIR__ . '/../app/models/Dispositivo.php';
$devices = new Dispositivo();
$balance = $devices->criar((int) $company, 'Balança teste', 'balanca', null);
$rfid = $devices->criar((int) $company, 'RFID teste', 'rfid', null);
echo json_encode(['balance'=>$balance, 'rfid'=>$rfid, 'uid'=>$userId,'company'=>$company,'email'=>$email,'senha'=>$password]);
