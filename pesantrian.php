<?php
/**
 * pesantrian
 * ~ only run for eva
 * ~ api handler for pesantrian
 * authored by 9r3i
 * https://github.com/9r3i
 * started at january 14th 2024
 * requires: eva, ldb, fdb
 */
class pesantrian{
  private $db;
  private $fdb;
  private $queue;
  private $session;
  public function __construct(){
    date_default_timezone_set('Asia/Jakarta');
    $dbname='atibs';
    $this->db=new ldb('localhost','aisyah','master',$dbname);
    $this->fdb=new fdb('pesantrian','9r3i');
    
    $this->queue=EVA_CLI_DIR.'queue.log';
    $this->session=($_SERVER['REMOTE_ADDR']??'0.0.0.0').'-'.uniqid();
    
    $this->checkQueue();
    @file_put_contents($this->queue,$this->session);
    $this->checkSession();
    
    /* backup job */
    if(defined('MSERVER_DB')){
      $dbfile=MSERVER_DB.$dbname.'.ldb';
      $backupDir=implode('/',[
        MSERVER_DB,
        'backup',
        $dbname,
        date('Y-m-d'),
        date('H'),
        '',
      ]);
      $backupFile=$backupDir.$dbname.'.'.date('ymdHi').'.ldb';
      if(!is_dir($backupDir)){
        @mkdir($backupDir,0755,true);
      }
      if(is_file($dbfile)&&!is_file($backupFile)){
        @copy($dbfile,$backupFile);
      }
    }

  }
  public function __destruct(){
    if(is_file($this->queue)){
      @unlink($this->queue);
    }
  }
  public function register(string $data=''){
    $this->userlog(__METHOD__,'0',$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    $arg=$this->decode($data);
    if(!is_array($arg)
      ||!isset($arg['passcode'],$arg['name'],$arg['type'])){
      return $this->result('error:form');
    }
    $types=['student','parent','employee'];
    if(!in_array($arg['type'],$types)){
      return $this->result('error:type');
    }
    /* from profile */
    if($arg['type']=='employee'){
      $select=$this->db->query('select id,name,position from '.$arg['type'].' where name="'.$arg['name'].'"');
    }elseif($arg['type']=='student'){
      $select=$this->db->query('select id,name,graduated from '.$arg['type'].' where name="'.$arg['name'].'" and graduated=1');
    }else{
      $select=$this->db->query('select id,name from '.$arg['type'].' where name="'.$arg['name'].'"');
    }
    if(!$select||!isset($select[0])){
      return $this->result('error:name');
    }
    $user=$select[0];
    if(!isset($user['position'])){
      $user['position']=$arg['type'];
    }
    /* from user */
    $select=$this->db->query('select * from user where name="'.$arg['name'].'"');
    if($select&&isset($select[0])){
      return $this->result('error:user');
    }
    /* prepare data */
    $ndata=[
      'passcode'=>password_hash($arg['passcode'],1),
      'name'=>trim($arg['name']),
      'type'=>$arg['type'],
      'profile_id'=>$user['id'],
      'privilege'=>$arg['type']=='employee'?4:2,
      'data'=>'{}',
      'scope'=>implode(',',[
        'account',
        $user['position'],
      ]),
      'active'=>1,
    ];
    $query=http_build_query($ndata);
    $insert=$this->db->query('insert into user '.$query);
    return $this->result($insert?'ok':'error:save');
  }
  public function login(string $data=''){
    $this->userlog(__METHOD__,'0',$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    $arg=$this->decode($data);
    if(!is_array($arg)
      ||!isset($arg['password'],$arg['username'])){
      return $this->result('error:form');
    }
    $select=$this->db->query('select * from user where name="'.$arg['username'].'"');
    if(!$select||!isset($select[0])){
      return $this->result('error:user');
    }
    $user=$select[0];
    $verified=password_verify($arg['password'],$user['passcode']);
    $active=$user['active'];
    unset($user['passcode']);
    unset($user['active']);
    unset($user['time']);
    if(!$verified){
      return $this->result('error:pass');
    }
    $user['data']=@json_decode($user['data'],true);
    
    $select=$this->db->query('select * from '.$user['type']
      .' where id='.$user['profile_id']);
    $user['profile']=$select&&isset($select[0])?$select[0]:false;
    return $this->result($active?$user:'error:active');
  }
  public function uload(array $data){
    $this->userlog(__METHOD__,$data['uid']??'0',json_encode($data));
    if(!isset($data['uid'],$data['file'],$data['path'])){
      return $this->result('error:form');
    }$uid=$data['uid'];
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    if(!$this->verify($uid)){
      return $this->result('error:active');
    }
    $root=defined('MSERVER_ROOT')?MSERVER_ROOT:__DIR__.'/';
    $dir=$root.'files/pesantrian/'.$data['path'];
    $path=$data['path'];
    $write=$this->fdb->write($path,$data);
    return $this->result($write?'ok':'error:save');
  }
  public function brow(string $data='',$uid=0){
    $this->userlog(__METHOD__,$uid,$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    if(!$this->verify($uid)){
      return $this->result('error:active');
    }
    $arg=$this->decode($data);
    if(!is_array($arg)||!isset($arg['agent'])){
      return $this->result('error:form');
    }
    /* default user from id */
    $select=$this->db->query('select * from user where id='.$uid);
    if(!$select||!isset($select[0])){
      return $this->result('error:user');
    }
    $user=$select[0];
    unset($user['passcode']);
    unset($user['active']);
    unset($user['time']);
    $udata=json_encode($user);
    $code=$this->bcode(uniqid());
    /* old user -- generate new code */
    $check=$this->db->query('select * from browser where used=0 and uid='.$uid);
    if($check&&isset($check[0],$check[0]['code'])){
      $code=$this->bcode(uniqid());
      $udata=$check[0]['data'];
      $update=$this->db->query('update browser ('.http_build_query([
        'code'=>$code,
        'data'=>$udata,
        'agent'=>$arg['agent'],
      ]).') where uid='.$uid);
      return $this->result($update?'atibs.'.$code:'error:update');
    }
    /* new user code */
    $insert=$this->db->query('insert into browser '.http_build_query([
      'code'=>$code,
      'data'=>$udata,
      'agent'=>$arg['agent'],
      'uid'=>$uid,
    ]));
    return $this->result($insert?'atibs.'.$code:'error:create');
  }
  public function cpass(string $data='',$uid=0){
    $this->userlog(__METHOD__,$uid,$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    if(!$this->verify($uid)){
      return $this->result('error:active');
    }
    $arg=$this->decode($data);
    if(!is_array($arg)
      ||!isset($arg['old'],$arg['npass'])){
      return $this->result('error:form');
    }
    $select=$this->db->query('select * from user where id='.$uid);
    if(!$select||!isset($select[0])){
      return $this->result('error:user');
    }
    $user=$select[0];
    $verified=password_verify($arg['old'],$user['passcode']);
    if(!$verified){
      return $this->result('error:pass');
    }
    $npass=password_hash($arg['npass'],1);
    $update=$this->db->query('update user ('
      .http_build_query([
        'passcode'=>$npass,
      ]).') where id='.$uid);
    return $this->result($update?'ok':'error:save');
  }
  public function query(string $data='',$uid=0){
    $this->userlog(__METHOD__,$uid,$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    if(!$this->verify($uid)){
      return $this->result('error:active');
    }
    $query=$this->decode($data);
    if(!is_string($query)){
      return $this->result(['error'=>'Invalid query.']);
    }
    $result=$this->db->query($query);
    $result=$this->db->error
      ?['error'=>$this->db->error]:$result;
    return $this->result($result);
  }
  public function queries(string $data='',$uid=0){
    $this->userlog(__METHOD__,$uid,$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    if(!$this->verify($uid)){
      return $this->result('error:active');
    }
    $queries=$this->decode($data);
    if(!is_string($queries)){
      return $this->result(['error'=>'Invalid query.']);
    }
    $result=$this->db->queries($queries);
    $result=$this->db->error
      ?['error'=>$this->db->error]:$result;
    return $this->result($result);
  }
  public function trace(string $data='',$uid=0){
    $this->userlog(__METHOD__,$uid,$data);
    if($this->offline()){
      return $this->result('error:offline');
    }
    if($this->maintenance()){
      return $this->result('error:maintenance');
    }
    if(!$this->verify($uid)){
      return $this->result('error:active');
    }
    $file=EVA_CLI_DIR.'pesantrian.log';
    if(!is_file($file)){
      return $this->result('error:file');
    }
    $arg=$this->decode($data);
    if(!isset($arg['start'],$arg['end'])){
      return $this->result('error:argument');
    }
    $size=filesize($file);
    $start=intval($arg['start']);
    $end=min(intval($arg['end']),$size);

    if($start==-1){
      $date=date('ymdHis');
      $new=EVA_CLI_DIR.'pesantrian.'.$date.'.log';
      $rename=@rename($file,$new);
      return $this->result($rename);
    }
    if($start==-2){
      return $this->result($size);
    }
    $o=fopen($file,'rb');
    if(!is_resource($o)){
      return $this->result('error:open');
    }
    fseek($o,$start);
    $result='';
    while(ftell($o)<$end){
      $result.=fread($o,(1024*32));
    }fclose($o);
    return $this->result($result);
  }
  private function checkSession(){
    $get=@file_get_contents($this->queue);
    if($get!==$this->session){
      return $this->checkQueue();
    }return true;
  }
  private function checkQueue(){
    if(is_file($this->queue)){
      usleep(100);
      return $this->checkQueue();
    }return true;
  }
  private function userlog(){
    $ip=$_SERVER['REMOTE_ADDR']??'0.0.0.0';
    $ua=$_SERVER['HTTP_USER_AGENT']??'unknown user-agent';
    $date=date('Y-m-d H:i:s');
    $args=func_get_args();
    $line=implode('|',[$date,$ip,$ua,...$args]);
    $file=EVA_CLI_DIR.'pesantrian.log';
    $o=@fopen($file,'ab');
    fwrite($o,$line."\n");
    fclose($o);
  }
  private function maintenance(){
    $file=EVA_CLI_DIR.'maintenance.log';
    if(is_file($file)){
      $get=file_get_contents($file);
      if(intval($get)===1){
        return true;
      }
    }return false;
  }
  private function offline(){
    $file=EVA_CLI_DIR.'offline.log';
    if(is_file($file)){
      $get=file_get_contents($file);
      if(intval($get)===1){
        return true;
      }
    }return false;
  }
  private function bcode(string $salt=''){
    return substr(
      strtolower(
        preg_replace(
          '/[^a-z0-9]+/i',
          '',
          base64_encode(
            md5(
              $salt,
              true
            )
          )
        )
      ),
      0,
      8
    );
  }
  private function verify($uid=0){
    $select=$this->db->query('select id,active from user where id='.$uid);
    $result=false;
    if($select&&isset($select[0])
      &&intval($select[0]['active'])===1){
      $result=true;
    }return $result;
  }
  private function result($data=false){
    $out=$this->encode($data);
    header('Content-Type: text/plain');
    header('Content-Length: '.strlen($out));
    exit($out);
  }
  private function decode($data=''){
    $json=@base64_decode($data);
    return @json_decode($json,true);
  }
  private function encode($data=false){
    $json=@json_encode($data);
    return @base64_encode($json);
  }
}
