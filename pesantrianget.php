<?php
/**
 * pesantrianget
 * authored by 9r3i
 * https://github.com/9r3i
 * requires: eva, fdb
 */
class pesantrianget{
  const version='1.0.0';
  protected $fdb;
  public function __construct(){
    date_default_timezone_set('Asia/Jakarta');
    $this->fdb=new fdb('pesantrian','9r3i');
  }
  /* from fdb read */
  public function read(string $file){
    return $this->fdb->read($file);
  }
}
